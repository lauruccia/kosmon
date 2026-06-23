<?php

namespace App\Support;

/**
 * Risoluzione della lista di proxy fidati per la gestione degli header
 * X-Forwarded-* (IP reale del client, schema, host, porta).
 *
 * MOTIVAZIONE DI SICUREZZA
 * ------------------------
 * Fidarsi di TUTTI i proxy (trustProxies(at: '*')) rende l'header
 * X-Forwarded-For falsificabile da qualsiasi client. Questo avvelena:
 *   - le chiavi del rate limiter (throttle per IP),
 *   - l'anti-frode basato su IP (blocco temporaneo del conto),
 *   - la notifica "nuovo IP" sui token API.
 *
 * In produzione (cPanel/LiteSpeed) PHP gira dietro il web server locale
 * sulla stessa macchina: l'unico proxy legittimo è il loopback / una rete
 * privata. Fidarsi solo di questi indirizzi mantiene la risoluzione
 * dell'IP reale e chiude la falsificazione.
 *
 * CONFIGURAZIONE
 * --------------
 * Override tramite la variabile d'ambiente TRUSTED_PROXIES (CSV):
 *   TRUSTED_PROXIES="127.0.0.1,::1"
 *   TRUSTED_PROXIES="173.245.48.0/20,103.21.244.0/22"   (es. range Cloudflare)
 *   TRUSTED_PROXIES="*"   (sconsigliato: ripristina il comportamento insicuro)
 *
 * Se la variabile non è impostata si usa il default sicuro qui sotto:
 * loopback + reti private RFC 1918 / ULA, adatto al deploy cPanel locale.
 */
class TrustedProxies
{
    /**
     * Default sicuro: proxy locale (loopback) e reti private.
     */
    private const SAFE_DEFAULT = [
        '127.0.0.1',
        '::1',
        '10.0.0.0/8',
        '172.16.0.0/12',
        '192.168.0.0/16',
        'fc00::/7',
    ];

    /**
     * @return string|array<int, string>
     */
    public static function resolve(): string|array
    {
        $raw = env('TRUSTED_PROXIES');

        if ($raw === null || trim((string) $raw) === '') {
            return self::SAFE_DEFAULT;
        }

        $raw = trim((string) $raw);

        // Opt-in esplicito al comportamento insicuro (solo se davvero voluto).
        if ($raw === '*') {
            return '*';
        }

        $proxies = array_values(array_filter(array_map(
            static fn (string $p): string => trim($p),
            explode(',', $raw),
        ), static fn (string $p): bool => $p !== ''));

        return $proxies !== [] ? $proxies : self::SAFE_DEFAULT;
    }
}
