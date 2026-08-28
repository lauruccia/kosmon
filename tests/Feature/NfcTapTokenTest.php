<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\NfcCard;
use App\Models\User;
use App\Support\NfcTapToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A10 — l'addebito su card NFC richiede la prova di un tap reale.
 *
 * Prima del 28/08/2026 la firma HMAC del chip veniva verificata solo da
 * POST /nfc/card/identify, mentre POST /nfc/card/request — quello che muove i
 * soldi — accettava il solo `card_uuid`. Il controllo viveva quindi soltanto nel
 * browser. E siccome la firma è statica, chi aveva letto la card una volta
 * poteva riaddebitare il titolare per sempre, senza avere la card in mano.
 *
 * Lo scenario che conta è test_un_tap_non_si_riusa_per_un_secondo_addebito.
 */
class NfcTapTokenTest extends TestCase
{
    use RefreshDatabase;

    private function makeCompanyUser(int $balance = 100000): array
    {
        $company = Company::create([
            'name'          => 'Soggetto ' . Str::random(4),
            'slug'          => 'soggetto-' . Str::random(6),
            'email'         => Str::random(6) . '@test.it',
            'status'        => 'active',
            'kyc_status'    => 'approved',
            'currency_code' => 'KY',
            'sector'        => 'Test',
            'description'   => 'Azienda test',
        ]);
        $user = User::create([
            'name'                => 'User ' . Str::random(4),
            'email'               => Str::random(6) . '@test.it',
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
            'available_balance' => $balance,
        ]);

        return compact('company', 'user', 'account');
    }

    private function makeActiveCard(Company $company, User $issuer, ?int $pinThreshold = null): NfcCard
    {
        $uuid = (string) Str::uuid();

        return NfcCard::create([
            'company_id'    => $company->id,
            'issued_by'     => $issuer->id,
            'serial_number' => NfcCard::generateSerial(),
            'uuid'          => $uuid,
            'status'        => 'active',
            'pin_hash'      => Hash::make('1234'),
            'pin_attempts'  => 0,
            'pin_threshold' => $pinThreshold,
            'nfc_payload'   => NfcCard::buildPayload($uuid),
        ]);
    }

    private function sig(string $uuid): string
    {
        return hash_hmac('sha256', $uuid, (config('app.nfc_card_secret') ?: config('app.key')));
    }

    // ─── identify emette il token ────────────────────────────────────────────

    public function test_identify_restituisce_un_tap_token(): void
    {
        ['company' => $customerCompany] = $this->makeCompanyUser();
        ['user' => $merchant] = $this->makeCompanyUser();
        $card = $this->makeActiveCard($customerCompany, $merchant);

        $response = $this->actingAs($merchant)
            ->postJson(route('nfc.card.identify'), ['uuid' => $card->uuid, 'sig' => $this->sig($card->uuid)]);

        $response->assertOk()->assertJsonStructure(['card_uuid', 'tap_token', 'tap_expires_in']);

        $this->assertTrue(
            NfcTapToken::isValid($response->json('tap_token'), $card->id, $merchant->id),
            'Il token restituito da identify deve valere per quella card e quel merchant.'
        );
    }

    /** Firma non valida → niente token, quindi niente addebito possibile. */
    public function test_identify_con_firma_sbagliata_non_emette_token(): void
    {
        ['company' => $customerCompany] = $this->makeCompanyUser();
        ['user' => $merchant] = $this->makeCompanyUser();
        $card = $this->makeActiveCard($customerCompany, $merchant);

        $this->actingAs($merchant)
            ->postJson(route('nfc.card.identify'), ['uuid' => $card->uuid, 'sig' => str_repeat('a', 64)])
            ->assertStatus(422)
            ->assertJsonMissingPath('tap_token');
    }

    /** Il giro completo: leggo la card, poi incasso. */
    public function test_identify_poi_request_funziona(): void
    {
        Notification::fake();

        ['company' => $customerCompany] = $this->makeCompanyUser();
        ['user' => $merchant] = $this->makeCompanyUser();
        $card = $this->makeActiveCard($customerCompany, $merchant);

        $tapToken = $this->actingAs($merchant)
            ->postJson(route('nfc.card.identify'), ['uuid' => $card->uuid, 'sig' => $this->sig($card->uuid)])
            ->json('tap_token');

        $this->actingAs($merchant)
            ->postJson(route('nfc.card.request'), [
                'card_uuid' => $card->uuid,
                'tap_token' => $tapToken,
                'amount'    => 10,
            ])
            ->assertOk()
            ->assertJsonStructure(['nonce', 'expires_at', 'status_url']);
    }

    // ─── request senza prova del tap ─────────────────────────────────────────

    /** Il buco originale: il solo UUID non deve bastare. */
    public function test_request_senza_tap_token_viene_rifiutata(): void
    {
        ['company' => $customerCompany] = $this->makeCompanyUser();
        ['user' => $merchant] = $this->makeCompanyUser();
        $card = $this->makeActiveCard($customerCompany, $merchant);

        $this->actingAs($merchant)
            ->postJson(route('nfc.card.request'), ['card_uuid' => $card->uuid, 'amount' => 10])
            ->assertStatus(422)
            ->assertJsonValidationErrors('tap_token');

        $this->assertDatabaseCount('nfc_card_auth_sessions', 0);
    }

