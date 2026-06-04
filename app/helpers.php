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
