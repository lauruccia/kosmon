<?php

namespace App\Exceptions\Financial;

class CircuitCapacityExceededException extends FinancialException
{
    public function __construct(
        public readonly int $limit,
        public readonly int $requested,
    ) {
        parent::__construct(
            'Il pagamento di ' . ky_format($requested) . ' KY supera la capacità massima per operazione del circuito ('
            . ky_format($limit) . ' KY).'
        );
    }
}
