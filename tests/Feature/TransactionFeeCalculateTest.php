<?php

namespace Tests\Feature;

use App\Models\TransactionFee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Test della funzione pura TransactionFee::calculate() — cuore del calcolo commissioni.
 * (La PRENOTAZIONE della fee avviene in DB::afterCommit e non è testabile in modo
 *  affidabile sotto RefreshDatabase; qui isoliamo la logica di calcolo.)
 */
class TransactionFeeCalculateTest extends TestCase
{
    use RefreshDatabase;

    private function makeFee(array $attrs = []): void
    {
        TransactionFee::create(array_merge([
            'operation_kind' => 'trade_payment',
            'fee_type'       => 'percentage',
            'fee_value'      => 2,
            'min_fee'        => 0,
            'max_fee'        => null,
            'is_active'      => true,
            'description'    => 'fee di test',
        ], $attrs));
    }

    public function test_percentage_fee_is_computed_on_amount(): void
    {
        $this->makeFee(['operation_kind' => 'trade_payment', 'fee_type' => 'percentage', 'fee_value' => 2]);
        // 2% di 100,00 KY (10000 cent) = 2,00 KY (200 cent)
        $this->assertSame(200, TransactionFee::calculate('trade_payment', 10000));
    }

    public function test_percentage_fee_respects_min_clamp(): void
    {
        $this->makeFee(['fee_type' => 'percentage', 'fee_value' => 1, 'min_fee' => 50]);
        // 1% di 1000 = 10, ma min_fee 50 -> 50
        $this->assertSame(50, TransactionFee::calculate('trade_payment', 1000));
    }

    public function test_percentage_fee_respects_max_clamp(): void
    {
        $this->makeFee(['fee_type' => 'percentage', 'fee_value' => 10, 'max_fee' => 5000]);
        // 10% di 100000 = 10000, ma max_fee 5000 -> 5000
        $this->assertSame(5000, TransactionFee::calculate('trade_payment', 100000));
    }

    public function test_flat_fee_is_fixed_regardless_of_amount(): void
    {
        $this->makeFee(['fee_type' => 'fixed', 'fee_value' => 150]);
        $this->assertSame(150, TransactionFee::calculate('trade_payment', 999999));
        $this->assertSame(150, TransactionFee::calculate('trade_payment', 1));
    }

    public function test_wildcard_fee_applies_when_no_exact_kind(): void
    {
        $this->makeFee(['operation_kind' => '*', 'fee_type' => 'percentage', 'fee_value' => 3]);
        // 3% di 50,00 (5000) = 1,50 (150)
        $this->assertSame(150, TransactionFee::calculate('portal_payment', 5000));
    }

    public function test_inactive_fee_is_ignored(): void
    {
        $this->makeFee(['operation_kind' => 'trade_payment', 'is_active' => false, 'fee_value' => 5]);
        $this->assertSame(0, TransactionFee::calculate('trade_payment', 10000));
    }

    public function test_cashback_and_fee_kinds_are_never_charged(): void
    {
        $this->makeFee(['operation_kind' => '*', 'fee_value' => 10]);
        $this->assertSame(0, TransactionFee::calculate('portal_cashback', 10000));
        $this->assertSame(0, TransactionFee::calculate('portal_fee', 10000));
    }

    public function test_no_configuration_means_no_fee(): void
    {
        $this->assertSame(0, TransactionFee::calculate('trade_payment', 10000));
    }
}
