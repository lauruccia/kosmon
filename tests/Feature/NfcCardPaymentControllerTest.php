<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\NfcCard;
use App\Models\NfcCardAuthSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

class NfcCardPaymentControllerTest extends TestCase
{
    use RefreshDatabase;

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function makeCompanyUser(): array
    {
        $company = Company::create([
            'name'          => 'Merchant ' . Str::random(4),
            'slug'          => 'merchant-' . Str::random(4),
            'email'         => Str::random(5) . '@test.it',
            'status'        => 'active',
            'kyc_status'    => 'approved',
            'currency_code' => 'KY',
            'sector'        => 'Test',
            'description'   => 'Azienda test',
        ]);
        $user = User::create([
            'name'                => 'User ' . Str::random(4),
            'email'               => Str::random(5) . '@test.it',
            'password'            => Hash::make('password'),
            'account_holder_type' => 'company',
            'company_id'          => $company->id,
            'role'                => 'owner',
            'is_active'           => true,
            'contract_signed_at'  => now(),
            'email_verified_at'   => now(),
            'payment_pin_hash'    => Hash::make('123456'),
        ]);

        $account = Account::create([
            'company_id'        => $company->id,
            'owner_user_id'     => $user->id,
            'currency_code'     => 'KY',
            'status'            => 'active',
            'available_balance' => 100000,
        ]);
        return compact('company', 'user', 'account');
    }

    private function makeActiveCard(Company $company, User $admin): NfcCard
    {
        $uuid = (string) Str::uuid();
        return NfcCard::create([
            'company_id'    => $company->id,
            'issued_by'     => $admin->id,
            'serial_number' => NfcCard::generateSerial(),
            'uuid'          => $uuid,
            'status'        => 'active',
            'pin_hash'      => Hash::make('1234'),
            'pin_attempts'  => 0,
            'nfc_payload'   => NfcCard::buildPayload($uuid),
        ]);
    }

    private function makeSig(string $uuid): string
    {
        $sig = hash_hmac('sha256', $uuid, config('app.nfc_card_secret', config('app.key')));
        return substr($sig, 0, 16);
    }

    // ─── identify ────────────────────────────────────────────────────────────

    public function test_identify_rejects_invalid_hmac(): void
    {
        ['user' => $merchant] = $this->makeCompanyUser();

        $this->actingAs($merchant)
            ->postJson(route('nfc.card.identify'), [
                'uuid' => Str::uuid(),
                'sig'  => 'invalidsig1234567',
            ])
            ->assertStatus(422)
            ->assertJsonFragment(['error' => 'Firma card non valida.']);
    }

    public function test_identify_rejects_unknown_card(): void
    {
        ['user' => $merchant] = $this->makeCompanyUser();

        $uuid = (string) Str::uuid();
        $sig  = $this->makeSig($uuid);

        $this->actingAs($merchant)
            ->postJson(route('nfc.card.identify'), compact('uuid', 'sig'))
            ->assertStatus(404);
    }

    public function test_identify_returns_card_info(): void
    {
        ['company' => $company, 'user' => $merchant] = $this->makeCompanyUser();
        $card = $this->makeActiveCard($company, $merchant);
        $sig  = $this->makeSig($card->uuid);

        $response = $this->actingAs($merchant)
            ->postJson(route('nfc.card.identify'), ['uuid' => $card->uuid, 'sig' => $sig]);

        $response->assertOk()
            ->assertJsonFragment(['card_uuid' => $card->uuid]);
    }

    public function test_identify_rejects_inactive_card(): void
    {
        ['company' => $company, 'user' => $merchant] = $this->makeCompanyUser();
        $uuid = (string) Str::uuid();
        $card = NfcCard::create([
            'company_id'    => $company->id,
            'issued_by'     => $merchant->id,
            'serial_number' => NfcCard::generateSerial(),
            'uuid'          => $uuid,
            'status'        => 'pending',
            'nfc_payload'   => NfcCard::buildPayload($uuid),
        ]);
        $sig = $this->makeSig($uuid);

        $this->actingAs($merchant)
            ->postJson(route('nfc.card.identify'), ['uuid' => $uuid, 'sig' => $sig])
            ->assertStatus(403);
    }

    // ─── createRequest ───────────────────────────────────────────────────────

