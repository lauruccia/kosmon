<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Prova che una card NFC è stata DAVVERO avvicinata, adesso, da questo commerciante.
 *
 * Perché esiste (fix A10 del 28/08/2026): la firma HMAC scritta sul chip veniva
 * verificata solo da POST /nfc/card/identify, mentre POST /nfc/card/request —
 * quello che muove i soldi — accettava il solo `card_uuid`. Il controllo viveva
 * quindi soltanto nel browser: bastava saltare il primo passo. E la firma è
 * statica (HMAC di un UUID che non cambia mai), quindi chi l'aveva letta una
 * volta poteva riusarla per sempre.
 *
 * Il tap token lega i due passi lato server: identify lo emette solo dopo aver
 * verificato la firma, request lo pretende. Vive 2 minuti, vale per UNA card e
 * UN utente merchant, e si brucia appena l'incasso va a buon fine.
 *
 * Limite noto, da non confondere: questo ferma il riuso via API, NON la clonazione
 * fisica della card. Un URL statico si ricopia su un altro tag; per impedirlo
 * servono chip che firmino da sé con un contatore (NTAG 424 DNA) — decisione
 * hardware, non di codice.
 *
 * In cache si salva solo lo SHA-256 del token, come per api_tokens e oauth:
 * chi legge la cache non trova niente di spendibile.
 */
class NfcTapToken
{
    /** Quanto vale un tap. Il tempo di digitare l'importo, non di più. */
    public const TTL_SECONDS = 120;

    private const PREFIX = 'nfc_tap:';

    /** Emette un token per questa card e questo merchant. */
    public static function issue(int $cardId, int $merchantUserId): string
    {
        $token = Str::random(48);

        Cache::put(self::key($token), [
            'card_id' => $cardId,
            'user_id' => $merchantUserId,
        ], self::TTL_SECONDS);

        return $token;
    }

    /** Il token esiste, non è scaduto, ed è di QUESTA card e di QUESTO merchant? */
    public static function isValid(?string $token, int $cardId, int $merchantUserId): bool
    {
        if (! is_string($token) || $token === '') {
            return false;
        }

        $payload = Cache::get(self::key($token));

        return is_array($payload)
            && ($payload['card_id'] ?? null) === $cardId
            && ($payload['user_id'] ?? null) === $merchantUserId;
    }

    /** Brucia il token: da qui in poi serve un nuovo tap fisico. */
    public static function consume(?string $token): void
    {
        if (is_string($token) && $token !== '') {
            Cache::forget(self::key($token));
        }
    }

    private static function key(string $token): string
    {
        return self::PREFIX . hash('sha256', $token);
    }
}
