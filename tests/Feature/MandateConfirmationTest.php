<?php

namespace Tests\Feature;

use App\Jobs\SendClientWebhookJob;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\MandatePaymentRequest;
use App\Models\OAuthAccessToken;
use App\Models\PaymentMandate;
use App\Models\PaymentMandateCharge;
use App\Models\PaymentRequest;
use App\Models\Transfer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Il ramo della conferma — fase 2b di PIANO_SHOP_ESTERNO.md (§5).
 *
 * La 2a aveva stabilito una cosa sola ma importante: quando l'addebito in un
 * clic non si può fare, la risposta non è "no", è "chiediglielo". Restava però
 * una promessa senza seguito — il 402 diceva perché, non diceva dove.
 *
 * Questi test sorvegliano il seguito, e in particolare i tre punti in cui è
 * facile fare danni veri:
 *
 *  1. **due volte lo stesso ordine.** Fra la conferma a mano dell'utente e il
 *     retry automatico di kshop ci sono due strade che portano allo stesso
 *     movimento. Se non convergono, qualcuno paga due volte.
 *  2. **il permesso che cresce da solo.** L'elenco dei venditori autorizzati è
 *     la protezione che sostituisce il plafond: può crescere SOLO con un gesto
 *     dell'utente, e mai su un mandato che non è più vivo.
 *  3. **il link che gira.** Una conferma di acquisto non è un QR da esporre sul
 *     bancone: la paga il proprietario del mandato o nessuno.
 */
class MandateConfirmationTest extends TestCase
{
    use RefreshDatabase;

    private const CLIENT_ID = 'kshop-test-client';

