<?php

namespace App\Support;

use Illuminate\Support\Facades\RateLimiter;

/**
 * Blocco temporaneo dei tentativi a vuoto su una credenziale, contato per
 * UTENTE e non per indirizzo IP.
 *
 * E' la stessa forma gia' scritta in casa in PaymentPin (5 tentativi, 15
 * minuti), portata fuori perche' serviva identica al challenge 2FA e alla
 * conferma identita', che fino al 28/08/2026 non avevano nessun freno: si
 * potevano provare codici e password a raffica.
 *
 * Il throttle di rotta limita per IP e da solo non basta: chi prova a
 * indovinare cambia indirizzo, mentre l'account bersaglio resta lo stesso.
 * Le due difese si sommano.
 */
class CredentialAttempts
{
    public const MAX_ATTEMPTS = 5;
    public const DECAY_SECONDS = 900;

    /** Messaggio da mostrare se l'utente e' bloccato, null se puo' ancora provare. */
    public static function lockoutMessage(string $scope, int|string $identifier): ?string
    {
        $key = self::key($scope, $identifier);

        if (! RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            return null;
        }

        $minuti = max(1, (int) ceil(RateLimiter::availableIn($key) / 60));

        return "Troppi tentativi non riusciti. Riprova tra {$minuti} minuti.";
    }

    public static function hit(string $scope, int|string $identifier): void
    {
        RateLimiter::hit(self::key($scope, $identifier), self::DECAY_SECONDS);
    }

    public static function clear(string $scope, int|string $identifier): void
    {
        RateLimiter::clear(self::key($scope, $identifier));
    }

    private static function key(string $scope, int|string $identifier): string
    {
        return $scope . ':' . $identifier;
    }
}
