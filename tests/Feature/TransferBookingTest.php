<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\CreditLimit;
use App\Models\LedgerEntry;
use App\Models\Transfer;
use App\Models\User;
use App\Services\TransferBookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class TransferBookingTest extends TestCase
{
    use RefreshDatabase;

    private TransferBookingService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(TransferBookingService::class);
    }

    public function test_it_books_a_transfer_and_writes_balanced_ledger_entries(): void
    {
        [$buyerCompany, $sellerCompany, $buyerAccount, $sellerAccount, $initiator] = $this->makeAccountsAndInitiator();

        CreditLimit::create(['account_id' => $buyerAccount->id, 'credit_limit' => 20000, 'daily_outgoing_limit' => 15000, 'single_transfer_limit' => 10000]);

        $transfer = $this->svc->book([
            'initiated_by'    => $initiator->id,
            'from_account_id' => $buyerAccount->id,
            'to_account_id'   => $sellerAccount->id,
            'amount'          => 5000,
            'description'     => 'Invoice INV-100',
            'idempotency_key' => (string) Str::uuid(),
            'ip_address'      => '127.0.0.1',
        ]);

        $this->assertSame('booked', $transfer->status);
        $this->assertSame(5000, $transfer->amount);

        $buyerAccount->refresh();
        $sellerAccount->refresh();

        $this->assertSame(-5000, $buyerAccount->available_balance);
        $this->assertSame(5000, $sellerAccount->available_balance);
        $this->assertSame(1, Transfer::count());
        $this->assertSame(2, LedgerEntry::count());
        $this->assertSame(1, AuditLog::where('event', 'transfer.booked')->count());
    }

    public function test_it_rejects_a_transfer_that_exceeds_credit_limit(): void
    {
        [$buyerCompany, $sellerCompany, $buyerAccount, $sellerAccount, $initiator] = $this->makeAccountsAndInitiator();

        CreditLimit::create(['account_id' => $buyerAccount->id, 'credit_limit' => 1000, 'daily_outgoing_limit' => 5000, 'single_transfer_limit' => 5000]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Transfer exceeds the allowed credit exposure.');

        $this->svc->book([
            'initiated_by'    => $initiator->id,
            'from_account_id' => $buyerAccount->id,
            'to_account_id'   => $sellerAccount->id,
            'amount'          => 1500,
            'idempotency_key' => (string) Str::uuid(),
            'ip_address'      => '127.0.0.1',
        ]);
    }

    public function test_it_is_idempotent_for_the_same_booking_request(): void
    {
        [$buyerCompany, $sellerCompany, $buyerAccount, $sellerAccount, $initiator] = $this->makeAccountsAndInitiator();

        CreditLimit::create(['account_id' => $buyerAccount->id, 'credit_limit' => 20000, 'daily_outgoing_limit' => 15000, 'single_transfer_limit' => 10000]);

        $key = (string) Str::uuid();
        $payload = ['initiated_by' => $initiator->id, 'from_account_id' => $buyerAccount->id, 'to_account_id' => $sellerAccount->id, 'amount' => 4000, 'idempotency_key' => $key, 'ip_address' => '127.0.0.1'];

        $first  = $this->svc->book($payload);
        $second = $this->svc->book($payload);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Transfer::count());
        $this->assertSame(2, LedgerEntry::count());
    }

    public function test_it_rejects_initiator_without_permission_on_source_account(): void
    {
        [$buyerCompany, $sellerCompany, $buyerAccount, $sellerAccount] = $this->makeAccounts();
        $unauthorizedUser = $this->makeInitiator($sellerCompany, 'company-member');

        CreditLimit::create(['account_id' => $buyerAccount->id, 'credit_limit' => 20000, 'daily_outgoing_limit' => 15000, 'single_transfer_limit' => 10000]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Initiator is not allowed to operate on this account.');

        $this->svc->book([
            'initiated_by'    => $unauthorizedUser->id,
            'from_account_id' => $buyerAccount->id,
            'to_account_id'   => $sellerAccount->id,
            'amount'          => 1000,
            'idempotency_key' => (string) Str::uuid(),
            'ip_address'      => '127.0.0.1',
        ]);
    }

    public function test_it_rejects_transfer_when_company_is_suspended(): void
    {
        [$buyerCompany, $sellerCompany, $buyerAccount, $sellerAccount, $initiator] = $this->makeAccountsAndInitiator();

        $buyerCompany->forceFill(['status' => 'suspended'])->save();
        CreditLimit::create(['account_id' => $buyerAccount->id, 'credit_limit' => 20000, 'daily_outgoing_limit' => 15000, 'single_transfer_limit' => 10000]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The source company must be active.');

        $this->svc->book([
            'initiated_by'    => $initiator->id,
            'from_account_id' => $buyerAccount->id,
            'to_account_id'   => $sellerAccount->id,
            'amount'          => 1000,
            'idempotency_key' => (string) Str::uuid(),
            'ip_address'      => '127.0.0.1',
        ]);
    }

    private function makeAccountsAndInitiator(): array
    {
        [$buyerCompany, $sellerCompany, $buyerAccount, $sellerAccount] = $this->makeAccounts();
        $initiator = $this->makeInitiator($buyerCompany);

        return [$buyerCompany, $sellerCompany, $buyerAccount, $sellerAccount, $initiator];
    }

    private function makeAccounts(): array
    {
        $buyer  = Company::create(['name' => 'Buyer SRL', 'slug' => 'buyer-srl', 'status' => 'active', 'kyc_status' => 'approved', 'currency_code' => 'KY']);
        $seller = Company::create(['name' => 'Seller SRL', 'slug' => 'seller-srl', 'status' => 'active', 'kyc_status' => 'approved', 'currency_code' => 'KY']);
        $buyerAccount  = Account::create(['company_id' => $buyer->id, 'type' => 'primary', 'owner_type' => 'company', 'currency_code' => 'KY', 'status' => 'active', 'available_balance' => 0, 'pending_balance' => 0]);
        $sellerAccount = Account::create(['company_id' => $seller->id, 'type' => 'primary', 'owner_type' => 'company', 'currency_code' => 'KY', 'status' => 'active', 'available_balance' => 0, 'pending_balance' => 0]);

        return [$buyer, $seller, $buyerAccount, $sellerAccount];
    }

    private function makeInitiator(Company $company, string $roleSlug = 'company-manager'): User
    {
        return User::create([
            'company_id'          => $company->id,
            'account_holder_type' => 'company',
            'name'                => 'Operator',
            'email'               => 'operator-' . Str::lower(Str::random(8)) . '@example.test',
            'password'            => 'secret123',
            'role'                => $roleSlug,
            'is_active'           => true,
            'is_super_admin'      => false,
            'email_verified_at'   => now(),
            'contract_signed_at'  => now(),
        ]);
    }
}