    private const RETURN_URL = 'https://kosmoshop.test/checkout/ritorno';

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        config()->set('oauth.clients.kshop', [
            'name'          => 'Kosmoshop',
            'client_id'     => self::CLIENT_ID,
            'secret'        => 'segreto-di-prova-molto-lungo',
            'redirect_uris' => ['https://kosmoshop.test/oauth/callback', self::RETURN_URL],
            'scopes'        => ['profile', 'account.read', 'orders.write', 'mandate'],
            'webhook'       => ['url' => 'https://kosmoshop.test/webhook', 'secret' => 'segreto-webhook'],
        ]);
    }

    // =========================================================================
    // 1. Il 402 adesso dice anche DOVE
    // =========================================================================

    public function test_sopra_il_tetto_il_402_porta_il_link_di_conferma(): void
    {
        [$user, $account] = $this->buyer();
        [$seller]         = $this->seller();
        $mandate          = $this->mandate($user, $account, [$seller->uuid], cap: 5000);

        $risposta = $this->charge($user, $mandate, $seller, amount: 9000)
            ->assertStatus(402)
            ->assertJsonPath('status', 'confirmation_required')
            ->assertJsonPath('reason', 'amount_above_limit');

        $richiesta = PaymentRequest::query()->sole();

        $risposta->assertJsonPath('payment_request_uuid', $richiesta->uuid);
        $risposta->assertJsonPath('confirmation_url', $richiesta->payUrl());
        $this->assertNotNull($risposta->json('confirmation_expires_at'));

        $this->assertSame(PaymentRequest::KIND_KSHOP_ORDER, $richiesta->kind);
        $this->assertSame(9000, (int) $richiesta->amount);
        $this->assertSame($seller->id, (int) $richiesta->to_account_id);

        // Nessun KY si è mosso: il 402 è una domanda, non un addebito.
        $this->assertSame(100000, $account->fresh()->available_balance);
        $this->assertSame(0, $seller->fresh()->available_balance);
    }

    public function test_il_venditore_nuovo_produce_la_stessa_richiesta_di_conferma(): void
    {
        [$user, $account] = $this->buyer();
        [$seller]         = $this->seller();
        $mandate          = $this->mandate($user, $account, []);   // nessun venditore autorizzato

        $this->charge($user, $mandate, $seller, amount: 1000)
            ->assertStatus(402)
            ->assertJsonPath('reason', 'seller_not_authorized')
            ->assertJsonPath('payment_request_uuid', fn ($v) => is_string($v) && $v !== '');

        $this->assertDatabaseCount('mandate_payment_requests', 1);
    }

    public function test_la_scadenza_del_link_e_quella_configurata(): void
    {
        config()->set('oauth.mandate.confirmation_ttl_minutes', 30);

        [$user, $account] = $this->buyer();
        [$seller]         = $this->seller();
        $mandate          = $this->mandate($user, $account, [], cap: 5000);

        $this->charge($user, $mandate, $seller, amount: 1000)->assertStatus(402);

        $richiesta = PaymentRequest::query()->sole();

        $this->assertEqualsWithDelta(
            now()->addMinutes(30)->timestamp,
            $richiesta->expires_at->timestamp,
            5,
            'Il link di conferma deve vivere quanto dice la configurazione, non un tempo scelto altrove.'
        );
    }

    public function test_una_richiesta_che_non_sta_in_piedi_resta_un_422_senza_link(): void
    {
        [$user, $account] = $this->buyer();
        [$seller]         = $this->seller();
        $mandate          = $this->mandate($user, $account, [$seller->uuid]);

        $token = $this->tokenFor($user);

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson(route('api.v1.mandates.charge', $mandate->uuid), [
                'seller_account_number' => 'KYBNONESISTE0000',
                'amount'                => 1000,
                'idempotency_key'       => 'ordine-1',
            ])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'seller_unknown')
            ->assertJsonMissingPath('payment_request_uuid');

        $this->assertDatabaseCount('payment_requests', 0);
    }

    public function test_un_indirizzo_di_ritorno_non_autorizzato_viene_rifiutato(): void
    {
        [$user, $account] = $this->buyer();
        [$seller]         = $this->seller();
        $mandate          = $this->mandate($user, $account, [], cap: 5000);

        $this->charge($user, $mandate, $seller, amount: 1000, extra: [
            'return_url' => 'https://sito-di-un-altro.test/prendi-i-soldi',
        ])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'return_url_not_allowed');

        // E soprattutto: non è finito da nessuna parte.
        $this->assertDatabaseCount('payment_requests', 0);
        $this->assertDatabaseCount('mandate_payment_requests', 0);
    }

    public function test_un_indirizzo_di_ritorno_autorizzato_viene_conservato(): void
    {
        [$user, $account] = $this->buyer();
        [$seller]         = $this->seller();
        $mandate          = $this->mandate($user, $account, [], cap: 5000);

        $this->charge($user, $mandate, $seller, amount: 1000, extra: [
            'return_url' => self::RETURN_URL,
        ])->assertStatus(402);

        $this->assertSame(self::RETURN_URL, PaymentRequest::query()->sole()->return_url);
    }

    public function test_la_richiesta_di_conferma_lascia_traccia(): void
    {
        [$user, $account] = $this->buyer();
        [$seller]         = $this->seller();
        $mandate          = $this->mandate($user, $account, []);

        $this->charge($user, $mandate, $seller, amount: 1000)->assertStatus(402);

        $this->assertTrue(
            AuditLog::query()->where('event', 'mandate.confirmation_requested')->exists()
        );
    }

    // =========================================================================
    // 2. Un ordine, una richiesta
    // =========================================================================

    public function test_ritentare_lo_stesso_ordine_non_genera_un_secondo_link(): void
    {
        [$user, $account] = $this->buyer();
        [$seller]         = $this->seller();
        $mandate          = $this->mandate($user, $account, [], cap: 5000);

        $primo   = $this->charge($user, $mandate, $seller, amount: 1000)->assertStatus(402);
        $secondo = $this->charge($user, $mandate, $seller, amount: 1000)->assertStatus(402);

        $this->assertSame(
            $primo->json('payment_request_uuid'),
            $secondo->json('payment_request_uuid'),
            'Dieci retry di rete non devono diventare dieci link da pagare per lo stesso carrello.'
        );

        $this->assertDatabaseCount('payment_requests', 1);
        $this->assertDatabaseCount('mandate_payment_requests', 1);
    }

    public function test_un_ordine_diverso_ha_un_link_diverso(): void
    {
        [$user, $account] = $this->buyer();
        [$seller]         = $this->seller();
        $mandate          = $this->mandate($user, $account, [], cap: 5000);

        $uno = $this->charge($user, $mandate, $seller, amount: 1000, key: 'carrello-A')->assertStatus(402);
        $due = $this->charge($user, $mandate, $seller, amount: 2000, key: 'carrello-B')->assertStatus(402);

        $this->assertNotSame($uno->json('payment_request_uuid'), $due->json('payment_request_uuid'));
        $this->assertDatabaseCount('mandate_payment_requests', 2);
    }

    public function test_se_il_link_scade_lo_stesso_ordine_ne_riceve_uno_nuovo(): void
    {
        [$user, $account] = $this->buyer();
        [$seller]         = $this->seller();
        $mandate          = $this->mandate($user, $account, [], cap: 5000);

        $primo = $this->charge($user, $mandate, $seller, amount: 1000)->assertStatus(402);

        PaymentRequest::query()->sole()->forceFill([
            'status'     => 'expired',
            'expires_at' => now()->subMinute(),
        ])->save();

        $secondo = $this->charge($user, $mandate, $seller, amount: 1000)->assertStatus(402);

        $this->assertNotSame($primo->json('payment_request_uuid'), $secondo->json('payment_request_uuid'));

        // L'identità dell'ordine è la chiave di kshop, non il link: la riga
        // resta una sola e punta alla richiesta nuova.
        $this->assertDatabaseCount('mandate_payment_requests', 1);
        $this->assertDatabaseCount('payment_requests', 2);

        $riga = MandatePaymentRequest::query()->sole();
        $this->assertSame($secondo->json('payment_request_uuid'), $riga->paymentRequest->uuid);
    }

    // =========================================================================
    // 3. La conferma dell'utente
    // =========================================================================

    public function test_confermando_i_ky_si_muovono_come_un_acquisto_shop(): void
    {
        [$user, $account] = $this->buyer();
        [$seller]         = $this->seller();
        $mandate          = $this->mandate($user, $account, [], cap: 5000);

        $this->charge($user, $mandate, $seller, amount: 3000, extra: [
            'external_order_uuid' => 'ORD-2B-1',
            'order_title'         => 'Cesto di prodotti tipici',
            'quantity'            => 2,
        ])->assertStatus(402);

        $this->conferma($user);

        $this->assertSame(97000, $account->fresh()->available_balance);
        $this->assertSame(3000, $seller->fresh()->available_balance);

        $transfer = Transfer::query()->sole();

        // Il punto: non è un pagamento QR, è un acquisto shop. Da qui passano
        // cashback, commissioni e MLM, che devono restare quelli di sempre.
        $this->assertSame('portal_marketplace_order', $transfer->kind);
        $this->assertSame(Transfer::ORDER_SOURCE_KSHOP, $transfer->order_source);
        $this->assertSame('ORD-2B-1', $transfer->external_order_uuid);
        $this->assertSame('Cesto di prodotti tipici', $transfer->order_title);
        $this->assertSame(2, (int) $transfer->quantity);
        $this->assertSame(2, $transfer->ledgerEntries()->count());
    }

    public function test_confermando_il_venditore_entra_fra_quelli_autorizzati(): void
    {
        [$user, $account] = $this->buyer();
        [$seller]         = $this->seller();
        $mandate          = $this->mandate($user, $account, []);

        $this->charge($user, $mandate, $seller, amount: 1000)->assertStatus(402);
        $this->conferma($user);

        $this->assertSame([$seller->uuid], $mandate->fresh()->authorized_sellers);
    }

    public function test_togliendo_la_spunta_il_venditore_non_entra(): void
    {
        [$user, $account] = $this->buyer();
        [$seller]         = $this->seller();
        $mandate          = $this->mandate($user, $account, []);

        $this->charge($user, $mandate, $seller, amount: 1000)->assertStatus(402);

        // La casella è già spuntata nella pagina, ma toglierla deve contare:
        // altrimenti non è una scelta, è un annuncio.
        $this->conferma($user, autorizza: false);

        $this->assertSame([], $mandate->fresh()->authorized_sellers);
        $this->assertSame(1000, $seller->fresh()->available_balance, 'Il pagamento deve avvenire lo stesso.');
    }

    public function test_su_un_mandato_revocato_si_paga_ma_il_permesso_non_torna(): void
    {
        [$user, $account] = $this->buyer();
        [$seller]         = $this->seller();
        $mandate          = $this->mandate($user, $account, []);

        $this->charge($user, $mandate, $seller, amount: 1000)->assertStatus(402);

        $mandate->forceFill(['revoked_at' => now()])->save();

        $this->conferma($user);

        $this->assertSame(1000, $seller->fresh()->available_balance, 'L\'utente può sempre pagare a mano.');
        $this->assertSame(
            [],
            $mandate->fresh()->authorized_sellers,
            'Un permesso revocato non deve poter tornare in vita da una conferma di pagamento.'
        );
    }

    public function test_la_conferma_registra_l_addebito_con_la_chiave_di_kshop(): void
    {
        [$user, $account] = $this->buyer();
        [$seller]         = $this->seller();
        $mandate          = $this->mandate($user, $account, [], cap: 5000);

        $this->charge($user, $mandate, $seller, amount: 1000, key: 'carrello-42')->assertStatus(402);
        $this->conferma($user);

        $charge = PaymentMandateCharge::query()->sole();

        $this->assertSame('carrello-42', $charge->idempotency_key);
        $this->assertSame($mandate->id, (int) $charge->payment_mandate_id);
        $this->assertTrue($charge->wasConfirmedByUser());

        $riga = MandatePaymentRequest::query()->sole();
        $this->assertNotNull($riga->confirmed_at);
        $this->assertSame($charge->id, (int) $riga->payment_mandate_charge_id);
    }

    /**
     * Il test che tiene in piedi tutto il disegno.
     *
     * Kshop non sa che c'è stata una conferma di mezzo: continua a ritentare
     * l'addebito come farebbe con qualsiasi 402. Quel retry deve trovare il
     * lavoro già fatto e ricevere il movimento — non un secondo addebito, non
     * un errore che lascerebbe l'ordine appeso.
     */
    public function test_il_retry_di_kshop_dopo_la_conferma_riceve_il_movimento_e_non_riaddebita(): void
    {
        [$user, $account] = $this->buyer();
        [$seller]         = $this->seller();
        $mandate          = $this->mandate($user, $account, [], cap: 5000);

        $this->charge($user, $mandate, $seller, amount: 1000, key: 'carrello-42')->assertStatus(402);
        $this->conferma($user);

        $saldoDopoLaConferma = $account->fresh()->available_balance;

        $this->charge($user, $mandate, $seller, amount: 1000, key: 'carrello-42')
            ->assertOk()
            ->assertJsonPath('status', 'booked')
            ->assertJsonPath('repeated', true)
            ->assertJsonPath('transfer_uuid', Transfer::query()->sole()->uuid);

        $this->assertSame($saldoDopoLaConferma, $account->fresh()->available_balance);
        $this->assertDatabaseCount('transfers', 1);
        $this->assertDatabaseCount('payment_mandate_charges', 1);
    }

    public function test_la_conferma_avvisa_l_applicazione(): void
    {
        Bus::fake();

        [$user, $account] = $this->buyer();
        [$seller]         = $this->seller();
        $mandate          = $this->mandate($user, $account, [], cap: 5000);

        $this->charge($user, $mandate, $seller, amount: 1000, key: 'carrello-42')->assertStatus(402);
        $this->conferma($user);

        Bus::assertDispatched(
            SendClientWebhookJob::class,
            fn (SendClientWebhookJob $job) => $job->event === 'payment_request.paid'
                && $job->clientId === self::CLIENT_ID
                && str_contains($job->body, 'carrello-42')
        );
    }

    // =========================================================================
    // 4. Il link di conferma non è un QR
    // =========================================================================

    public function test_un_altro_utente_non_puo_pagare_la_conferma(): void
    {
        [$user, $account] = $this->buyer();
        [$seller]         = $this->seller();
        [$estraneo]       = $this->buyer();
        $mandate          = $this->mandate($user, $account, [], cap: 5000);

        $this->charge($user, $mandate, $seller, amount: 1000)->assertStatus(402);

        $token = PaymentRequest::query()->sole()->token;

        $this->actingAs($estraneo)
            ->post(route('portal.pay-request.pay', $token))
            ->assertRedirect(route('portal.dashboard'));

        $this->assertSame(0, $seller->fresh()->available_balance);
        $this->assertDatabaseCount('transfers', 0);
    }

    public function test_un_altro_utente_non_vede_nemmeno_la_pagina(): void
    {
        [$user, $account] = $this->buyer();
        [$seller]         = $this->seller();
        [$estraneo]       = $this->buyer();
        $mandate          = $this->mandate($user, $account, [], cap: 5000);

        $this->charge($user, $mandate, $seller, amount: 1000)->assertStatus(402);

        $this->actingAs($estraneo)
            ->get(route('portal.pay-request.show', PaymentRequest::query()->sole()->token))
            ->assertRedirect(route('portal.dashboard'));
    }

    public function test_la_pagina_spiega_perche_serve_la_conferma(): void
    {
        [$user, $account] = $this->buyer();
        [$seller]         = $this->seller();
        $mandate          = $this->mandate($user, $account, [], cap: 5000);

        $this->charge($user, $mandate, $seller, amount: 1000)->assertStatus(402);

        $this->actingAs($user)
            ->get(route('portal.pay-request.show', PaymentRequest::query()->sole()->token))
            ->assertOk()
            ->assertSee('Kosmoshop', escape: false)
            ->assertSee('primo acquisto da questo venditore', escape: false)
            ->assertSee('Non chiedermelo più', escape: false);
    }

    // =========================================================================
    // 5. L'antifurto non deve sparare sul proprietario
    // =========================================================================

    public function test_gli_acquisti_confermati_a_mano_non_contano_nell_antifurto(): void
    {
        config()->set('oauth.mandate.rate_limit.max_charges', 2);

        [$user, $account] = $this->buyer();
        [$seller]         = $this->seller();
        $mandate          = $this->mandate($user, $account, [], cap: 5000);

        // Tre acquisti confermati a mano, cioè tre volte in cui l'utente era
        // davanti allo schermo e ha digitato. Non è un furto.
        foreach (['a', 'b', 'c'] as $chiave) {
            $this->charge($user, $mandate, $seller, amount: 500, key: $chiave)->assertStatus(402);
            $this->conferma($user, autorizza: false);
        }

        $this->assertSame(0, $mandate->fresh()->recentChargesCount());
        $this->assertFalse($mandate->fresh()->isSuspended());

        // E il mandato è ancora buono per un addebito automatico.
        $mandate->fresh()->authorizeSeller($seller->uuid);

        $this->charge($user, $mandate->fresh(), $seller, amount: 500, key: 'automatico')
            ->assertOk();
    }

    // =========================================================================
    // Helper
    // =========================================================================

    /**
     * L'utente apre il link e paga. `autorizza` è la casella "non chiedermelo
     * più", che nella pagina arriva già spuntata.
     */
    private function conferma(User $user, bool $autorizza = true): void
    {
        $richiesta = PaymentRequest::query()
            ->where('status', 'pending')
            ->latest('id')
            ->firstOrFail();

        $this->actingAs($user)
            ->post(
                route('portal.pay-request.pay', $richiesta->token),
                $autorizza ? ['authorize_seller' => '1'] : []
            );
    }

    private function charge(User $user, PaymentMandate $mandate, Account $seller, int $amount, string $key = 'ordine-1', array $extra = [])
    {
        return $this->withHeaders(['Authorization' => 'Bearer ' . $this->tokenFor($user)])
            ->postJson(route('api.v1.mandates.charge', $mandate->uuid), array_merge([
                'seller_account_number' => $seller->uuid,
                'amount'                => $amount,
                'idempotency_key'       => $key,
            ], $extra));
    }

    private function tokenFor(User $user, array $scopes = ['profile', 'mandate']): string
    {
        $plain = 'kma_' . Str::random(60);

        OAuthAccessToken::create([
            'token_hash' => hash('sha256', $plain),
            'chain_uuid' => (string) Str::uuid(),
            'client_id'  => self::CLIENT_ID,
            'user_id'    => $user->id,
            'scopes'     => $scopes,
            'expires_at' => now()->addHour(),
        ]);

        return $plain;
    }

    private function mandate(User $user, Account $account, array $sellers, int $cap = 5000): PaymentMandate
    {
        return PaymentMandate::create([
            'user_id'             => $user->id,
            'account_id'          => $account->id,
            'client_id'           => self::CLIENT_ID,
            'max_per_transaction' => $cap,
            'authorized_sellers'  => $sellers,
            'expires_at'          => now()->addYear(),
        ]);
    }

    /**
     * @return array{0: User, 1: Account}
     */
    private function buyer(int $saldo = 100000): array
    {
        $user = User::create([
            'name'                => 'Mario Rossi',
            'email'               => 'compratore-' . Str::random(8) . '@test.test',
            'password'            => 'secret123',
            'account_holder_type' => 'private',
            'role'                => 'private-owner',
            'is_active'           => true,
            'is_super_admin'      => false,
            'email_verified_at'   => now(),
            'contract_signed_at'  => now(),
        ]);

        $account = Account::create([
            'owner_user_id'     => $user->id,
            'owner_type'        => 'private',
            'type'              => 'member',
            'status'            => 'active',
            'available_balance' => $saldo,
        ]);

        return [$user->fresh(), $account->fresh()];
    }

    /**
     * @return array{0: Account, 1: Company}
     */
    private function seller(int $saldo = 0): array
    {
        $slug = 'venditore-' . Str::random(6);

        $company = Company::create([
            'name'          => 'Venditore ' . Str::random(4),
            'slug'          => $slug,
            'email'         => $slug . '@test.test',
            'status'        => 'active',
            'kyc_status'    => 'approved',
            'currency_code' => 'KY',
            'sector'        => 'informatica',
            'description'   => 'Azienda di test',
        ]);

        $account = Account::create([
            'company_id'        => $company->id,
            'owner_type'        => 'company',
            'type'              => 'member',
            'status'            => 'active',
            'available_balance' => $saldo,
        ]);

        $user = User::create([
            'name'                => 'Titolare ' . $company->name,
            'email'               => 'titolare-' . Str::random(8) . '@test.test',
            'password'            => 'secret123',
            'account_holder_type' => 'company',
            'company_id'          => $company->id,
            'role'                => 'owner',
            'is_active'           => true,
            'is_super_admin'      => false,
            'email_verified_at'   => now(),
            'contract_signed_at'  => now(),
        ]);

        $account->forceFill(['owner_user_id' => $user->id])->save();

        return [$account->fresh(), $company->fresh()];
    }
}