    public function test_create_request_creates_session_and_notifies(): void
    {
        Notification::fake();

        ['company' => $customerCompany, 'user' => $customer] = $this->makeCompanyUser();
        ['user' => $merchant] = $this->makeCompanyUser();

        $card = $this->makeActiveCard($customerCompany, $merchant);

        $response = $this->actingAs($merchant)
            ->postJson(route('nfc.card.request'), [
                'card_uuid'   => $card->uuid,
                'amount'      => 10,   // 10 KY
                'description' => 'Test NFC card',
            ]);

        $response->assertOk()
            ->assertJsonStructure(['nonce', 'expires_at', 'status_url']);

        $this->assertDatabaseHas('nfc_card_auth_sessions', [
            'nfc_card_id' => $card->id,
            'amount'      => 1000,
            'status'      => 'pending',
        ]);

        Notification::assertSentTo(
            $customer,
            \App\Notifications\NfcCardPinRequestNotification::class
        );
    }

    /** Sotto soglia PIN → pagamento contactless immediato, senza conferma del titolare. */
    public function test_create_request_below_threshold_books_immediately(): void
    {
        Notification::fake();

        ['company' => $customerCompany, 'user' => $customer, 'account' => $customerAccount] = $this->makeCompanyUser();
        ['user' => $merchant, 'account' => $merchantAccount] = $this->makeCompanyUser();

        $card = $this->makeActiveCard($customerCompany, $merchant);
        $card->update(['pin_threshold' => 100]); // soglia 1,00 KY

        $response = $this->actingAs($merchant)
            ->postJson(route('nfc.card.request'), [
                'card_uuid'   => $card->uuid,
                'amount'      => '0.50', // 0,50 KY < soglia
                'description' => 'Contactless test',
            ]);

        $response->assertOk()
            ->assertJsonFragment(['status' => 'authorized'])
            ->assertJsonStructure(['nonce', 'transfer_uuid', 'status_url']);

        $this->assertDatabaseHas('nfc_card_auth_sessions', [
            'nfc_card_id' => $card->id,
            'amount'      => 50,
            'status'      => 'authorized',
        ]);
        $this->assertDatabaseHas('transfers', [
            'from_account_id' => $customerAccount->id,
            'to_account_id'   => $merchantAccount->id,
            'amount'          => 50,
            'kind'            => 'nfc_card',
        ]);

        // Contatori card aggiornati
        $this->assertSame(50, $card->fresh()->daily_spent);

        // Nessuna notifica di richiesta conferma inviata
        Notification::assertNotSentTo($customer, \App\Notifications\NfcCardPinRequestNotification::class);
    }

    /** Importo pari o sopra soglia → flusso normale con conferma (sessione pending). */
    public function test_create_request_at_threshold_uses_confirmation_flow(): void
    {
        Notification::fake();

        ['company' => $customerCompany, 'user' => $customer] = $this->makeCompanyUser();
        ['user' => $merchant] = $this->makeCompanyUser();

        $card = $this->makeActiveCard($customerCompany, $merchant);
        $card->update(['pin_threshold' => 100]); // soglia 1,00 KY

        $this->actingAs($merchant)
            ->postJson(route('nfc.card.request'), [
                'card_uuid' => $card->uuid,
                'amount'    => '1', // 1,00 KY = soglia → conferma richiesta
            ])
            ->assertOk();

        $this->assertDatabaseHas('nfc_card_auth_sessions', [
            'nfc_card_id' => $card->id,
            'amount'      => 100,
            'status'      => 'pending',
        ]);
        $this->assertDatabaseMissing('transfers', ['kind' => 'nfc_card', 'amount' => 100]);

        Notification::assertSentTo($customer, \App\Notifications\NfcCardPinRequestNotification::class);
    }

    public function test_create_request_fails_for_inactive_card(): void
    {
        ['company' => $customerCompany, 'user' => $merchant] = $this->makeCompanyUser();
        $uuid = (string) Str::uuid();
        $card = NfcCard::create([
            'company_id'    => $customerCompany->id,
            'issued_by'     => $merchant->id,
            'serial_number' => NfcCard::generateSerial(),
            'uuid'          => $uuid,
            'status'        => 'blocked',
            'nfc_payload'   => NfcCard::buildPayload($uuid),
        ]);

        $this->actingAs($merchant)
            ->postJson(route('nfc.card.request'), [
                'card_uuid' => $card->uuid,
                'amount'    => 5,
            ])
            ->assertStatus(403);
    }

