<?php

namespace App\Support;

/**
 * RFC 6238 TOTP implementation -- no external dependencies required.
 * Compatible with Google Authenticator, Authy, and any TOTP app.
 */
class Totp
{
    private const BASE32_CHARS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * Generate a cryptographically random base32 secret.
     * 16 bytes -> 26 base32 chars (128 bits of entropy).
     */
    public static function generateSecret(int $bytes = 16): string
    {
        return static::base32Encode(random_bytes($bytes));
    }

    /**
     * Build the otpauth:// URI to encode in the QR code.
     */
    public static function getUri(string $secret, string $accountName, string $issuer): string
    {
        $params = http_build_query([
            'secret'    => $secret,
            'issuer'    => $issuer,
            'algorithm' => 'SHA1',
            'digits'    => 6,
            'period'    => 30,
        ]);

        return 'otpauth://totp/' . rawurlencode($issuer . ':' . $accountName) . '?' . $params;
    }

    /**
     * Verify a 6-digit OTP code against the secret.
     * $window allows +/- N time steps (30s each) to account for clock drift.
     */
    public static function verify(string $secret, string $code, int $window = 1): bool
    {
        $code = preg_replace('/\s+/', '', $code);

        if (! ctype_digit($code) || strlen($code) !== 6) {
            return false;
        }

        $key       = static::base32Decode($secret);
        $timestamp = (int) floor(time() / 30);

        for ($i = -$window; $i <= $window; $i++) {
            if (static::hotp($key, $timestamp + $i) === $code) {
                return true;
            }
        }

        return false;
    }

    /**
     * Generate N one-time recovery codes in 'xxxxx-xxxxx' format.
     * Returns plaintext codes -- the caller must bcrypt-hash them before storing.
     */
    public static function generateRecoveryCodes(int $count = 8): array
    {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $hex     = bin2hex(random_bytes(5));
            $codes[] = substr($hex, 0, 5) . '-' . substr($hex, 5, 5);
        }
        return $codes;
    }

    /**
     * Return the current (or offset) valid OTP code for a secret.
     * Useful in tests to generate a code that will pass verify().
     */
    public static function currentCode(string $secret, int $windowOffset = 0): string
    {
        $key       = static::base32Decode($secret);
        $timestamp = (int) floor(time() / 30) + $windowOffset;
        return static::hotp($key, $timestamp);
    }

    // Private HOTP / base32 helpers

    private static function hotp(string $key, int $counter): string
    {
        // RFC 4226: HOTP = Truncate(HMAC-SHA-1(K, C))
        $data = pack('J', $counter);
        $hash = hash_hmac('sha1', $data, $key, true);

        $offset = ord($hash[19]) & 0x0F;
        $code   = (
            ((ord($hash[$offset])     & 0x7F) << 24) |
            ((ord($hash[$offset + 1]) & 0xFF) << 16) |
            ((ord($hash[$offset + 2]) & 0xFF) << 8) |
            ((ord($hash[$offset + 3]) & 0xFF))
        ) % 1000000;

        return str_pad((string) $code, 6, '0', STR_PAD_LEFT);
    }

    private static function base32Encode(string $data): string
    {
        $chars      = static::BASE32_CHARS;
        $output     = '';
        $buffer     = 0;
        $bufferSize = 0;
        $length     = strlen($data);

        for ($i = 0; $i < $length; $i++) {
            $buffer     = ($buffer << 8) | ord($data[$i]);
            $bufferSize += 8;

            while ($bufferSize >= 5) {
                $bufferSize -= 5;
                $output    .= $chars[($buffer >> $bufferSize) & 31];
            }
        }

        if ($bufferSize > 0) {
            $output .= $chars[($buffer << (5 - $bufferSize)) & 31];
        }

        return $output;
    }

    private static function base32Decode(string $data): string
    {
        $chars      = static::BASE32_CHARS;
        $data       = strtoupper(preg_replace('/[^A-Z2-7]/i', '', $data));
        $output     = '';
        $buffer     = 0;
        $bufferSize = 0;
        $length     = strlen($data);

        for ($i = 0; $i < $length; $i++) {
            $pos = strpos($chars, $data[$i]);
            if ($pos === false) {
                continue;
            }

            $buffer     = ($buffer << 5) | $pos;
            $bufferSize += 5;

            if ($bufferSize >= 8) {
                $bufferSize -= 8;
                $output    .= chr(($buffer >> $bufferSize) & 0xFF);
            }
        }

        return $output;
    }
}
