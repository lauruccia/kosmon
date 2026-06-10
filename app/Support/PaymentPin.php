<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

class PaymentPin
{
    private const MAX_ATTEMPTS = 5;
    private const DECAY_SECONDS = 900;

    public static function verify(User $user, ?string $pin): array
    {
        $key = self::rateLimitKey($user);

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            $seconds = RateLimiter::availableIn($key);
            return [false, 'Troppi tentativi PIN errati. Riprova tra ' . ceil($seconds / 60) . ' minuti.'];
        }

        if (! self::isValidPlainPin($pin)) {
            RateLimiter::hit($key, self::DECAY_SECONDS);
            return [false, 'PIN di pagamento errato. Riprova.'];
        }

        $stored = (string) $user->payment_pin_hash;
        $valid = Hash::check($pin, $stored);

        if (! $valid && self::looksLikeLegacySha256($stored)) {
            $valid = hash_equals($stored, hash('sha256', $pin));

            if ($valid) {
                $user->forceFill(['payment_pin_hash' => Hash::make($pin)])->save();
            }
        }

        if ($valid) {
            RateLimiter::clear($key);
            return [true, null];
        }

        RateLimiter::hit($key, self::DECAY_SECONDS);

        return [false, 'PIN di pagamento errato. Riprova.'];
    }

    public static function hash(string $pin): string
    {
        if (! self::isValidPlainPin($pin)) {
            throw new \InvalidArgumentException('Il PIN deve contenere esattamente 6 cifre.');
        }

        return Hash::make($pin);
    }

    public static function isValidPlainPin(?string $pin): bool
    {
        return is_string($pin) && preg_match('/^\d{6}$/', $pin) === 1;
    }

    private static function looksLikeLegacySha256(string $value): bool
    {
        return preg_match('/^[0-9a-f]{64}$/', $value) === 1;
    }

    private static function rateLimitKey(User $user): string
    {
        return 'payment-pin:' . $user->getAuthIdentifier();
    }
}
