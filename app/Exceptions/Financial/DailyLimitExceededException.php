<?php

namespace App\Exceptions\Financial;

class DailyLimitExceededException extends FinancialException
{
    public function __construct(
        public readonly int $limit,
        public readonly int $spentToday,
        public readonly int $requested,
    ) {
        $remaining = max(0, $limit - $spentToday);

        parent::__construct(
            'Hai raggiunto il limite giornaliero di uscita ('
            . ky_format($limit) . ' KY). '
            . 'Speso oggi: ' . ky_format($spentToday) . ' KY — '
            . 'Residuo: ' . ky_format($remaining) . ' KY. '
            . 'Per aumentare il limite contatta il gestore del circuito.'
        );
    }
}
