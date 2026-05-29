<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\TextPaymentRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Test HTTP layer TextPaymentRequestController.
 * Copre: index, create, store, show, approve, reject, cancel.
 */
class TextPaymentRequestControllerTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // Index
    // -------------------------------------------------------------------------

    public function test_index_requires_authentication(): void
    {
        $this->get(route('portal.text-requests.index'))
            ->assertRedirect(route('login'));
    }

    public function test_user_can_view_text_requests_index(): void
    {
        [$user] = $this->makeCompanyUser();

        $this->actingAs($user)
            ->get(route('portal.text-requests.index'))
            ->assertOk();
    }

    // -------------------------------------------------------------------------
    // Create
    // -------------------------------------------------------------------------

    public function test_user_can_view_create_form(): void
    {
        [$user] = $this->makeCompanyUser();

        $this->actingAs($user)
            ->get(route('portal.text-requests.create'))
            ->assertOk();
    }

    // -------------------------------------------------------------------------
    // Store
    // -------------------------------------------------------------------------

    public function test_creditor_can_create_text_request(): void
    {
        [$creditorUser, $creditorAccount] = $this->makeCompanyUser();
        [, $debtorAccount]                = $this->makeCompanyUser(5000);

        $response = $this->actingAs($creditorUser)
            ->post(route('portal.text-requests.store'), [
                'to_account_id' => $debtorAccount->id,
                'amount'        => 400,
                'causale'       => 'Fattura 2026/001',
            ]);

        $req = TextPaymentRequest::where('from_account_id', $creditorAccount->id)->first();
        $this->assertNotNull($req);
        $this->assertSame(400, $req->amount);
        $this->assertSame('pending', $req->status);

        $response->assertRedirect(route('portal.text-requests.show', $req));
    }

    public function test_store_validates_required_fields(): void
    {
        [$user] = $this->makeCompanyUser();

        $this->actingAs($user)
            ->post(route('portal.text-requests.store'), [])
            ->assertSessionHasErrors(['to_account_id', 'amount', 'causale']);
    }

    public function test_store_rejects_request_to_self(): void
    {
        [$user, $account] = $this->makeCompanyUser();

        $this->actingAs($user)
            ->post(route('portal.text-requests.store'), [
                'to_account_id' => $account->id,
                'amount'        => 100,
                'causale'       => 'Autocredito',
            ])
            ->assertStatus(422);
    }

    // -------------------------------------------------------------------------
    // Show
    // -------------------------------------------------------------------------

    public function test_creditor_can_view_request(): void
    {
        [$creditorUser, $creditorAccount] = $this->makeCompanyUser();
        [$debtorUser, $debtorAccount]     = $this->makeCompanyUser(5000);
        $req = $this->makeRequest($creditorUser, $creditorAccount, $debtorAccount);

        $this->actingAs($creditorUser)
            ->get(route('portal.text-requests.show', $req))
            ->assertOk();
    }

    public function test_debtor_can_view_request(): void
    {
        [$creditorUser, $creditorAccount] = $this->makeCompanyUser();
        [$debtorUser, $debtorAccount]     = $this->makeCompanyUser(5000);
        $req = $this->makeRequest($creditorUser, $creditorAccount, $debtorAccount);

        $this->actingAs($debtorUser)
            ->get(route('portal.text-requests.show', $req))
            ->assertOk();
    }

    public function test_third_party_cannot_view_request(): void
    {
        [$creditorUser, $creditorAccount] = $this->makeCompanyUser();
        [, $debtorAccount]                = $this->makeCompanyUser(5000);
        [$thirdUser]                      = $this->makeCompanyUser();
        $req = $this->makeRequest($creditorUser, $creditorAccount, $debtorAccount);

        $this->actingAs($thirdUser)
            ->get(route('portal.text-requests.show', $req))
            ->assertForbidden();
    }

    // -------------------------------------------------------------------------
    // Approve
    // -------------------------------------------------------------------------

    public function test_debtor_can_approve_request(): void
    {
        [$creditorUser, $creditorAccount] = $this->makeCompanyUser(0);
        [$debtorUser, $debtorAccount]     = $this->makeCompanyUser(5000);
        $req = $this->makeRequest($creditorUser, $creditorAccount, $debtorAccount);

        $response = $this->actingAs($debtorUser)
            ->post(route('portal.text-requests.approve', $req));

        $req->refresh();
        $this->assertSame('approved', $req->status);
        $this->assertNotNull($req->transfer_id);
        $response->assertRedirect(route('portal.text-requests.show', $req));
    }

    public function test_creditor_cannot_approve_own_request(): void
    {
        [$creditorUser, $creditorAccount] = $this->makeCompanyUser(0);
        [, $debtorAccount]                = $this->makeCompanyUser(5000);
        $req = $this->makeRequest($creditorUser, $creditorAccount, $debtorAccount);

        $this->actingAs($creditorUser)
            ->post(route('portal.text-requests.approve', $req))
            ->assertForbidden();

        $this->assertSame('pending', $req->fresh()->status);
    }

    public function test_already_approved_request_cannot_be_approved_again(): void
    {
        [$creditorUser, $creditorAccount] = $this->makeCompanyUser(0);
        [$debtorUser, $debtorAccount]     = $this->makeCompanyUser(5000);
        $req = $this->makeRequest($creditorUser, $creditorAccount, $debtorAccount);
        $req->update(['status' => 'approved']);

        $this->actingAs($debtorUser)
            ->post(route('portal.text-requests.approve', $req))
            ->assertStatus(422);
    }

    // -------------------------------------------------------------------------
    // Reject
    // -------------------------------------------------------------------------

    public function test_debtor_can_reject_request(): void
    {
        [$creditorUser, $creditorAccount] = $this->makeCompanyUser(0);
        [$debtorUser, $debtorAccount]     = $this->makeCompanyUser(5000);
        $req = $this->makeRequest($creditorUser, $creditorAccount, $debtorAccount);

        $this->actingAs($debtorUser)
            ->post(route('portal.text-requests.reject', $req), [
                'rejection_note' => 'Non dovuto',
            ])
            ->assertRedirect(route('portal.text-requests.index'));

        $this->assertSame('rejected', $req->fresh()->status);
    }

    public function test_creditor_cannot_reject_own_request(): void
    {
        [$creditorUser, $creditorAccount] = $this->makeCompanyUser(0);
        [, $debtorAccount]                = $this->makeCompanyUser(5000);
        $req = $this->makeRequest($creditorUser, $creditorAccount, $debtorAccount);

        $this->actingAs($creditorUser)
            ->post(route('portal.text-requests.reject', $req))
            ->assertForbidden();
    }

    // -------------------------------------------------------------------------
    // Cancel
    // -------------------------------------------------------------------------

    public function test_creditor_can_cancel_own_request(): void
    {
        [$creditorUser, $creditorAccount] = $this->makeCompanyUser(0);
        [, $debtorAccount]                = $this->makeCompanyUser(5000);
        $req = $this->makeRequest($creditorUser, $creditorAccount, $debtorAccount);

        $this->actingAs($creditorUser)
            ->post(route('portal.text-requests.cancel', $req))
            ->assertRedirect(route('portal.text-requests.index'));

        $this->assertSame('cancelled', $req->fresh()->status);
    }

    public function test_debtor_cannot_cancel_received_request(): void
    {
        [$creditorUser, $creditorAccount] = $this->makeCompanyUser(0);
        [$debtorUser, $debtorAccount]     = $this->makeCompanyUser(5000);
        $req = $this->makeRequest($creditorUser, $creditorAccount, $debtorAccount);

        $this->actingAs($debtorUser)
            ->post(route('portal.text-requests.cancel', $req))
            ->assertForbidden();
    }

    public function test_already_approved_request_cannot_be_cancelled(): void
    {
        [$creditorUser, $creditorAccount] = $this->makeCompanyUser(0);
        [, $debtorAccount]                = $this->makeCompanyUser(5000);
        $req = $this->makeRequest($creditorUser, $creditorAccount, $debtorAccount);
        $req->update(['status' => 'approved']);

        $this->actingAs($creditorUser)
            ->post(route('portal.text-requests.cancel', $req))
            ->assertStatus(422);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** @return array{User, Account, Company} */
    private function makeCompanyUser(int $balance = 0): array
    {
        $company = Company::create([
            'name'          => 'TRCo ' . Str::random(4),
            'slug'          => 'trco-' . Str::random(6),
            'email'         => 'tr-' . Str::random(6) . '@test.test',
            'status'        => 'active',
            'kyc_status'    => 'approved',
            'currency_code' => 'KY',
        ]);

        $user = User::create([
            'name'                => 'TR User',
            'email'               => 'truser-' . Str::random(8) . '@test.test',
            'password'            => 'secret123',
            'account_holder_type' => 'company',
            'company_id'          => $company->id,
            'is_active'           => true,
            'is_super_admin'      => false,
            'email_verified_at'   => now(),
        ]);

        $account = Account::create([
            'company_id'             => $company->id,
            'owner_user_id'          => $user->id,
            'owner_type'             => 'company',
            'type'                   => 'primary',
            'currency_code'          => 'KY',
            'status'                 => 'active',
            'available_balance'      => $balance,
            'allow_negative_balance' => false,
        ]);

        return [$user, $account, $company];
    }

    private function makeRequest(
        User $creator,
        Account $from,
        Account $to,
        array $overrides = [],
    ): TextPaymentRequest {
        return TextPaymentRequest::create(array_merge([
            'from_account_id' => $from->id,
            'to_account_id'   => $to->id,
            'amount'          => 500,
            'causale'         => 'Test richiesta',
            'status'          => 'pending',
            'created_by'      => $creator->id,
        ], $overrides));
    }
}
