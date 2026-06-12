<?php

namespace App\Exceptions\Financial;

class MonthlyLimitExceededException extends FinancialException
{
    public function __construct(
        public readonly int $limit,
        public readonly int $spentThisMonth,
        public readonly int $requested,
    ) {
        $remaining = max(0, $limit - $spentThisMonth);

        parent::__construct(
            'Hai raggiunto il limite mensile di uscita ('
            . ky_format($limit) . ' KY). '
            . 'Speso questo mese: ' . ky_format($spentThisMonth) . ' KY — '
            . 'Residuo: ' . ky_format($remaining) . ' KY. '
            . 'Per aumentare il limite contatta il gestore del circuito.'
        );
    }
}
