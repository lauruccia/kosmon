<?php

namespace Tests\Feature;

use App\Exceptions\Financial\DailyLimitExceededException;
use App\Exceptions\Financial\MonthlyLimitExceededException;
use App\Exceptions\Financial\SingleTransferLimitExceededException;
use App\Models\Account;
use App\Models\Company;
use App\Models\CreditLimit;
use App\Models\LedgerEntry;
use App\Models\Transfer;
use App\Models\User;
use App\Services\TransferBookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Test sui limiti di trasferimento (sincroni, in assertTransferWithinLimits) e
 * sull'invariante del circuito chiuso (SUM dei saldi = 0; partita doppia bilanciata).
 */
class TransferBookingLimitsTest extends TestCase
{
    use RefreshDatabase;

    private TransferBookingService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(TransferBookingService::class);
    }

    public function test_it_blocks_a_transfer_exceeding_the_daily_limit(): void
    {
        [$buyer, $buyerAccount, $sellerAccount] = $this->scenario(['daily_transaction_limit' => 10000]);

        // Primo movimento entro il limite giornaliero (10000): 6000 -> ok
        $this->book($buyer, $buyerAccount, $sellerAccount, 6000);

        // Secondo movimento: 6000 cumulato = 12000 > 10000 -> bloccato
        $this->expectException(DailyLimitExceededException::class);
        $this->book($buyer, $buyerAccount, $sellerAccount, 6000);
    }

    public function test_it_blocks_a_transfer_exceeding_the_monthly_limit(): void
    {
        [$buyer, $buyerAccount, $sellerAccount] = $this->scenario(['monthly_transaction_limit' => 10000]);

        $this->book($buyer, $buyerAccount, $sellerAccount, 6000);

        $this->expectException(MonthlyLimitExceededException::class);
        $this->book($buyer, $buyerAccount, $sellerAccount, 6000);
    }

    public function test_it_blocks_a_transfer_exceeding_the_single_transfer_limit(): void
    {
        [$buyer, $buyerAccount, $sellerAccount] = $this->scenario(['per_movement_limit' => 5000]);

        $this->expectException(SingleTransferLimitExceededException::class);
        $this->book($buyer, $buyerAccount, $sellerAccount, 6000);
    }

    public function test_closed_circuit_invariant_holds_after_a_booking(): void
    {
        // Conto sistema (Cassa Circuito) a saldo 0 + due conti azienda
        Account::create([
            'type' => 'main', 'owner_type' => 'company', 'currency_code' => 'KY',
            'status' => 'active', 'available_balance' => 0, 'pending_balance' => 0,
            'is_system_account' => true, 'account_name' => 'Cassa Circuito',
        ]);

        [$buyer, $buyerAccount, $sellerAccount] = $this->scenario();

        $this->book($buyer, $buyerAccount, $sellerAccount, 5000);

        $buyerAccount->refresh();
        $sellerAccount->refresh();

        // Saldi simmetrici
        $this->assertSame(-5000, $buyerAccount->available_balance);
        $this->assertSame(5000, $sellerAccount->available_balance);

        // Invariante 1: somma di TUTTI i saldi del circuito = 0
        $this->assertSame(0, (int) Account::sum('available_balance'));

        // Invariante 2: partita doppia bilanciata (totale debiti = totale crediti)
        $debit  = (int) LedgerEntry::where('direction', 'debit')->sum('amount');
        $credit = (int) LedgerEntry::where('direction', 'credit')->sum('amount');
        $this->assertSame($debit, $credit);
        $this->assertSame(2, LedgerEntry::count());
    }

    // ── helper ────────────────────────────────────────────────────────────────

    /** @return array{0: Company, 1: Account, 2: Account} */
    private function scenario(array $limitOverrides = []): array
    {
        $buyer  = Company::create(['name' => 'Buyer SRL', 'slug' => 'buyer-' . Str::lower(Str::random(6)), 'status' => 'active', 'kyc_status' => 'approved', 'currency_code' => 'KY']);
        $seller = Company::create(['name' => 'Seller SRL', 'slug' => 'seller-' . Str::lower(Str::random(6)), 'status' => 'active', 'kyc_status' => 'approved', 'currency_code' => 'KY']);

        $buyerAccount  = Account::create(['company_id' => $buyer->id, 'type' => 'primary', 'owner_type' => 'company', 'currency_code' => 'KY', 'status' => 'active', 'available_balance' => 0, 'pending_balance' => 0]);
        $sellerAccount = Account::create(['company_id' => $seller->id, 'type' => 'primary', 'owner_type' => 'company', 'currency_code' => 'KY', 'status' => 'active', 'available_balance' => 0, 'pending_balance' => 0]);

        // Fido ampio: isola i limiti sotto test dal check di esposizione/credito
        CreditLimit::create(['account_id' => $buyerAccount->id, 'credit_limit' => 100000, 'daily_outgoing_limit' => null, 'single_transfer_limit' => 100000]);

        $this->makeInitiator($buyer, $limitOverrides);

        return [$buyer, $buyerAccount, $sellerAccount];
    }

    private function makeInitiator(Company $company, array $limitOverrides = []): User
    {
        $base = [
            'company_id'                   => $company->id,
            'account_holder_type'          => 'company',
            'name'                         => 'Operator',
            'email'                        => 'op-' . Str::lower(Str::random(8)) . '@example.test',
            'password'                     => 'secret123',
            'role'                         => 'company-manager',
            'is_active'                    => true,
            'is_super_admin'               => false,
            'email_verified_at'            => now(),
            'contract_signed_at'           => now(),
            'transfer_limits_use_defaults' => true,
        ];

        if ($limitOverrides !== []) {
            // Limiti espliciti dell'utente: disattiva l'ereditarietà dai default di sistema
            $base['transfer_limits_use_defaults'] = false;
            $base = array_merge($base, $limitOverrides);
        }

        return User::create($base);
    }

    private function book(Company $company, Account $from, Account $to, int $amount): Transfer
    {
        return $this->svc->book([
            'initiated_by'    => $company->users()->first()->id,
            'from_account_id' => $from->id,
            'to_account_id'   => $to->id,
            'amount'          => $amount,
            'idempotency_key' => (string) Str::uuid(),
            'ip_address'      => '127.0.0.1',
        ]);
    }
}
