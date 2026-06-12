<?php

namespace App\Exceptions\Financial;

class CreditExposureExceededException extends FinancialException
{
    public function __construct(
        public readonly int $creditLimit,
        public readonly int $currentBalance,
        public readonly int $requested,
    ) {
        $available = max(0, $creditLimit + $currentBalance);

        parent::__construct(
            'Il pagamento supera il fido disponibile. '
            . 'Fido concesso: ' . ky_format($creditLimit) . ' KY — '
            . 'Disponibile ora: ' . ky_format($available) . ' KY. '
            . 'Per aumentare il fido contatta il gestore del circuito.'
        );
    }
}
