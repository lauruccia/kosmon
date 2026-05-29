<?php

if (! function_exists('ky_format')) {
    /**
     * Formatta un importo in KY con 2 decimali.
     * Es: ky_format(6) → "6,00"   ky_format(1234.5) → "1.234,50"
     */
    function ky_format(int|float $amount): string
    {
        return number_format($amount, 2, ',', '.');
    }
}
