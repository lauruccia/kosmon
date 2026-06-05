<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\NettingProposal;
use App\Models\Transfer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Test HTTP layer NettingController.
 * Il service layer (propose/accept/reject) e' gia' coperto da NettingServiceTest.
 * Qui verifichiamo: accesso, ownership, redirect e stato DB post-azione.
 */
class NettingControllerTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // Index
    // -------------------------------------------------------------------------

    public function test_user_can_view_netting_index(): void
    {
        [$user, $account] = $this->makeUserAndAccount();

        $this->actingAs($user)
            ->get(route('portal.netting.index'))
            ->assertOk();
    }

    public function test_netting_index_requires_authentication(): void
    {
        $this->get(route('portal.netting.index'))
            ->assertRedirect(route('login'));
    }

    // -------------------------------------------------------------------------
    // Create
    // -------------------------------------------------------------------------

    public function test_user_can_view_netting_create(): void
    {
        [$user, $account] = $this->makeUserAndAccount();

        $this->actingAs($user)
            ->get(route('portal.netting.create'))
            ->assertOk();
    }

    // -------------------------------------------------------------------------
    // Show
    // -------------------------------------------------------------------------

    public function test_proposer_can_view_proposal(): void
    {
        [$proposerUser, $proposerAccount]       = $this->makeUserAndAccount();
        [$counterpartyUser, $counterpartyAccount] = $this->makeUserAndAccount();
        $proposal = $this->makeProposal($proposerAccount, $counterpartyAccount);

        $this->actingAs($proposerUser)
            ->get(route('portal.netting.show', $proposal))
            ->assertOk();
    }

    public function test_counterparty_can_view_proposal(): void
    {
        [$proposerUser, $proposerAccount]       = $this->makeUserAndAccount();
        [$counterpartyUser, $counterpartyAccount] = $this->makeUserAndAccount();
        $proposal = $this->makeProposal($proposerAccount, $counterpartyAccount);

        $this->actingAs($counterpartyUser)
            ->get(route('portal.netting.show', $proposal))
            ->assertOk();
    }

    public function test_third_party_cannot_view_proposal(): void
    {
        [$proposerUser, $proposerAccount]       = $this->makeUserAndAccount();
        [$counterpartyUser, $counterpartyAccount] = $this->makeUserAndAccount();
        [$otherUser, $otherAccount]              = $this->makeUserAndAccount();
        $proposal = $this->makeProposal($proposerAccount, $counterpartyAccount);

        $this->actingAs($otherUser)
            ->get(route('portal.netting.show', $proposal))
            ->assertForbidden();
    }

    // -------------------------------------------------------------------------
    // Accept
    // -------------------------------------------------------------------------

    public function test_counterparty_can_accept_proposal(): void
    {
        [$proposerUser, $proposerAccount]       = $this->makeUserAndAccount(5000);
        [$counterpartyUser, $counterpartyAccount] = $this->makeUserAndAccount(5000);
        $proposal = $this->makeProposal($proposerAccount, $counterpartyAccount);

        $this->actingAs($counterpartyUser)
            ->post(route('portal.netting.accept', $proposal))
            ->assertRedirect(route('portal.netting.show', $proposal));

        $proposal->refresh();
        $this->assertSame('accepted', $proposal->status);
    }

    public function test_proposer_cannot_accept_own_proposal(): void
    {
        [$proposerUser, $proposerAccount]       = $this->makeUserAndAccount(5000);
        [$counterpartyUser, $counterpartyAccount] = $this->makeUserAndAccount(5000);
        $proposal = $this->makeProposal($proposerAccount, $counterpartyAccount);

        $this->actingAs($proposerUser)
            ->post(route('portal.netting.accept', $proposal))
            ->assertForbidden();

        $this->assertSame('pending', $proposal->fresh()->status);
    }

    public function test_third_party_cannot_accept_proposal(): void
    {
        [$proposerUser, $proposerAccount]       = $this->makeUserAndAccount(5000);
        [$counterpartyUser, $counterpartyAccount] = $this->makeUserAndAccount(5000);
        [$otherUser, $otherAccount]              = $this->makeUserAndAccount(5000);
        $proposal = $this->makeProposal($proposerAccount, $counterpartyAccount);

        $this->actingAs($otherUser)
            ->post(route('portal.netting.accept', $proposal))
            ->assertForbidden();
    }

    public function test_already_accepted_proposal_cannot_be_accepted_again(): void
    {
        [$proposerUser, $proposerAccount]       = $this->makeUserAndAccount(5000);
        [$counterpartyUser, $counterpartyAccount] = $this->makeUserAndAccount(5000);
        $proposal = $this->makeProposal($proposerAccount, $counterpartyAccount);
        $proposal->update(['status' => 'accepted']);

        $response = $this->actingAs($counterpartyUser)
            ->post(route('portal.netting.accept', $proposal));

        // Deve ritornare 422 (abort_unless isPending())
        $response->assertStatus(422);
    }

    // -------------------------------------------------------------------------
    // Reject
    // -------------------------------------------------------------------------

    public function test_counterparty_can_reject_proposal(): void
    {
        [$proposerUser, $proposerAccount]       = $this->makeUserAndAccount(5000);
        [$counterpartyUser, $counterpartyAccount] = $this->makeUserAndAccount(5000);
        $proposal = $this->makeProposal($proposerAccount, $counterpartyAccount);

        $this->actingAs($counterpartyUser)
            ->post(route('portal.netting.reject', $proposal))
            ->assertRedirect(route('portal.netting.show', $proposal));

        $this->assertSame('rejected', $proposal->fresh()->status);
    }

    public function test_proposer_cannot_reject_own_proposal(): void
    {
        [$proposerUser, $proposerAccount]       = $this->makeUserAndAccount(5000);
        [$counterpartyUser, $counterpartyAccount] = $this->makeUserAndAccount(5000);
        $proposal = $this->makeProposal($proposerAccount, $counterpartyAccount);

        $this->actingAs($proposerUser)
            ->post(route('portal.netting.reject', $proposal))
            ->assertForbidden();

        $this->assertSame('pending', $proposal->fresh()->status);
    }

    // -------------------------------------------------------------------------
    // Load transfers (AJAX)
    // -------------------------------------------------------------------------

    public function test_load_transfers_returns_json(): void
    {
        [$user, $account]     = $this->makeUserAndAccount(5000);
        [$other, $otherAccount] = $this->makeUserAndAccount(5000);

        $this->actingAs($user)
            ->getJson(route('portal.netting.load-transfers', [
                'counterparty_account_id' => $otherAccount->id,
            ]))
            ->assertOk()
            ->assertJsonStructure(['proposer', 'counterparty']);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeUserAndAccount(int $balance = 0): array
    {
        $user = User::create([
            'name'                => 'Netting User',
            'email'               => 'netting-' . Str::random(8) . '@test.test',
            'password'            => 'secret123',
            'account_holder_type' => 'private',
            'company_id'          => null,
            'is_active'           => true,
            'is_super_admin'      => false,
            'email_verified_at'   => now(),
            'contract_signed_at'  => now(),
        ]);

        $account = Account::create([
            'owner_user_id'      => $user->id,
            'owner_type'         => 'private',
            'type'               => 'member',
            'status'             => 'active',
            'available_balance'  => $balance,
            'allow_negative_balance' => true,
        ]);

        return [$user, $account];
    }

    /**
     * Crea una NettingProposal pending con un Transfer pendente su ciascun lato,
     * in modo che il NettingService possa calcolare il net senza errori.
     */
    private function makeProposal(Account $proposerAccount, Account $counterpartyAccount): NettingProposal
    {
        // Transfer: proposer ha un credito verso counterparty (pending)
        $t1 = Transfer::create([
            'from_account_id' => $counterpartyAccount->id,
            'to_account_id'   => $proposerAccount->id,
            'amount'          => 1000,
            'currency_code'   => 'KY',
            'status'          => 'pending',
            'kind'            => 'test',
            'reference'       => 'REF-' . Str::random(6),
        ]);

        // Transfer: counterparty ha un credito verso proposer (pending)
        $t2 = Transfer::create([
            'from_account_id' => $proposerAccount->id,
            'to_account_id'   => $counterpartyAccount->id,
            'amount'          => 600,
            'currency_code'   => 'KY',
            'status'          => 'pending',
            'kind'            => 'test',
            'reference'       => 'REF-' . Str::random(6),
        ]);

        return NettingProposal::create([
            'proposer_account_id'       => $proposerAccount->id,
            'counterparty_account_id'   => $counterpartyAccount->id,
            'proposer_transfer_ids'     => [$t1->id],
            'counterparty_transfer_ids' => [$t2->id],
            'proposer_total'            => 1000,
            'counterparty_total'        => 600,
            'currency_code'             => 'KY',
            'net_amount'                => 400,
            'net_payer_account_id'      => $counterpartyAccount->id,
            'status'                    => 'pending',
            'proposed_by'               => $proposerAccount->owner_user_id,
            'expires_at'                => now()->addDays(7),
        ]);
    }
}