    public function test_request_con_tap_token_inventato_viene_rifiutata(): void
    {
        ['company' => $customerCompany] = $this->makeCompanyUser();
        ['user' => $merchant] = $this->makeCompanyUser();
        $card = $this->makeActiveCard($customerCompany, $merchant);

        $this->actingAs($merchant)
            ->postJson(route('nfc.card.request'), [
                'card_uuid' => $card->uuid,
                'tap_token' => Str::random(48),
                'amount'    => 10,
            ])
            ->assertStatus(422)
            ->assertJsonFragment(['reason' => 'tap_required']);

        $this->assertDatabaseCount('nfc_card_auth_sessions', 0);
    }

    /** Ho avvicinato la card di Tizio, non posso incassare su quella di Caio. */
    public function test_il_tap_di_una_card_non_vale_per_un_altra(): void
    {
        ['company' => $companyA] = $this->makeCompanyUser();
        ['company' => $companyB] = $this->makeCompanyUser();
        ['user' => $merchant] = $this->makeCompanyUser();

        $cardA = $this->makeActiveCard($companyA, $merchant);
        $cardB = $this->makeActiveCard($companyB, $merchant);

        $this->actingAs($merchant)
            ->postJson(route('nfc.card.request'), [
                'card_uuid' => $cardB->uuid,
                'tap_token' => $this->tokenFor($cardA, $merchant),
                'amount'    => 10,
            ])
            ->assertStatus(422)
            ->assertJsonFragment(['reason' => 'tap_required']);
    }

    /** Il tap è del commerciante che ha avvicinato la card, non è cedibile. */
    public function test_il_tap_di_un_merchant_non_vale_per_un_altro(): void
    {
        ['company' => $customerCompany] = $this->makeCompanyUser();
        ['user' => $merchantUno] = $this->makeCompanyUser();
        ['user' => $merchantDue] = $this->makeCompanyUser();

        $card = $this->makeActiveCard($customerCompany, $merchantUno);

        $this->actingAs($merchantDue)
            ->postJson(route('nfc.card.request'), [
                'card_uuid' => $card->uuid,
                'tap_token' => $this->tokenFor($card, $merchantUno),
                'amount'    => 10,
            ])
            ->assertStatus(422)
            ->assertJsonFragment(['reason' => 'tap_required']);
    }

    public function test_il_tap_scade(): void
    {
        ['company' => $customerCompany] = $this->makeCompanyUser();
        ['user' => $merchant] = $this->makeCompanyUser();
        $card = $this->makeActiveCard($customerCompany, $merchant);

        $tapToken = $this->tokenFor($card, $merchant);

        $this->travel(NfcTapToken::TTL_SECONDS + 10)->seconds();

        $this->actingAs($merchant)
            ->postJson(route('nfc.card.request'), [
                'card_uuid' => $card->uuid,
                'tap_token' => $tapToken,
                'amount'    => 10,
            ])
            ->assertStatus(422)
            ->assertJsonFragment(['reason' => 'tap_required']);

        $this->travelBack();
    }

    // ─── Il caso che conta: il riaddebito da casa ────────────────────────────

    /**
     * Card sotto soglia PIN: il primo addebito parte senza conferma del titolare
     * (è il contactless). Rigiocare lo stesso tap non deve produrne un secondo.
     */
    public function test_un_tap_non_si_riusa_per_un_secondo_addebito(): void
    {
        Notification::fake();

        ['company' => $customerCompany, 'account' => $customerAccount] = $this->makeCompanyUser();
        ['user' => $merchant, 'account' => $merchantAccount] = $this->makeCompanyUser();

        $card = $this->makeActiveCard($customerCompany, $merchant, pinThreshold: 10000); // soglia 100 KY

        $tapToken = $this->tokenFor($card, $merchant);
        $saldoIniziale = $customerAccount->fresh()->available_balance;

        $this->actingAs($merchant)
            ->postJson(route('nfc.card.request'), [
                'card_uuid' => $card->uuid,
                'tap_token' => $tapToken,
                'amount'    => 10,
            ])
            ->assertOk()
            ->assertJsonFragment(['status' => 'authorized']);

        // Stesso token, secondo tentativo: la card non è più stata avvicinata.
        $this->actingAs($merchant)
            ->postJson(route('nfc.card.request'), [
                'card_uuid' => $card->uuid,
                'tap_token' => $tapToken,
                'amount'    => 10,
            ])
            ->assertStatus(422)
            ->assertJsonFragment(['reason' => 'tap_required']);

        $this->assertSame(1, \App\Models\Transfer::where('kind', 'nfc_card')->count());
        $this->assertSame(
            $saldoIniziale - 1000,
            $customerAccount->fresh()->available_balance,
            'Il secondo tentativo non deve aver mosso un solo centesimo.'
        );
        $this->assertSame(1000, $card->fresh()->daily_spent);
    }

    /** Anche il flusso con conferma brucia il tap: niente notifiche a raffica. */
    public function test_un_tap_non_si_riusa_per_una_seconda_richiesta(): void
    {
        Notification::fake();

        ['company' => $customerCompany] = $this->makeCompanyUser();
        ['user' => $merchant] = $this->makeCompanyUser();
        $card = $this->makeActiveCard($customerCompany, $merchant);

        $tapToken = $this->tokenFor($card, $merchant);

        $this->actingAs($merchant)
            ->postJson(route('nfc.card.request'), ['card_uuid' => $card->uuid, 'tap_token' => $tapToken, 'amount' => 10])
            ->assertOk();

        $this->actingAs($merchant)
            ->postJson(route('nfc.card.request'), ['card_uuid' => $card->uuid, 'tap_token' => $tapToken, 'amount' => 10])
            ->assertStatus(422);

        $this->assertDatabaseCount('nfc_card_auth_sessions', 1);
    }

    private function tokenFor(NfcCard $card, User $merchant): string
    {
        return NfcTapToken::issue($card->id, $merchant->id);
    }
}
