<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\PaymentRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PaymentLinkTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // Index
    // -------------------------------------------------------------------------

    public function test_authenticated_user_can_view_payment_links_list(): void
    {
        [$user, $account] = $this->makeUserAndAccount();

        $response = $this->actingAs($user)->get(route('portal.payment-links.index'));

        $response->assertOk();
    }

    public function test_payment_links_index_requires_authentication(): void
    {
        $response = $this->get(route('portal.payment-links.index'));

        $response->assertRedirect(route('login'));
    }

    // -------------------------------------------------------------------------
    // Create
    // -------------------------------------------------------------------------

    public function test_user_can_view_payment_link_creation_form(): void
    {
        [$user, $account] = $this->makeUserAndAccount();

        $response = $this->actingAs($user)->get(route('portal.payment-links.create'));

        $response->assertOk();
    }

    public function test_inactive_account_cannot_access_creation_form(): void
    {
        [$user, $account] = $this->makeUserAndAccount('suspended');

        $response = $this->actingAs($user)->get(route('portal.payment-links.create'));

        $response->assertRedirect(route('portal.payment-links.index'));
    }

    // -------------------------------------------------------------------------
    // Store
    // -------------------------------------------------------------------------

    public function test_user_can_create_a_payment_link(): void
    {
        [$user, $account] = $this->makeUserAndAccount();

        $response = $this->actingAs($user)->post(route('portal.payment-links.store'), [
            'amount'      => 15,   // 15 KY → 1500 centesimi
            'description' => 'Fattura #42',
            'expires_days' => 7,
        ]);

        $this->assertDatabaseHas('payment_requests', [
            'to_account_id' => $account->id,
            'amount'        => 1500,
            'kind'          => 'link',
            'status'        => 'pending',
        ]);

        $pr = PaymentRequest::where('to_account_id', $account->id)->firstOrFail();
        $response->assertRedirect(route('portal.payment-links.show', $pr->token));
    }

    public function test_store_validates_required_amount(): void
    {
        [$user, $account] = $this->makeUserAndAccount();

        $response = $this->actingAs($user)->post(route('portal.payment-links.store'), [
            'description'  => 'Test',
            'expires_days' => 7,
        ]);

        $response->assertSessionHasErrors('amount');
        $this->assertSame(0, PaymentRequest::count());
    }

    public function test_store_validates_minimum_amount(): void
    {
        [$user, $account] = $this->makeUserAndAccount();

        $response = $this->actingAs($user)->post(route('portal.payment-links.store'), [
            'amount'       => 0,
            'expires_days' => 7,
        ]);

        $response->assertSessionHasErrors('amount');
    }

    public function test_inactive_account_cannot_create_payment_link(): void
    {
        [$user, $account] = $this->makeUserAndAccount('suspended');

        $response = $this->actingAs($user)->post(route('portal.payment-links.store'), [
            'amount'       => 500,
            'expires_days' => 7,
        ]);

        $this->assertSame(0, PaymentRequest::count());
    }

    // -------------------------------------------------------------------------
    // Show
    // -------------------------------------------------------------------------

    public function test_creator_can_view_their_payment_link(): void
    {
        [$user, $account] = $this->makeUserAndAccount();
        $pr = $this->makePendingLink($account);

        $response = $this->actingAs($user)
            ->get(route('portal.payment-links.show', $pr->token));

        $response->assertOk();
        $response->assertSee($pr->token);
    }

    public function test_other_user_cannot_view_a_payment_link_they_do_not_own(): void
    {
        [$ownerUser, $ownerAccount] = $this->makeUserAndAccount();
        [$otherUser, $otherAccount] = $this->makeUserAndAccount();

        $pr = $this->makePendingLink($ownerAccount);

        $response = $this->actingAs($otherUser)
            ->get(route('portal.payment-links.show', $pr->token));

        $response->assertForbidden();
    }

    // -------------------------------------------------------------------------
    // Cancel
    // -------------------------------------------------------------------------

    public function test_user_can_cancel_a_pending_payment_link(): void
    {
        [$user, $account] = $this->makeUserAndAccount();
        $pr = $this->makePendingLink($account);

        $response = $this->actingAs($user)
            ->post(route('portal.payment-links.cancel', $pr->token));

        $response->assertRedirect(route('portal.payment-links.index'));

        $pr->refresh();
        $this->assertSame('cancelled', $pr->status);
    }

    public function test_user_cannot_cancel_a_paid_payment_link(): void
    {
        [$user, $account] = $this->makeUserAndAccount();
        $pr = $this->makePendingLink($account, 'paid');

        $this->actingAs($user)
            ->post(route('portal.payment-links.cancel', $pr->token));

        $pr->refresh();
        // Status should remain 'paid' — cancel() only acts on 'pending'
        $this->assertSame('paid', $pr->status);
    }

    public function test_other_user_cannot_cancel_someone_elses_payment_link(): void
    {
        [$ownerUser, $ownerAccount] = $this->makeUserAndAccount();
        [$otherUser, $otherAccount] = $this->makeUserAndAccount();

        $pr = $this->makePendingLink($ownerAccount);

        $response = $this->actingAs($otherUser)
            ->post(route('portal.payment-links.cancel', $pr->token));

        $response->assertForbidden();

        $pr->refresh();
        $this->assertSame('pending', $pr->status);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeUserAndAccount(string $accountStatus = 'active'): array
    {
        $user = User::create([
            'name'                => 'Test User',
            'email'               => 'user-' . Str::random(8) . '@test.test',
            'password'            => 'secret123',
            'account_holder_type' => 'private',
            'company_id'          => null,
            'is_active'           => true,
            'is_super_admin'      => false,
            'email_verified_at'   => now(),
            'contract_signed_at'  => now(),
        ]);

        $account = Account::create([
            'owner_user_id'     => $user->id,
            'owner_type'        => 'private',
            'type'              => 'member',
            'status'            => $accountStatus,
            'available_balance' => 0,
        ]);

        return [$user, $account];
    }

    private function makePendingLink(Account $account, string $status = 'pending'): PaymentRequest
    {
        return PaymentRequest::create([
            'to_account_id' => $account->id,
            'amount'        => 1000,
            'description'   => 'Test link',
            'kind'          => 'link',
            'status'        => $status,
            'expires_at'    => now()->addDays(7),
        ]);
    }
}
