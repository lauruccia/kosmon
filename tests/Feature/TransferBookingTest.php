<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\CreditLimit;
use App\Models\LedgerEntry;
use App\Models\Role;
use App\Models\Transfer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TransferBookingTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_books_a_transfer_and_writes_balanced_ledger_entries(): void
    {
        [$buyerCompany, $sellerCompany, $buyerAccount, $sellerAccount, $initiator] = $this->makeAccountsAndInitiator();

        CreditLimit::create(['account_id' => $buyerAccount->id, 'credit_limit' => 20000, 'daily_outgoing_limit' => 15000, 'single_transfer_limit' => 10000]);

        $response = $this->actingAs($initiator)->postJson('/transfers', [
            'initiated_by' => $initiator->id,
            'from_account_id' => $buyerAccount->id,
            'to_account_id' => $sellerAccount->id,
            'amount' => 5000,
            'description' => 'Invoice INV-100',
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $response->assertCreated()->assertJsonPath('data.status', 'booked')->assertJsonPath('data.amount', 5000)->assertJsonPath('data.ledger_entries_count', 2);

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

        $response = $this->actingAs($initiator)->postJson('/transfers', [
            'initiated_by' => $initiator->id,
            'from_account_id' => $buyerAccount->id,
            'to_account_id' => $sellerAccount->id,
            'amount' => 1500,
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $response->assertStatus(422)->assertJsonPath('message', 'Transfer exceeds the allowed credit exposure.');
        $this->assertSame(0, Transfer::count());
        $this->assertSame(0, LedgerEntry::count());
        $this->assertSame(1, AuditLog::where('event', 'transfer.rejected')->count());
    }

    public function test_it_is_idempotent_for_the_same_booking_request(): void
    {
        [$buyerCompany, $sellerCompany, $buyerAccount, $sellerAccount, $initiator] = $this->makeAccountsAndInitiator();

        CreditLimit::create(['account_id' => $buyerAccount->id, 'credit_limit' => 20000, 'daily_outgoing_limit' => 15000, 'single_transfer_limit' => 10000]);

        $payload = ['initiated_by' => $initiator->id, 'from_account_id' => $buyerAccount->id, 'to_account_id' => $sellerAccount->id, 'amount' => 4000, 'idempotency_key' => (string) Str::uuid()];

        $firstResponse = $this->actingAs($initiator)->postJson('/transfers', $payload);
        $secondResponse = $this->actingAs($initiator)->postJson('/transfers', $payload);

        $firstResponse->assertCreated();
        $secondResponse->assertCreated();
        $this->assertSame(1, Transfer::count());
        $this->assertSame(2, LedgerEntry::count());
    }

    public function test_it_rejects_initiator_without_permission_on_source_account(): void
    {
        [$buyerCompany, $sellerCompany, $buyerAccount, $sellerAccount] = $this->makeAccounts();
        $unauthorizedUser = $this->makeInitiator($sellerCompany, 'company-member');

        CreditLimit::create(['account_id' => $buyerAccount->id, 'credit_limit' => 20000, 'daily_outgoing_limit' => 15000, 'single_transfer_limit' => 10000]);

        $response = $this->actingAs($unauthorizedUser)->postJson('/transfers', [
            'initiated_by' => $unauthorizedUser->id,
            'from_account_id' => $buyerAccount->id,
            'to_account_id' => $sellerAccount->id,
            'amount' => 1000,
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $response->assertStatus(422)->assertJsonPath('message', 'Initiator is not allowed to operate on this account.');
    }

    public function test_it_rejects_transfer_when_company_is_suspended(): void
    {
        [$buyerCompany, $sellerCompany, $buyerAccount, $sellerAccount, $initiator] = $this->makeAccountsAndInitiator();

        $buyerCompany->forceFill(['status' => 'suspended'])->save();
        CreditLimit::create(['account_id' => $buyerAccount->id, 'credit_limit' => 20000, 'daily_outgoing_limit' => 15000, 'single_transfer_limit' => 10000]);

        $response = $this->actingAs($initiator)->postJson('/transfers', [
            'initiated_by' => $initiator->id,
            'from_account_id' => $buyerAccount->id,
            'to_account_id' => $sellerAccount->id,
            'amount' => 1000,
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $response->assertStatus(422)->assertJsonPath('message', 'The source company must be active.');
    }

    private function makeAccountsAndInitiator(): array
    {
        [$buyerCompany, $sellerCompany, $buyerAccount, $sellerAccount] = $this->makeAccounts();
        $initiator = $this->makeInitiator($buyerCompany);

        return [$buyerCompany, $sellerCompany, $buyerAccount, $sellerAccount, $initiator];
    }

    private function makeAccounts(): array
    {
        $buyer = Company::create(['name' => 'Buyer SRL', 'slug' => 'buyer-srl', 'status' => 'active']);
        $seller = Company::create(['name' => 'Seller SRL', 'slug' => 'seller-srl', 'status' => 'active']);
        $buyerAccount = Account::create(['company_id' => $buyer->id, 'type' => 'member', 'owner_type' => 'company', 'status' => 'active', 'available_balance' => 0]);
        $sellerAccount = Account::create(['company_id' => $seller->id, 'type' => 'member', 'owner_type' => 'company', 'status' => 'active', 'available_balance' => 0]);

        return [$buyer, $seller, $buyerAccount, $sellerAccount];
    }

    private function makeInitiator(Company $company, string $roleSlug = 'company-manager'): User
    {
        $user = User::create([
            'company_id' => $company->id,
            'account_holder_type' => 'company',
            'name' => 'Operator',
            'email' => 'operator-' . Str::lower(Str::random(8)) . '@example.test',
            'password' => 'secret123',
            'role' => $roleSlug,
            'is_active' => true,
            'is_super_admin' => false,
        ]);

        $role = Role::query()->where('slug', $roleSlug)->first();
        if ($role) {
            $user->roles()->sync([$role->id]);
        }

        return $user;
    }
}
