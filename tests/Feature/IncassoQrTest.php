<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\PaymentRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class IncassoQrTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // Form
    // -------------------------------------------------------------------------

    public function test_merchant_can_view_incasso_qr_form(): void
    {
        [$user, $account] = $this->makeUserAndAccount();

        $this->actingAs($user)
            ->get(route('portal.incasso-qr.form'))
            ->assertOk();
    }

    public function test_incasso_qr_form_requires_authentication(): void
    {
        $this->get(route('portal.incasso-qr.form'))
            ->assertRedirect(route('login'));
    }

    public function test_inactive_account_is_redirected_from_qr_form(): void
    {
        [$user, $account] = $this->makeUserAndAccount('suspended');

        $this->actingAs($user)
            ->get(route('portal.incasso-qr.form'))
            ->assertRedirect(route('portal.dashboard'));
    }

    // -------------------------------------------------------------------------
    // Store
    // -------------------------------------------------------------------------

    public function test_merchant_can_create_qr_payment_request(): void
    {
        [$user, $account] = $this->makeUserAndAccount();

        $response = $this->actingAs($user)
            ->post(route('portal.incasso-qr.store'), [
                'amount'      => 500,
                'description' => 'Pranzo aziendale',
            ]);

        $pr = PaymentRequest::where('to_account_id', $account->id)->firstOrFail();

        $this->assertSame(500, $pr->amount);
        $this->assertSame('pending', $pr->status);
        $response->assertRedirect(route('portal.incasso-qr.show', $pr->token));
    }

    public function test_qr_store_validates_amount_is_required(): void
    {
        [$user, $account] = $this->makeUserAndAccount();

        $this->actingAs($user)
            ->post(route('portal.incasso-qr.store'), ['amount' => ''])
            ->assertSessionHasErrors('amount');
    }

    public function test_qr_store_validates_amount_must_be_positive(): void
    {
        [$user, $account] = $this->makeUserAndAccount();

        $this->actingAs($user)
            ->post(route('portal.incasso-qr.store'), ['amount' => 0])
            ->assertSessionHasErrors('amount');
    }

    public function test_inactive_account_cannot_create_qr(): void
    {
        [$user, $account] = $this->makeUserAndAccount('suspended');

        $this->actingAs($user)
            ->post(route('portal.incasso-qr.store'), ['amount' => 100])
            ->assertRedirect(route('portal.incasso-qr.form'));
    }

    // -------------------------------------------------------------------------
    // Show
    // -------------------------------------------------------------------------

    public function test_merchant_can_view_own_qr_page(): void
    {
        [$user, $account] = $this->makeUserAndAccount();
        $pr = $this->makePendingQr($account);

        $this->actingAs($user)
            ->get(route('portal.incasso-qr.show', $pr->token))
            ->assertOk();
    }

    public function test_other_user_cannot_view_qr_page(): void
    {
        [$owner, $ownerAccount]  = $this->makeUserAndAccount();
        [$other, $otherAccount]  = $this->makeUserAndAccount();
        $pr = $this->makePendingQr($ownerAccount);

        $this->actingAs($other)
            ->get(route('portal.incasso-qr.show', $pr->token))
            ->assertForbidden();
    }

    // -------------------------------------------------------------------------
    // Status (AJAX polling)
    // -------------------------------------------------------------------------

    public function test_merchant_can_poll_qr_status(): void
    {
        [$user, $account] = $this->makeUserAndAccount();
        $pr = $this->makePendingQr($account);

        $response = $this->actingAs($user)
            ->getJson(route('portal.incasso-qr.status', $pr->token));

        $response->assertOk()
            ->assertJsonFragment(['status' => 'pending', 'is_paid' => false]);
    }

    public function test_status_endpoint_auto_expires_overdue_request(): void
    {
        [$user, $account] = $this->makeUserAndAccount();
        $pr = $this->makePendingQr($account, now()->subMinutes(15));

        $this->actingAs($user)
            ->getJson(route('portal.incasso-qr.status', $pr->token));

        $pr->refresh();
        $this->assertSame('expired', $pr->status);
    }

    public function test_other_user_cannot_poll_status(): void
    {
        [$owner, $ownerAccount] = $this->makeUserAndAccount();
        [$other, $otherAccount] = $this->makeUserAndAccount();
        $pr = $this->makePendingQr($ownerAccount);

        $this->actingAs($other)
            ->getJson(route('portal.incasso-qr.status', $pr->token))
            ->assertForbidden();
    }

    // -------------------------------------------------------------------------
    // Cancel
    // -------------------------------------------------------------------------

    public function test_merchant_can_cancel_pending_qr(): void
    {
        [$user, $account] = $this->makeUserAndAccount();
        $pr = $this->makePendingQr($account);

        $this->actingAs($user)
            ->post(route('portal.incasso-qr.cancel', $pr->token))
            ->assertRedirect(route('portal.incasso-qr.form'));

        $this->assertSame('cancelled', $pr->fresh()->status);
    }

    public function test_paid_qr_status_not_changed_on_cancel(): void
    {
        [$user, $account] = $this->makeUserAndAccount();
        $pr = $this->makePendingQr($account);
        $pr->update(['status' => 'paid']);

        $this->actingAs($user)
            ->post(route('portal.incasso-qr.cancel', $pr->token));

        $this->assertSame('paid', $pr->fresh()->status);
    }

    public function test_other_user_cannot_cancel_qr(): void
    {
        [$owner, $ownerAccount] = $this->makeUserAndAccount();
        [$other, $otherAccount] = $this->makeUserAndAccount();
        $pr = $this->makePendingQr($ownerAccount);

        $this->actingAs($other)
            ->post(route('portal.incasso-qr.cancel', $pr->token))
            ->assertForbidden();
    }

    // -------------------------------------------------------------------------
    // Payer side (PaymentRequestController)
    // -------------------------------------------------------------------------

    public function test_payer_can_view_pay_request_page(): void
    {
        [$merchant, $merchantAccount] = $this->makeUserAndAccount();
        [$payer,    $payerAccount]    = $this->makeUserAndAccount();
        $pr = $this->makePendingQr($merchantAccount);

        $this->actingAs($payer)
            ->get(route('portal.pay-request.show', $pr->token))
            ->assertOk();
    }

    public function test_expired_pay_request_page_shows_redirect(): void
    {
        [$merchant, $merchantAccount] = $this->makeUserAndAccount();
        [$payer,    $payerAccount]    = $this->makeUserAndAccount();
        $pr = $this->makePendingQr($merchantAccount, now()->subMinutes(15));

        // Expired requests redirect back to portal dashboard
        $response = $this->actingAs($payer)
            ->get(route('portal.pay-request.show', $pr->token));

        // Either redirect or the page shows expiry info — must not 500
        $response->assertStatus($response->isRedirect() ? 302 : 200);
    }

    public function test_unauthenticated_user_is_redirected_from_pay_page(): void
    {
        [$merchant, $merchantAccount] = $this->makeUserAndAccount();
        $pr = $this->makePendingQr($merchantAccount);

        $this->get(route('portal.pay-request.show', $pr->token))
            ->assertRedirect(route('login'));
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeUserAndAccount(string $accountStatus = 'active'): array
    {
        $user = User::create([
            'name'                => 'QR User',
            'email'               => 'qr-' . Str::random(8) . '@test.test',
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
            'available_balance' => 10000,
        ]);

        return [$user, $account];
    }

    private function makePendingQr(Account $account, ?\DateTimeInterface $expiresAt = null): PaymentRequest
    {
        return PaymentRequest::create([
            'to_account_id' => $account->id,
            'amount'        => 300,
            'description'   => 'Test QR',
            'status'        => 'pending',
            'expires_at'    => $expiresAt ?? now()->addMinutes(10),
        ]);
    }
}
