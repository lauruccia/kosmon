<?php

if (! function_exists('ky_format')) {
    /**
     * Formatta un importo KY memorizzato in CENTESIMI (intero) in stringa con 2 decimali.
     * Divide sempre per 100, coerentemente con la convenzione del progetto.
     * Es: ky_format(6) → "0,06"   ky_format(80040) → "800,40"   ky_format(1234567) → "12.345,67"
     */
    function ky_format(int|float|null $amount): string
    {
        return number_format(((int) $amount) / 100, 2, ',', '.');
    }
}

if (! function_exists('ky_to_cents')) {
    /**
     * Converte un importo digitato dall'utente in KY (es. "1", "1,50", "1.50")
     * nel valore intero in CENTESIMI usato internamente dal circuito.
     * Accetta sia la virgola che il punto come separatore decimale.
     * Es: ky_to_cents("1") → 100   ky_to_cents("1,50") → 150   ky_to_cents("0,01") → 1
     */
    function ky_to_cents(string|int|float|null $amount): int
    {
        $normalized = str_replace(',', '.', (string) $amount);

        return (int) round(((float) $normalized) * 100);
    }
}

if (! function_exists('ky_input')) {
    /**
     * Converte un valore in CENTESIMI (intero dal DB) nel valore KY da
     * pre-compilare in un campo <input type="number"> (separatore punto).
     * Restituisce stringa vuota se null, così il placeholder resta visibile.
     * Es: ky_input(10000) → "100.00"   ky_input(null) → ""
     */
    function ky_input(int|float|null $cents): string
    {
        if ($cents === null) {
            return '';
        }

        return number_format(((int) $cents) / 100, 2, '.', '');
    }
}

if (! function_exists('trusted_proxies')) {
    /**
     * Lista di proxy fidati per gli header X-Forwarded-* (IP reale del client).
     *
     * SICUREZZA: NON usare '*' come default. Fidarsi di tutti i proxy rende
     * X-Forwarded-For falsificabile dal client, avvelenando rate limiter,
     * anti-frode su IP e notifica "nuovo IP" sui token API.
     *
     * Default sicuro: loopback + reti private (adatto a cPanel/LiteSpeed dove
     * PHP gira dietro il web server locale). Override via env TRUSTED_PROXIES
     * (CSV, es. range Cloudflare) oppure "*" per opt-in esplicito.
     *
     * NB: definita qui in app/helpers.php (già autoloaded via composer "files")
     * e non come classe, così funziona anche con deploy che pullano solo i file
     * senza rigenerare l'autoloader.
     *
     * NB2: chiamata da config/trustedproxy.php (NON da bootstrap/app.php):
     * la callback withMiddleware gira PRIMA che la config sia caricata, mentre
     * il middleware TrustProxies legge config('trustedproxy.proxies') a
     * request time — così il valore sopravvive anche a `config:cache`.
     *
     * @return string|array<int, string>
     */
    function trusted_proxies(): string|array
    {
        $safeDefault = [
            '127.0.0.1',
            '::1',
            '10.0.0.0/8',
            '172.16.0.0/12',
            '192.168.0.0/16',
            'fc00::/7',
        ];

        $raw = env('TRUSTED_PROXIES');

        if ($raw === null || trim((string) $raw) === '') {
            return $safeDefault;
        }

        $raw = trim((string) $raw);

        if ($raw === '*') {
            return '*';
        }

        $proxies = array_values(array_filter(array_map(
            static fn (string $p): string => trim($p),
            explode(',', $raw),
        ), static fn (string $p): bool => $p !== ''));

        return $proxies !== [] ? $proxies : $safeDefault;
    }
}
