<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\CreditLimit;
use App\Models\LedgerEntry;
use App\Models\Transfer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KMoneyBootstrapTest extends TestCase
{
    use RefreshDatabase;

    public function test_root_endpoint_reports_kmoney_status(): void
    {
        $response = $this->get('/');

        $response
            ->assertRedirect('/login');
    }

    public function test_company_account_and_ledger_relations_are_persisted(): void
    {
        $sellerOwner = User::factory()->create(['account_holder_type' => 'company', 'role' => 'owner']);
        $buyerOwner = User::factory()->create(['account_holder_type' => 'company', 'role' => 'owner']);

        $seller = Company::create([
            'name' => 'Seller SRL',
            'slug' => 'seller-srl',
            'status' => 'active',
        ]);

        $buyer = Company::create([
            'name' => 'Buyer SRL',
            'slug' => 'buyer-srl',
            'status' => 'active',
        ]);

        $sellerOwner->update(['company_id' => $seller->id]);
        $buyerOwner->update(['company_id' => $buyer->id]);

        $sellerAccount = Account::create([
            'company_id' => $seller->id,
            'owner_user_id' => $sellerOwner->id,
            'owner_type' => 'company',
            'type' => 'primary',
            'available_balance' => 10000,
        ]);

        $buyerAccount = Account::create([
            'company_id' => $buyer->id,
            'owner_user_id' => $buyerOwner->id,
            'owner_type' => 'company',
            'type' => 'primary',
            'available_balance' => -10000,
        ]);

        CreditLimit::create([
            'account_id' => $buyerAccount->id,
            'credit_limit' => 25000,
        ]);

        $transfer = Transfer::create([
            'from_account_id' => $buyerAccount->id,
            'to_account_id' => $sellerAccount->id,
            'amount' => 5000,
            'status' => 'booked',
        ]);

        LedgerEntry::create([
            'transfer_id' => $transfer->id,
            'account_id' => $buyerAccount->id,
            'direction' => 'debit',
            'amount' => 5000,
            'balance_after' => -15000,
            'posted_at' => now(),
        ]);

        LedgerEntry::create([
            'transfer_id' => $transfer->id,
            'account_id' => $sellerAccount->id,
            'direction' => 'credit',
            'amount' => 5000,
            'balance_after' => 15000,
            'posted_at' => now(),
        ]);

        $this->assertCount(1, $buyerAccount->creditLimits);
        $this->assertCount(1, $buyerAccount->outgoingTransfers);
        $this->assertCount(1, $sellerAccount->incomingTransfers);
        $this->assertCount(2, $transfer->ledgerEntries);
    }
}
