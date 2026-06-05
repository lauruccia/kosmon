<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\NettingProposal;
use App\Models\Transfer;
use App\Models\User;
use App\Services\NettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class NettingServiceTest extends TestCase
{
    use RefreshDatabase;

    private NettingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new NettingService();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeAccount(int $balance = 0): Account
    {
        $company = Company::factory()->create();

        return Account::factory()->create([
            'company_id'        => $company->id,
            'available_balance' => $balance,
        ]);
    }

    private function makeUser(Account $account): User
    {
        return User::factory()->create([
            'company_id' => $account->company_id,
            'role'               => 'company-manager',
            'contract_signed_at' => now(),
        ]);
    }

    /**
     * Creates a pending collection-request transfer between two accounts.
     */
    private function makePendingTransfer(Account $fromAccount, Account $toAccount, int $amount): Transfer
    {
        return Transfer::factory()->create([
            'from_account_id' => $fromAccount->id,
            'to_account_id'   => $toAccount->id,
            'amount'          => $amount,
            'status'          => 'pending',
            'kind'            => 'portal_collection_request',
        ]);
    }

    // ── propose() ────────────────────────────────────────────────────────────

    public function test_propose_creates_netting_proposal_with_correct_net_amount(): void
    {
        $accountA = $this->makeAccount(10_000);
        $accountB = $this->makeAccount(10_000);
        $userA    = $this->makeUser($accountA);

        // A owes B 5000; B owes A 3000 → net: A pays 2000
        $transferAtoB = $this->makePendingTransfer($accountA, $accountB, 5000); // B is creditor
        $transferBtoA = $this->makePendingTransfer($accountB, $accountA, 3000); // A is creditor

        $proposal = $this->service->propose(
            proposerAccountId:       $accountA->id,
            counterpartyAccountId:   $accountB->id,
            proposerTransferIds:     [$transferBtoA->id], // A's credits (what B owes A)
            counterpartyTransferIds: [$transferAtoB->id], // B's credits (what A owes B)
            proposedBy:              $userA->id,
        );

        $this->assertSame('pending', $proposal->status);
        $this->assertSame(3000, $proposal->proposer_total);
        $this->assertSame(5000, $proposal->counterparty_total);
        $this->assertSame(2000, $proposal->net_amount);
        $this->assertSame($accountA->id, $proposal->net_payer_account_id); // A pays the difference
        $this->assertNotNull($proposal->expires_at);

        // Audit log written correctly
        $this->assertSame(1, AuditLog::where('event', 'netting.proposed')->count());
        $log = AuditLog::where('event', 'netting.proposed')->first();
        $this->assertSame($userA->id, $log->actor_user_id); // fix bug confirmed
    }

    public function test_propose_calculates_zero_net_when_amounts_are_equal(): void
    {
        $accountA = $this->makeAccount();
        $accountB = $this->makeAccount();
        $userA    = $this->makeUser($accountA);

        $t1 = $this->makePendingTransfer($accountA, $accountB, 4000);
        $t2 = $this->makePendingTransfer($accountB, $accountA, 4000);

        $proposal = $this->service->propose(
            proposerAccountId:       $accountA->id,
            counterpartyAccountId:   $accountB->id,
            proposerTransferIds:     [$t2->id],
            counterpartyTransferIds: [$t1->id],
            proposedBy:              $userA->id,
        );

        $this->assertSame(0, $proposal->net_amount);
        $this->assertNull($proposal->net_payer_account_id);
    }

    public function test_propose_throws_when_same_account(): void
    {
        $account = $this->makeAccount();
        $user    = $this->makeUser($account);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/te stesso/');

        $this->service->propose(
            proposerAccountId:       $account->id,
            counterpartyAccountId:   $account->id,
            proposerTransferIds:     [],
            counterpartyTransferIds: [],
            proposedBy:              $user->id,
        );
    }

    public function test_propose_throws_when_no_transfers_selected(): void
    {
        $accountA = $this->makeAccount();
        $accountB = $this->makeAccount();
        $userA    = $this->makeUser($accountA);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/almeno un/');

        $this->service->propose(
            proposerAccountId:       $accountA->id,
            counterpartyAccountId:   $accountB->id,
            proposerTransferIds:     [],
            counterpartyTransferIds: [],
            proposedBy:              $userA->id,
        );
    }

    public function test_propose_throws_when_transfer_belongs_to_wrong_pair(): void
    {
        $accountA = $this->makeAccount();
        $accountB = $this->makeAccount();
        $accountC = $this->makeAccount();
        $userA    = $this->makeUser($accountA);

        // Transfer is between A and C, not A and B
        $wrongTransfer = $this->makePendingTransfer($accountC, $accountA, 1000);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/non sono validi/');

        $this->service->propose(
            proposerAccountId:       $accountA->id,
            counterpartyAccountId:   $accountB->id,
            proposerTransferIds:     [$wrongTransfer->id],
            counterpartyTransferIds: [],
            proposedBy:              $userA->id,
        );
    }

    // ── accept() ─────────────────────────────────────────────────────────────

    public function test_accept_cancels_pending_transfers_and_books_net_transfer(): void
    {
        $accountA = $this->makeAccount(5_000); // net payer
        $accountB = $this->makeAccount(0);
        $userA    = $this->makeUser($accountA);
        $userB    = $this->makeUser($accountB);

        $tAtoB = $this->makePendingTransfer($accountA, $accountB, 5000);
        $tBtoA = $this->makePendingTransfer($accountB, $accountA, 3000);

        $proposal = $this->service->propose(
            proposerAccountId:       $accountA->id,
            counterpartyAccountId:   $accountB->id,
            proposerTransferIds:     [$tBtoA->id],
            counterpartyTransferIds: [$tAtoB->id],
            proposedBy:              $userA->id,
        );

        $result = $this->service->accept($proposal, $userB->id);

        // Pending transfers cancelled
        $tAtoB->refresh();
        $tBtoA->refresh();
        $this->assertSame('cancelled', $tAtoB->status);
        $this->assertSame('cancelled', $tBtoA->status);

        // Net transfer booked
        $this->assertSame('accepted', $result->status);
        $this->assertNotNull($result->net_transfer_id);

        $netTransfer = Transfer::find($result->net_transfer_id);
        $this->assertSame(2000, (int) $netTransfer->amount);
        $this->assertSame('portal_netting', $netTransfer->kind);
        $this->assertSame('booked', $netTransfer->status);
        $this->assertSame($accountA->id, $netTransfer->from_account_id);
        $this->assertSame($accountB->id, $netTransfer->to_account_id);

        // Balances updated: A pays 2000 net
        $accountA->refresh();
        $accountB->refresh();
        $this->assertSame(5_000 - 2000, $accountA->available_balance);
        $this->assertSame(2000, $accountB->available_balance);

        // Ledger entries for net transfer
        $this->assertSame(2, LedgerEntry::where('transfer_id', $netTransfer->id)->count());

        // Audit log correct (fix bug confirmed: actor_user_id not user_id)
        $log = AuditLog::where('event', 'netting.accepted')->first();
        $this->assertNotNull($log);
        $this->assertSame($userB->id, $log->actor_user_id);
    }

    public function test_accept_with_zero_net_amount_does_not_create_net_transfer(): void
    {
        $accountA = $this->makeAccount();
        $accountB = $this->makeAccount();
        $userA    = $this->makeUser($accountA);
        $userB    = $this->makeUser($accountB);

        $t1 = $this->makePendingTransfer($accountA, $accountB, 4000);
        $t2 = $this->makePendingTransfer($accountB, $accountA, 4000);

        $proposal = $this->service->propose(
            proposerAccountId:       $accountA->id,
            counterpartyAccountId:   $accountB->id,
            proposerTransferIds:     [$t2->id],
            counterpartyTransferIds: [$t1->id],
            proposedBy:              $userA->id,
        );

        $result = $this->service->accept($proposal, $userB->id);

        $this->assertSame('accepted', $result->status);
        $this->assertNull($result->net_transfer_id); // no net transfer needed

        // Both pending transfers cancelled
        $this->assertSame('cancelled', $t1->fresh()->status);
        $this->assertSame('cancelled', $t2->fresh()->status);

        // No ledger entries created
        $this->assertSame(0, LedgerEntry::count());
    }

    public function test_accept_throws_when_net_payer_has_insufficient_balance(): void
    {
        $accountA = $this->makeAccount(0); // A is net payer, no balance
        $accountB = $this->makeAccount(0);
        $accountA->forceFill(['allow_negative_balance' => false])->save();

        $userA = $this->makeUser($accountA);
        $userB = $this->makeUser($accountB);

        $tAtoB = $this->makePendingTransfer($accountA, $accountB, 5000);
        $tBtoA = $this->makePendingTransfer($accountB, $accountA, 3000);

        $proposal = $this->service->propose(
            proposerAccountId:       $accountA->id,
            counterpartyAccountId:   $accountB->id,
            proposerTransferIds:     [$tBtoA->id],
            counterpartyTransferIds: [$tAtoB->id],
            proposedBy:              $userA->id,
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/[Ss]aldo/');

        $this->service->accept($proposal, $userB->id);
    }

    public function test_accept_throws_when_proposal_already_accepted(): void
    {
        $accountA = $this->makeAccount(99_999);
        $accountB = $this->makeAccount(0);
        $userA    = $this->makeUser($accountA);
        $userB    = $this->makeUser($accountB);

        $t1 = $this->makePendingTransfer($accountA, $accountB, 2000);
        $t2 = $this->makePendingTransfer($accountB, $accountA, 1000);

        $proposal = $this->service->propose(
            proposerAccountId:       $accountA->id,
            counterpartyAccountId:   $accountB->id,
            proposerTransferIds:     [$t2->id],
            counterpartyTransferIds: [$t1->id],
            proposedBy:              $userA->id,
        );

        $this->service->accept($proposal, $userB->id);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/non .* in attesa|scadut/i');

        $this->service->accept($proposal, $userB->id);
    }

    // ── reject() ─────────────────────────────────────────────────────────────

    public function test_reject_sets_status_rejected_and_leaves_transfers_intact(): void
    {
        $accountA = $this->makeAccount();
        $accountB = $this->makeAccount();
        $userA    = $this->makeUser($accountA);
        $userB    = $this->makeUser($accountB);

        $t1 = $this->makePendingTransfer($accountA, $accountB, 3000);
        $t2 = $this->makePendingTransfer($accountB, $accountA, 1000);

        $proposal = $this->service->propose(
            proposerAccountId:       $accountA->id,
            counterpartyAccountId:   $accountB->id,
            proposerTransferIds:     [$t2->id],
            counterpartyTransferIds: [$t1->id],
            proposedBy:              $userA->id,
        );

        $result = $this->service->reject($proposal, $userB->id);

        $this->assertSame('rejected', $result->status);

        // Original transfers still pending (not cancelled on rejection)
        $this->assertSame('pending', $t1->fresh()->status);
        $this->assertSame('pending', $t2->fresh()->status);

        // Audit log correct
        $log = AuditLog::where('event', 'netting.rejected')->first();
        $this->assertNotNull($log);
        $this->assertSame($userB->id, $log->actor_user_id);
    }

    public function test_reject_throws_when_proposal_not_pending(): void
    {
        $accountA = $this->makeAccount();
        $accountB = $this->makeAccount();
        $userA    = $this->makeUser($accountA);
        $userB    = $this->makeUser($accountB);

        $t1 = $this->makePendingTransfer($accountA, $accountB, 2000);

        $proposal = $this->service->propose(
            proposerAccountId:       $accountA->id,
            counterpartyAccountId:   $accountB->id,
            proposerTransferIds:     [],
            counterpartyTransferIds: [$t1->id],
            proposedBy:              $userA->id,
        );
        $this->service->reject($proposal, $userB->id);

        $this->expectException(\RuntimeException::class);

        $this->service->reject($proposal, $userB->id);
    }
}