    // ─── status ──────────────────────────────────────────────────────────────

    public function test_status_returns_pending(): void
    {
        ['company' => $company, 'user' => $merchant, 'account' => $merchantAccount] = $this->makeCompanyUser();
        ['company' => $customerCompany] = $this->makeCompanyUser();
        $card = $this->makeActiveCard($customerCompany, $merchant);

        $session = NfcCardAuthSession::create([
            'nfc_card_id'         => $card->id,
            'merchant_company_id' => $company->id,
            'amount'              => 500,
            'status'              => 'pending',
            'expires_at'          => now()->addMinutes(10),
        ]);

        $this->actingAs($merchant)
            ->getJson(route('nfc.card.status', $session->nonce))
            ->assertOk()
            ->assertJsonFragment(['status' => 'pending', 'is_authorized' => false]);
    }

    public function test_status_auto_expires_past_deadline(): void
    {
        ['company' => $company, 'user' => $merchant] = $this->makeCompanyUser();
        ['company' => $customerCompany] = $this->makeCompanyUser();
        $card = $this->makeActiveCard($customerCompany, $merchant);

        $session = NfcCardAuthSession::create([
            'nfc_card_id'         => $card->id,
            'merchant_company_id' => $company->id,
            'amount'              => 500,
            'status'              => 'pending',
            'expires_at'          => now()->subMinutes(1),
        ]);

        $this->actingAs($merchant)
            ->getJson(route('nfc.card.status', $session->nonce))
            ->assertOk()
            ->assertJsonFragment(['is_expired' => true]);

        $this->assertDatabaseHas('nfc_card_auth_sessions', [
            'nonce'  => $session->nonce,
            'status' => 'expired',
        ]);
    }

    // ─── authorize ───────────────────────────────────────────────────────────

    public function test_authorize_executes_transfer(): void
    {
        Notification::fake();

        ['company' => $merchantCompany, 'user' => $merchant, 'account' => $merchantAccount] = $this->makeCompanyUser();
        ['company' => $customerCompany, 'user' => $customer, 'account' => $customerAccount] = $this->makeCompanyUser();

        $card = $this->makeActiveCard($customerCompany, $merchant);

        $session = NfcCardAuthSession::create([
            'nfc_card_id'         => $card->id,
            'merchant_company_id' => $merchantCompany->id,
            'merchant_account_id' => $merchantAccount->id,
            'amount'              => 2000, // 20 KY
            'description'         => 'Test pagamento',
            'status'              => 'pending',
            'expires_at'          => now()->addMinutes(10),
        ]);

        $response = $this->actingAs($customer)
            ->post(route('nfc.card.authorize.post', $session->nonce), [
                'pin' => '1234',
            ]);

        $response->assertRedirect(route('portal.dashboard'));
        $this->assertDatabaseHas('nfc_card_auth_sessions', [
            'nonce'  => $session->nonce,
            'status' => 'authorized',
        ]);
        $this->assertDatabaseHas('transfers', [
            'from_account_id' => $customerAccount->id,
            'to_account_id'   => $merchantAccount->id,
            'amount'          => 2000,
            'kind'            => 'nfc_card',
        ]);
    }

    /** Sotto soglia PIN → pagamento confermato senza PIN. */
    public function test_authorize_below_pin_threshold_skips_pin(): void
    {
        Notification::fake();

        ['company' => $merchantCompany, 'user' => $merchant, 'account' => $merchantAccount] = $this->makeCompanyUser();
        ['company' => $customerCompany, 'user' => $customer, 'account' => $customerAccount] = $this->makeCompanyUser();

        $card = $this->makeActiveCard($customerCompany, $merchant);
        $card->update(['pin_threshold' => 100]); // soglia 1,00 KY

        $session = NfcCardAuthSession::create([
            'nfc_card_id'         => $card->id,
            'merchant_company_id' => $merchantCompany->id,
            'merchant_account_id' => $merchantAccount->id,
            'amount'              => 1, // 0,01 KY < soglia
            'status'              => 'pending',
            'expires_at'          => now()->addMinutes(10),
        ]);

        $this->actingAs($customer)
            ->post(route('nfc.card.authorize.post', $session->nonce)) // nessun PIN
            ->assertRedirect(route('portal.dashboard'));

        $this->assertDatabaseHas('nfc_card_auth_sessions', [
            'nonce'  => $session->nonce,
            'status' => 'authorized',
        ]);
        $this->assertDatabaseHas('transfers', [
            'from_account_id' => $customerAccount->id,
            'to_account_id'   => $merchantAccount->id,
            'amount'          => 1,
            'kind'            => 'nfc_card',
        ]);
    }

