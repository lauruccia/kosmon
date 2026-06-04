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
