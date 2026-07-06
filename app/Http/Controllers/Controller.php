<?php

namespace App\Http\Controllers;

use Illuminate\Validation\ValidationException;

abstract class Controller
{
    /**
     * Verifica che una serie di limiti finanziari sia numericamente coerente,
     * dal periodo/ambito più ristretto (es. per singola operazione) al più ampio
     * (es. mensile): un limite più ampio non può mai essere inferiore a uno più
     * ristretto già impostato.
     *
     * Esempio di incoerenza bloccata: limite giornaliero 1.200,00 KY ma limite
     * mensile 50,00 KY — il mensile, che copre un periodo più ampio, non
     * potrebbe mai essere raggiunto in modo coerente con quello giornaliero.
     *
     * I valori nulli (limite non impostato) vengono ignorati e non interrompono
     * la catena di confronto con gli step successivi.
     *
     * @param list<array{field: string, label: string, value: int|null}> $steps
     *        Ordinati dal più ristretto al più ampio. I valori vanno passati
     *        già convertiti in centesimi (stessa unità di ky_format()).
     *
     * @throws ValidationException
     */
    protected function assertLimitsAscending(array $steps): void
    {
        $previous = null;

        foreach ($steps as $step) {
            if ($step['value'] === null) {
                continue;
            }

            if ($previous !== null && $step['value'] < $previous['value']) {
                throw ValidationException::withMessages([
                    $step['field'] => sprintf(
                        'Il limite %s (%s KY) non può essere inferiore al limite %s (%s KY).',
                        $step['label'],
                        ky_format($step['value']),
                        $previous['label'],
                        ky_format($previous['value']),
                    ),
                ]);
            }

            $previous = $step;
        }
    }
}