    /** Importo pari alla soglia → PIN richiesto (>= soglia). */
    public function test_authorize_at_threshold_requires_pin(): void
    {
        Notification::fake();

        ['company' => $merchantCompany, 'user' => $merchant, 'account' => $merchantAccount] = $this->makeCompanyUser();
        ['company' => $customerCompany, 'user' => $customer] = $this->makeCompanyUser();

        $card = $this->makeActiveCard($customerCompany, $merchant);
        $card->update(['pin_threshold' => 100]); // soglia 1,00 KY

        $session = NfcCardAuthSession::create([
            'nfc_card_id'         => $card->id,
            'merchant_company_id' => $merchantCompany->id,
            'merchant_account_id' => $merchantAccount->id,
            'amount'              => 100, // 1,00 KY = soglia
            'status'              => 'pending',
            'expires_at'          => now()->addMinutes(10),
        ]);

        $this->actingAs($customer)
            ->post(route('nfc.card.authorize.post', $session->nonce)) // nessun PIN
            ->assertSessionHasErrors('pin');

        $this->assertDatabaseHas('nfc_card_auth_sessions', [
            'nonce'  => $session->nonce,
            'status' => 'pending',
        ]);
    }

    /** Soglia null → PIN sempre richiesto. */
    public function test_authorize_without_threshold_always_requires_pin(): void
    {
        Notification::fake();

        ['company' => $merchantCompany, 'user' => $merchant, 'account' => $merchantAccount] = $this->makeCompanyUser();
        ['company' => $customerCompany, 'user' => $customer] = $this->makeCompanyUser();

        $card = $this->makeActiveCard($customerCompany, $merchant); // pin_threshold null

        $session = NfcCardAuthSession::create([
            'nfc_card_id'         => $card->id,
            'merchant_company_id' => $merchantCompany->id,
            'merchant_account_id' => $merchantAccount->id,
            'amount'              => 1, // anche 0,01 KY
            'status'              => 'pending',
            'expires_at'          => now()->addMinutes(10),
        ]);

        $this->actingAs($customer)
            ->post(route('nfc.card.authorize.post', $session->nonce))
            ->assertSessionHasErrors('pin');
    }

    /** I limiti inseriti in KY vengono salvati in centesimi. */
    public function test_update_limits_converts_ky_to_cents(): void
    {
        ['company' => $company, 'user' => $user] = $this->makeCompanyUser();
        $card = $this->makeActiveCard($company, $user);

        $this->actingAs($user)
            ->post(route('portal.nfc-cards.limits', $card->uuid), [
                'limit_per_transaction' => '50',     // 50 KY
                'pin_threshold'         => '1',      // 1 KY
                'limit_daily'           => '100,50', // virgola italiana
                'limit_monthly'         => '',       // nessun limite
            ])
            ->assertRedirect();

        $card->refresh();
        $this->assertSame(5000, $card->limit_per_transaction);
        $this->assertSame(100, $card->pin_threshold);
        $this->assertSame(10050, $card->limit_daily);
        $this->assertNull($card->limit_monthly);
    }

    public function test_authorize_forbidden_for_wrong_user(): void
    {
        ['company' => $merchantCompany, 'user' => $merchant, 'account' => $merchantAccount] = $this->makeCompanyUser();
        ['company' => $customerCompany] = $this->makeCompanyUser();
        ['user' => $intruder] = $this->makeCompanyUser();

        $card = $this->makeActiveCard($customerCompany, $merchant);

        $session = NfcCardAuthSession::create([
            'nfc_card_id'         => $card->id,
            'merchant_company_id' => $merchantCompany->id,
            'merchant_account_id' => $merchantAccount->id,
            'amount'              => 500,
            'status'              => 'pending',
            'expires_at'          => now()->addMinutes(10),
        ]);

        $this->actingAs($intruder)
            ->post(route('nfc.card.authorize.post', $session->nonce))
            ->assertForbidden();
    }

}
