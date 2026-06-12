<?php

namespace App\Exceptions\Financial;

class NegativeBalanceLimitExceededException extends FinancialException
{
    public function __construct(
        public readonly int $negativeLimit,
        public readonly int $currentBalance,
        public readonly int $requested,
    ) {
        parent::__construct(
            'Il pagamento porterebbe il saldo oltre il limite di scoperto consentito ('
            . ky_format($negativeLimit) . ' KY). '
            . 'Saldo attuale: ' . ky_format($currentBalance) . ' KY. '
            . 'Per aumentare il limite contatta il gestore del circuito.'
        );
    }
}
