<?php

namespace App\Exceptions\Financial;

class SingleTransferLimitExceededException extends FinancialException
{
    public function __construct(
        public readonly int $limit,
        public readonly int $requested,
    ) {
        parent::__construct(
            'Il pagamento di ' . ky_format($requested) . ' KY supera il limite massimo per singola operazione ('
            . ky_format($limit) . ' KY). '
            . 'Per aumentare il limite contatta il gestore del circuito.'
        );
    }
}
