<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\PaymentRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class NfcPaymentControllerTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $company = Company::factory()->create(['kyc_status' => 'approved']);
        $account = Account::factory()->create([
            'company_id'  => $company->id,
            'owner_user_id' => $user->id,
            'status'      => 'active',
            'currency_code' => 'KY',
            'available_balance' => 50000,
        ]);
        $user->update(['two_factor_confirmed_at' => null]);
        return $user;
    }

    /** GET /incassa/nfc — form visibile */
    public function test_form_visible_to_authenticated_user(): void
    {
        $user = $this->makeUser();
        $response = $this->actingAs($user)->get(route('portal.incasso-nfc.form'));
        $response->assertStatus(200);
        $response->assertViewIs('portal.nfc-form');
    }

    /** POST /incassa/nfc — crea PaymentRequest e redirect a show */
    public function test_store_creates_payment_request(): void
    {
        Event::fake();
        $user = $this->makeUser();

        $response = $this->actingAs($user)->post(route('portal.incasso-nfc.store'), [
            'amount'      => 10,   // 10 KY → 1000 centesimi
            'description' => 'Test NFC',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('payment_requests', [
            'amount' => 1000,
            'kind'   => 'nfc',
            'status' => 'pending',
        ]);
    }

    /** POST /incassa/nfc — importo richiesto */
    public function test_store_validates_amount_required(): void
    {
        $user = $this->makeUser();
        $response = $this->actingAs($user)->post(route('portal.incasso-nfc.store'), []);
        $response->assertSessionHasErrors('amount');
    }

    /** POST /incassa/nfc — importo minimo 1 */
    public function test_store_validates_amount_minimum(): void
    {
        $user = $this->makeUser();
        $response = $this->actingAs($user)->post(route('portal.incasso-nfc.store'), ['amount' => 0]);
        $response->assertSessionHasErrors('amount');
    }

    /** GET /incassa/nfc/{token} — show pagina merchant */
    public function test_show_displays_nfc_page(): void
    {
        $user = $this->makeUser();
        $account = Account::where('owner_user_id', $user->id)->first();

        $pr = PaymentRequest::create([
            'token'         => 'tok-nfc-show',
            'to_account_id' => $account->id,
            'amount'        => 500,
            'kind'          => 'nfc',
            'status'        => 'pending',
            'expires_at'    => now()->addMinutes(5),
        ]);

        $response = $this->actingAs($user)->get(route('portal.incasso-nfc.show', $pr->token));
        $response->assertStatus(200);
        $response->assertViewIs('portal.nfc-show');
    }

    /** GET /incassa/nfc/{token}/stato — JSON status */
    public function test_status_returns_json(): void
    {
        $user = $this->makeUser();
        $account = Account::where('owner_user_id', $user->id)->first();

        $pr = PaymentRequest::create([
            'token'         => 'tok-nfc-status',
            'to_account_id' => $account->id,
            'amount'        => 300,
            'kind'          => 'nfc',
            'status'        => 'pending',
            'expires_at'    => now()->addMinutes(5),
        ]);

        $response = $this->actingAs($user)->getJson(route('portal.incasso-nfc.status', $pr->token));
        $response->assertOk()->assertJsonFragment(['status' => 'pending']);
    }

    /** POST /incassa/nfc/{token}/annulla — cancella la propria richiesta */
    public function test_cancel_own_request(): void
    {
        Event::fake();
        $user = $this->makeUser();
        $account = Account::where('owner_user_id', $user->id)->first();

        $pr = PaymentRequest::create([
            'uuid'          => (string) \Illuminate\Support\Str::uuid(),
            'token'         => 'tok-nfc-cancel',
            'to_account_id' => $account->id,
            'amount'        => 200,
            'kind'          => 'nfc',
            'status'        => 'pending',
            'expires_at'    => now()->addMinutes(5),
        ]);

        $response = $this->actingAs($user)->post(route('portal.incasso-nfc.cancel', $pr->token));
        $response->assertRedirect(route('portal.incasso-nfc.form'));
        $this->assertDatabaseHas('payment_requests', ['token' => 'tok-nfc-cancel', 'status' => 'cancelled']);
    }

    /** POST annulla — 403 se richiesta di un altro */
    public function test_cancel_others_request_forbidden(): void
    {
        $owner = $this->makeUser();
        $ownerAccount = Account::where('owner_user_id', $owner->id)->first();
        $intruder = $this->makeUser();

        $pr = PaymentRequest::create([
            'token'         => 'tok-nfc-other',
            'to_account_id' => $ownerAccount->id,
            'amount'        => 100,
            'kind'          => 'nfc',
            'status'        => 'pending',
            'expires_at'    => now()->addMinutes(5),
        ]);

        $response = $this->actingAs($intruder)->post(route('portal.incasso-nfc.cancel', $pr->token));
        $response->assertForbidden();
    }

    /** GET /incassa/nfc/{token}/stato — scaduta on-the-fly */
    public function test_status_auto_expires_past_deadline(): void
    {
        $user = $this->makeUser();
        $account = Account::where('owner_user_id', $user->id)->first();

        $pr = PaymentRequest::create([
            'token'         => 'tok-nfc-expired',
            'to_account_id' => $account->id,
            'amount'        => 100,
            'kind'          => 'nfc',
            'status'        => 'pending',
            'expires_at'    => now()->subMinutes(1),
        ]);

        $response = $this->actingAs($user)->getJson(route('portal.incasso-nfc.status', $pr->token));
        $response->assertOk()->assertJsonFragment(['status' => 'expired']);
    }
}
