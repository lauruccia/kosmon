<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\PaymentPlan;
use App\Models\PaymentPlanInstallment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Test HTTP layer del PaymentPlanController.
 * Il service layer e' gia' coperto da PaymentPlanServiceTest.
 * Qui verifichiamo: accesso, ownership, logica bilaterale approve/reject.
 */
class PaymentPlanControllerTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // Index
    // -------------------------------------------------------------------------

    public function test_user_can_view_payment_plans_index(): void
    {
        [$user, $account] = $this->makeUserAndAccount();

        $this->actingAs($user)
            ->get(route('portal.payment-plans.index'))
            ->assertOk();
    }

    public function test_index_requires_authentication(): void
    {
        $this->get(route('portal.payment-plans.index'))
            ->assertRedirect(route('login'));
    }

    // -------------------------------------------------------------------------
    // Create form
    // -------------------------------------------------------------------------

    public function test_user_can_view_create_form(): void
    {
        [$user, $account] = $this->makeUserAndAccount();

        $this->actingAs($user)
            ->get(route('portal.payment-plans.create'))
            ->assertOk();
    }

    // -------------------------------------------------------------------------
    // Store — debtor proposes (acquirente chiede rateale)
    // -------------------------------------------------------------------------

    public function test_debtor_initiator_can_create_plan(): void
    {
        [$debtorUser, $debtorAccount]     = $this->makeUserAndAccount();
        [$creditorUser, $creditorAccount] = $this->makeUserAndAccount();

        $response = $this->actingAs($debtorUser)->post(route('portal.payment-plans.store'), [
            'initiator_role'     => 'debtor',
            'counterparty_id'    => $creditorAccount->id,
            'total_amount'       => 3000,
            'installments_count' => 3,
            'frequency'          => 'monthly',
            'first_due_date'     => now()->addMonth()->format('Y-m-d'),
            'description'        => 'Fornitura hardware',
        ]);

        $plan = PaymentPlan::first();
        $this->assertNotNull($plan);
        $this->assertSame('pending_approval', $plan->status);
        // debtor = from_account
        $this->assertSame($debtorAccount->id, $plan->from_account_id);
        $this->assertSame($creditorAccount->id, $plan->to_account_id);

        $response->assertRedirect(route('portal.payment-plans.show', $plan));
    }

    public function test_creditor_initiator_inverts_from_to(): void
    {
        [$creditorUser, $creditorAccount] = $this->makeUserAndAccount();
        [$debtorUser, $debtorAccount]     = $this->makeUserAndAccount();

        $this->actingAs($creditorUser)->post(route('portal.payment-plans.store'), [
            'initiator_role'     => 'creditor',
            'counterparty_id'    => $debtorAccount->id,
            'total_amount'       => 2000,
            'installments_count' => 2,
            'frequency'          => 'monthly',
            'first_due_date'     => now()->addMonth()->format('Y-m-d'),
            'description'        => 'Offerta vendita rateale',
        ]);

        $plan = PaymentPlan::first();
        $this->assertNotNull($plan);
        // creditor propone: from = debtor (counterparty), to = creditor (proposer)
        $this->assertSame($debtorAccount->id, $plan->from_account_id);
        $this->assertSame($creditorAccount->id, $plan->to_account_id);
    }

    // -------------------------------------------------------------------------
    // Show
    // -------------------------------------------------------------------------

    public function test_both_parties_can_view_plan(): void
    {
        [$debtorUser, $debtorAccount]     = $this->makeUserAndAccount();
        [$creditorUser, $creditorAccount] = $this->makeUserAndAccount();
        $plan = $this->makePendingPlan($debtorAccount, $creditorAccount, 'debtor');

        $this->actingAs($debtorUser)
            ->get(route('portal.payment-plans.show', $plan))
            ->assertOk();

        $this->actingAs($creditorUser)
            ->get(route('portal.payment-plans.show', $plan))
            ->assertOk();
    }

    public function test_third_party_cannot_view_plan(): void
    {
        [$debtorUser, $debtorAccount]     = $this->makeUserAndAccount();
        [$creditorUser, $creditorAccount] = $this->makeUserAndAccount();
        [$otherUser, $otherAccount]       = $this->makeUserAndAccount();
        $plan = $this->makePendingPlan($debtorAccount, $creditorAccount, 'debtor');

        $this->actingAs($otherUser)
            ->get(route('portal.payment-plans.show', $plan))
            ->assertForbidden();
    }

    // -------------------------------------------------------------------------
    // Approve
    // -------------------------------------------------------------------------

    public function test_counterparty_creditor_can_approve_debtor_initiated_plan(): void
    {
        // initiator_role=debtor => counterparty = to_account (creditor) deve approvare
        [$debtorUser, $debtorAccount]     = $this->makeUserAndAccount();
        [$creditorUser, $creditorAccount] = $this->makeUserAndAccount();
        $plan = $this->makePendingPlan($debtorAccount, $creditorAccount, 'debtor');

        $this->actingAs($creditorUser)
            ->post(route('portal.payment-plans.approve', $plan))
            ->assertRedirect(route('portal.payment-plans.show', $plan));

        $this->assertSame('active', $plan->fresh()->status);
    }

    public function test_initiator_cannot_approve_own_plan(): void
    {
        [$debtorUser, $debtorAccount]     = $this->makeUserAndAccount();
        [$creditorUser, $creditorAccount] = $this->makeUserAndAccount();
        $plan = $this->makePendingPlan($debtorAccount, $creditorAccount, 'debtor');

        // debtorUser e' l'initiator (from_account) — non puo' approvare
        $this->actingAs($debtorUser)
            ->post(route('portal.payment-plans.approve', $plan))
            ->assertForbidden();

        $this->assertSame('pending_approval', $plan->fresh()->status);
    }

    public function test_third_party_cannot_approve_plan(): void
    {
        [$debtorUser, $debtorAccount]     = $this->makeUserAndAccount();
        [$creditorUser, $creditorAccount] = $this->makeUserAndAccount();
        [$otherUser, $otherAccount]       = $this->makeUserAndAccount();
        $plan = $this->makePendingPlan($debtorAccount, $creditorAccount, 'debtor');

        $this->actingAs($otherUser)
            ->post(route('portal.payment-plans.approve', $plan))
            ->assertForbidden();
    }

    // -------------------------------------------------------------------------
    // Reject
    // -------------------------------------------------------------------------

    public function test_counterparty_can_reject_plan(): void
    {
        [$debtorUser, $debtorAccount]     = $this->makeUserAndAccount();
        [$creditorUser, $creditorAccount] = $this->makeUserAndAccount();
        $plan = $this->makePendingPlan($debtorAccount, $creditorAccount, 'debtor');

        $this->actingAs($creditorUser)
            ->post(route('portal.payment-plans.reject', $plan))
            ->assertRedirect(route('portal.payment-plans.show', $plan));

        $this->assertSame('rejected', $plan->fresh()->status);
    }

    public function test_initiator_cannot_reject_own_plan(): void
    {
        [$debtorUser, $debtorAccount]     = $this->makeUserAndAccount();
        [$creditorUser, $creditorAccount] = $this->makeUserAndAccount();
        $plan = $this->makePendingPlan($debtorAccount, $creditorAccount, 'debtor');

        $this->actingAs($debtorUser)
            ->post(route('portal.payment-plans.reject', $plan))
            ->assertForbidden();
    }

    // -------------------------------------------------------------------------
    // Cancel
    // -------------------------------------------------------------------------

    public function test_initiator_can_cancel_pending_plan(): void
    {
        [$debtorUser, $debtorAccount]     = $this->makeUserAndAccount();
        [$creditorUser, $creditorAccount] = $this->makeUserAndAccount();
        $plan = $this->makePendingPlan($debtorAccount, $creditorAccount, 'debtor');

        $this->actingAs($debtorUser)
            ->post(route('portal.payment-plans.cancel', $plan))
            ->assertRedirect(route('portal.payment-plans.index'));

        $this->assertSame('cancelled', $plan->fresh()->status);
    }

    // -------------------------------------------------------------------------
    // Creditor-initiated plan — chi approva e' il debitore (from_account)
    // -------------------------------------------------------------------------

    public function test_debtor_counterparty_must_approve_creditor_initiated_plan(): void
    {
        // initiator_role=creditor => counterparty = from_account (debtor) deve approvare
        [$creditorUser, $creditorAccount] = $this->makeUserAndAccount();
        [$debtorUser, $debtorAccount]     = $this->makeUserAndAccount();
        // creditor propone: from=debtor, to=creditor
        $plan = $this->makePendingPlan($debtorAccount, $creditorAccount, 'creditor');

        $this->actingAs($debtorUser)
            ->post(route('portal.payment-plans.approve', $plan))
            ->assertRedirect(route('portal.payment-plans.show', $plan));

        $this->assertSame('active', $plan->fresh()->status);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeUserAndAccount(string $accountStatus = 'active'): array
    {
        $user = User::create([
            'name'                => 'Plan User',
            'email'               => 'plan-' . Str::random(8) . '@test.test',
            'password'            => 'secret123',
            'account_holder_type' => 'private',
            'company_id'          => null,
            'is_active'           => true,
            'is_super_admin'      => false,
            'email_verified_at'   => now(),
        ]);

        $account = Account::create([
            'owner_user_id'     => $user->id,
            'owner_type'        => 'private',
            'type'              => 'member',
            'status'            => $accountStatus,
            'available_balance' => 50000,
        ]);

        return [$user, $account];
    }

    /**
     * Crea un PaymentPlan in stato pending_approval con rate.
     * initiator_role=debtor  => from=debtorAccount, to=creditorAccount, counterparty=to
     * initiator_role=creditor => from=debtorAccount, to=creditorAccount, counterparty=from
     */
    private function makePendingPlan(Account $fromAccount, Account $toAccount, string $initiatorRole): PaymentPlan
    {
        $plan = PaymentPlan::create([
            'from_account_id'    => $fromAccount->id,
            'to_account_id'      => $toAccount->id,
            'total_amount'       => 3000,
            'installments_count' => 3,
            'frequency'          => 'monthly',
            'first_due_date'     => now()->addMonth()->toDateString(),
            'status'             => 'pending_approval',
            'initiator_role'     => $initiatorRole,
            'description'        => 'Piano test',
        ]);

        $instAmount = (int) round(3000 / 3);
        for ($i = 0; $i < 3; $i++) {
            PaymentPlanInstallment::create([
                'payment_plan_id'    => $plan->id,
                'installment_number' => $i + 1,
                'amount'             => $instAmount,
                'due_date'           => now()->addMonths($i + 1)->toDateString(),
                'status'             => 'pending',
            ]);
        }

        return $plan;
    }
}
