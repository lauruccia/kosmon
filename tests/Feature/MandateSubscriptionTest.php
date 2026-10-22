<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\MandateSubscription;
use App\Models\MandatePaymentRequest;
use App\Models\OAuthAccessToken;
use App\Models\PaymentMandate;
use App\Models\PaymentMandateCharge;
use App\Models\Transfer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Gli abbonamenti dei portali e il blocco dell'utente.
 *
 * Il criterio con cui sono scritti: **il blocco è dell'utente e il portale non
 * può mai riaccendere ciò che l'utente ha spento**, né con un nuovo invio, né
 * ricreando l'abbonamento sotto il nome di un altro utente, né ripresentando
 * l'addebito. E un "no" per abbonamento bloccato deve restare un 403 netto: se
 * diventasse il 402 "chiediglielo", il checkout offrirebbe all'utente di
 * confermare a mano proprio ciò che ha appena fermato.
 */
class MandateSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    private const CLIENT_ID = 'kshop-test-client';

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        config()->set('oauth.clients.kshop', [
            'name'          => 'Kosmoshop',
            'client_id'     => self::CLIENT_ID,
            'secret'        => 'segreto-di-prova-molto-lungo',
            'redirect_uris' => ['https://kosmoshop.test/oauth/callback'],
            'scopes'        => ['profile', 'account.read', 'orders.write', 'mandate'],
        ]);
    }

    // =========================================================================
    // 1. API — il portale manda la copia dell'abbonamento
    // =========================================================================

    public function test_il_portale_registra_un_abbonamento(): void
    {
        [$user] = $this->buyer();

        $this->putSub($user, 'sub-1', $this->body(['items' => ['1 × Box mensile'], 'amount' => 1000]))
            ->assertCreated()
            ->assertJsonPath('external_id', 'sub-1')
            ->assertJsonPath('status', 'active')
            ->assertJsonPath('blocked', false)
            ->assertJsonPath('amount', 1000);

        $sub = MandateSubscription::query()->sole();
        $this->assertSame($user->id, $sub->user_id);
        $this->assertSame(self::CLIENT_ID, $sub->client_id);
        $this->assertSame(['1 × Box mensile'], $sub->items);
        $this->assertTrue(AuditLog::query()->where('event', 'subscription.registered')->exists());
    }

    public function test_rimandare_lo_stesso_abbonamento_aggiorna_senza_duplicare(): void
    {
        [$user] = $this->buyer();

        $this->putSub($user, 'sub-1', $this->body(['next_charge_at' => '2026-11-13T10:00:00+01:00']))->assertCreated();
        $this->putSub($user, 'sub-1', $this->body(['status' => 'paused', 'next_charge_at' => null, 'last_error' => 'saldo insufficiente']))
            ->assertOk()
            ->assertJsonPath('status', 'paused');

        $sub = MandateSubscription::query()->sole();
        $this->assertSame('paused', $sub->status);
        $this->assertNull($sub->next_charge_at);
        $this->assertSame('saldo insufficiente', $sub->last_error);
        $this->assertTrue(AuditLog::query()->where('event', 'subscription.status_changed')->exists());
    }

    public function test_il_portale_non_puo_sbloccare_un_abbonamento_bloccato(): void
    {
        [$user] = $this->buyer();
        $this->putSub($user, 'sub-1', $this->body())->assertCreated();

        $sub = MandateSubscription::query()->sole();
        $sub->forceFill(['blocked_at' => now()])->save();

        // Il portale rimanda l'abbonamento come attivo, magari ricalcolato
        // dopo un cambio di indirizzo: il blocco resta, e glielo si dice.
        $this->putSub($user, 'sub-1', $this->body(['status' => 'active', 'next_charge_at' => '2026-12-13T10:00:00+01:00']))
            ->assertOk()
            ->assertJsonPath('blocked', true);

        $this->assertNotNull($sub->fresh()->blocked_at);
    }

    public function test_un_abbonamento_chiuso_dal_portale_perde_il_prossimo_addebito(): void
    {
        [$user] = $this->buyer();

        $this->putSub($user, 'sub-1', $this->body(['next_charge_at' => '2026-11-13T10:00:00+01:00']))->assertCreated();
        $this->putSub($user, 'sub-1', $this->body(['status' => 'cancelled', 'next_charge_at' => '2026-11-13T10:00:00+01:00']))->assertOk();

        $sub = MandateSubscription::query()->sole();
        $this->assertNull($sub->next_charge_at, 'Un abbonamento annullato non ha un prossimo addebito.');
        $this->assertNotNull($sub->cancelled_at);
    }

    public function test_lo_stesso_identificativo_non_si_scrive_con_il_token_di_un_altro_utente(): void
    {
        [$user]  = $this->buyer();
        [$altro] = $this->buyer();

        $this->putSub($user, 'sub-1', $this->body(['title' => 'Il mio']))->assertCreated();

        $this->putSub($altro, 'sub-1', $this->body(['title' => 'Rubato']))
            ->assertStatus(409)
            ->assertJsonPath('reason', 'subscription_owner_mismatch');

        $sub = MandateSubscription::query()->sole();
        $this->assertSame($user->id, $sub->user_id);
        $this->assertSame('Il mio', $sub->title);
    }

    public function test_senza_lo_scope_mandate_non_si_registra_niente(): void
    {
        [$user] = $this->buyer();

        $this->withHeaders(['Authorization' => 'Bearer ' . $this->tokenFor($user, ['profile'])])
            ->putJson(route('api.v1.subscriptions.upsert', 'sub-1'), $this->body())
            ->assertStatus(403);

        $this->assertSame(0, MandateSubscription::query()->count());
    }

    public function test_dati_non_validi_vengono_rifiutati(): void
    {
        [$user] = $this->buyer();

        $this->putSub($user, 'sub-1', $this->body(['status' => 'sospeso-per-ferie']))->assertStatus(422);
        $this->putSub($user, 'sub-1', $this->body(['amount' => -5]))->assertStatus(422);
        $this->putSub($user, 'sub-1', ['status' => 'active'])->assertStatus(422);

        $this->assertSame(0, MandateSubscription::query()->count());
    }

    public function test_l_elenco_api_mostra_solo_i_miei_per_questa_applicazione(): void
    {
        [$user]  = $this->buyer();
        [$altro] = $this->buyer();

        $this->putSub($user, 'sub-1', $this->body(['title' => 'Mio']))->assertCreated();
        $this->putSub($altro, 'sub-2', $this->body(['title' => 'Di un altro']))->assertCreated();
        MandateSubscription::create([
            'user_id' => $user->id, 'client_id' => 'altra-app', 'external_id' => 'x-1',
            'title' => 'Di un\'altra app', 'status' => 'active',
        ]);

        $res = $this->withHeaders(['Authorization' => 'Bearer ' . $this->tokenFor($user)])
            ->getJson(route('api.v1.subscriptions.index'))
            ->assertOk();

        $this->assertSame(['Mio'], collect($res->json('subscriptions'))->pluck('title')->all());
    }

    // =========================================================================
    // 2. Addebito — il blocco vale dove i soldi escono
    // =========================================================================

    public function test_un_ciclo_di_abbonamento_si_lega_all_abbonamento(): void
    {
        [$user, $account] = $this->buyer();
        [$seller]         = $this->seller();
        $mandate          = $this->mandate($user, $account, [$seller->uuid]);
        $sub              = $this->subscription($user);

        $this->charge($user, $mandate, $seller, 1000, 'ciclo-1', ['subscription_id' => 'sub-1'])
            ->assertOk()
            ->assertJsonPath('status', 'booked');

        $charge = PaymentMandateCharge::query()->sole();
        $this->assertSame($sub->id, $charge->mandate_subscription_id);
        $this->assertNotNull($sub->fresh()->last_charged_at);
        $this->assertSame(1, $sub->charges()->count());
    }

    public function test_un_abbonamento_bloccato_non_viene_addebitato(): void
    {
        [$user, $account] = $this->buyer(saldo: 100000);
        [$seller]         = $this->seller();
        $mandate          = $this->mandate($user, $account, [$seller->uuid]);
        $this->subscription($user, blocked: true);

        $this->charge($user, $mandate, $seller, 1000, 'ciclo-1', ['subscription_id' => 'sub-1'])
            ->assertStatus(403)
            ->assertJsonPath('status', 'refused')
            ->assertJsonPath('reason', 'subscription_blocked');

        // Il test guarda i soldi: un "no" che lascia uscire il denaro non è un no.
        $this->assertSame(100000, $account->fresh()->available_balance);
        $this->assertSame(0, $seller->fresh()->available_balance);
        $this->assertSame(0, Transfer::query()->where('kind', 'portal_marketplace_order')->count());
        $this->assertSame(0, PaymentMandateCharge::query()->count());
    }

    public function test_il_blocco_non_diventa_una_richiesta_di_conferma_sopra_il_tetto(): void
    {
        [$user, $account] = $this->buyer();
        [$seller]         = $this->seller();
        $mandate          = $this->mandate($user, $account, [$seller->uuid], cap: 5000);
        $this->subscription($user, blocked: true);

        // 80 KY sopra il tetto da 50: senza blocco sarebbe un 402 con il link
        // di conferma. Con il blocco il link non deve nemmeno nascere.
        $this->charge($user, $mandate, $seller, 8000, 'ciclo-1', ['subscription_id' => 'sub-1'])
            ->assertStatus(403)
            ->assertJsonPath('reason', 'subscription_blocked')
            ->assertJsonMissingPath('confirmation_url');

        $this->assertSame(0, MandatePaymentRequest::query()->count());
    }

    public function test_il_retry_di_un_addebito_gia_riuscito_riceve_la_stessa_risposta_anche_dopo_il_blocco(): void
    {
        [$user, $account] = $this->buyer();
        [$seller]         = $this->seller();
        $mandate          = $this->mandate($user, $account, [$seller->uuid]);
        $sub              = $this->subscription($user);

        $this->charge($user, $mandate, $seller, 1000, 'ciclo-1', ['subscription_id' => 'sub-1'])->assertOk();

        // Nel frattempo l'utente blocca; il portale non ha ricevuto la risposta
        // e ripete. Non è un secondo addebito e non è un errore nuovo.
        $sub->forceFill(['blocked_at' => now()])->save();

        $this->charge($user, $mandate, $seller, 1000, 'ciclo-1', ['subscription_id' => 'sub-1'])
            ->assertOk()
            ->assertJsonPath('repeated', true);

        $this->assertSame(1, PaymentMandateCharge::query()->count());
    }

    public function test_dopo_lo_sblocco_l_addebito_riparte(): void
    {
        [$user, $account] = $this->buyer();
        [$seller]         = $this->seller();
        $mandate          = $this->mandate($user, $account, [$seller->uuid]);
        $sub              = $this->subscription($user, blocked: true);

        $this->charge($user, $mandate, $seller, 1000, 'ciclo-1', ['subscription_id' => 'sub-1'])->assertStatus(403);

        $sub->forceFill(['blocked_at' => null])->save();

        $this->charge($user, $mandate, $seller, 1000, 'ciclo-1', ['subscription_id' => 'sub-1'])->assertOk();
    }

    public function test_un_acquisto_senza_abbonamento_non_e_toccato_dal_blocco(): void
    {
        [$user, $account] = $this->buyer();
        [$seller]         = $this->seller();
        $mandate          = $this->mandate($user, $account, [$seller->uuid]);
        $this->subscription($user, blocked: true);

        // Un acquisto singolo dello stesso portale, senza subscription_id: il
        // blocco è su UN abbonamento, non sull'applicazione (per quello c'è la
        // revoca del mandato).
        $this->charge($user, $mandate, $seller, 1000, 'ordine-singolo')->assertOk();
    }

    public function test_un_identificativo_sconosciuto_non_ferma_l_addebito(): void
    {
        [$user, $account] = $this->buyer();
        [$seller]         = $this->seller();
        $mandate          = $this->mandate($user, $account, [$seller->uuid]);

        $this->charge($user, $mandate, $seller, 1000, 'ciclo-1', ['subscription_id' => 'mai-registrato'])->assertOk();

        $this->assertNull(PaymentMandateCharge::query()->sole()->mandate_subscription_id);
    }

    public function test_l_abbonamento_bloccato_di_un_altro_utente_non_ferma_il_mio_addebito(): void
    {
        [$user, $account] = $this->buyer();
        [$altro]          = $this->buyer();
        [$seller]         = $this->seller();
        $mandate          = $this->mandate($user, $account, [$seller->uuid]);

        // L'identificativo è unico per applicazione: se esiste ed è di un
        // altro, dichiararlo dal mio mandato non lo rende mio.
        $this->subscription($altro, blocked: true);

        $this->charge($user, $mandate, $seller, 1000, 'ciclo-1', ['subscription_id' => 'sub-1'])->assertOk();

        $this->assertNull(PaymentMandateCharge::query()->sole()->mandate_subscription_id);
    }

    // =========================================================================
    // 3. La pagina dell'utente
    // =========================================================================

    public function test_senza_abbonamenti_la_pagina_lo_dice_chiaramente(): void
    {
        [$user] = $this->buyer();

        $this->actingAs($user)
            ->get(route('portal.subscriptions.index'))
            ->assertOk()
            ->assertSee('Nessun abbonamento');
    }

    public function test_la_pagina_mostra_titolo_importo_data_e_stato(): void
    {
        [$user] = $this->buyer();
        $this->subscription($user, [
            'title' => '1 prodotto al mese', 'items' => ['1 × test'], 'amount' => 1050,
            'next_charge_at' => now()->addDays(31),
        ]);

        $this->actingAs($user)
            ->get(route('portal.subscriptions.index'))
            ->assertOk()
            ->assertSee('1 prodotto al mese')
            ->assertSee('1 × test')
            ->assertSee('10,50 KY')
            ->assertSee(now()->addDays(31)->format('d/m/Y'))
            ->assertSee('Kosmoshop')
            ->assertSee('Attivo');
    }

    public function test_la_pagina_non_mostra_gli_abbonamenti_di_altri(): void
    {
        [$user]  = $this->buyer();
        [$altro] = $this->buyer();
        $this->subscription($altro, ['title' => 'Abbonamento segreto']);

        $this->actingAs($user)
            ->get(route('portal.subscriptions.index'))
            ->assertOk()
            ->assertDontSee('Abbonamento segreto')
            ->assertSee('Nessun abbonamento');
    }

    public function test_la_pagina_avvisa_se_l_autorizzazione_dell_app_non_e_piu_attiva(): void
    {
        [$user, $account] = $this->buyer();
        $mandate          = $this->mandate($user, $account, []);
        $this->subscription($user);
        $mandate->forceFill(['revoked_at' => now()])->save();

        $this->actingAs($user)
            ->get(route('portal.subscriptions.index'))
            ->assertOk()
            ->assertSee('non è più attiva');
    }

    public function test_la_pagina_sicurezza_porta_agli_abbonamenti(): void
    {
        [$user] = $this->buyer();

        $this->actingAs($user)
            ->get(route('portal.security'))
            ->assertOk()
            ->assertSee(route('portal.subscriptions.index'));
    }

    // =========================================================================
    // 4. Bloccare è facile, sbloccare no
    // =========================================================================

    public function test_bloccare_non_richiede_lo_step_up(): void
    {
        [$user] = $this->buyer();
        $sub    = $this->subscription($user);

        // Nessuno step-up in sessione, di proposito.
        $this->actingAs($user)
            ->post(route('portal.subscriptions.block', $sub->uuid))
            ->assertRedirect(route('portal.subscriptions.index'));

        $this->assertTrue($sub->fresh()->isBlocked());
        $this->assertTrue(AuditLog::query()->where('event', 'subscription.blocked')->exists());
    }

    public function test_sbloccare_richiede_lo_step_up(): void
    {
        [$user] = $this->buyer();
        $sub    = $this->subscription($user, blocked: true);

        $this->actingAs($user)
            ->post(route('portal.subscriptions.unblock', $sub->uuid))
            ->assertRedirect(route('portal.step-up.show'));

        $this->assertTrue($sub->fresh()->isBlocked());
    }

    public function test_con_lo_step_up_l_abbonamento_si_sblocca(): void
    {
        [$user] = $this->buyer();
        $sub    = $this->subscription($user, blocked: true);

        $this->actingAs($user)
            ->withSession(['step_up_verified_at' => now()->timestamp])
            ->post(route('portal.subscriptions.unblock', $sub->uuid))
            ->assertRedirect(route('portal.subscriptions.index'));

        $this->assertFalse($sub->fresh()->isBlocked());
        $this->assertTrue(AuditLog::query()->where('event', 'subscription.unblocked')->exists());
    }

    public function test_bloccare_due_volte_non_sposta_la_data(): void
    {
        [$user] = $this->buyer();
        $sub    = $this->subscription($user, blocked: true);
        $prima  = $sub->fresh()->blocked_at;

        $this->travel(2)->days();

        $this->actingAs($user)->post(route('portal.subscriptions.block', $sub->uuid));

        $this->assertEquals($prima, $sub->fresh()->blocked_at, 'La data del blocco non deve spostarsi.');
    }

    public function test_non_si_puo_bloccare_l_abbonamento_di_un_altro(): void
    {
        [$user]  = $this->buyer();
        [$altro] = $this->buyer();
        $sub     = $this->subscription($altro);

        $this->actingAs($user)
            ->post(route('portal.subscriptions.block', $sub->uuid))
            ->assertNotFound();

        $this->assertFalse($sub->fresh()->isBlocked());
    }

    public function test_non_si_puo_sbloccare_l_abbonamento_di_un_altro(): void
    {
        [$user]  = $this->buyer();
        [$altro] = $this->buyer();
        $sub     = $this->subscription($altro, blocked: true);

        $this->actingAs($user)
            ->withSession(['step_up_verified_at' => now()->timestamp])
            ->post(route('portal.subscriptions.unblock', $sub->uuid))
            ->assertNotFound();

        $this->assertTrue($sub->fresh()->isBlocked());
    }

    // =========================================================================
    // Helper
    // =========================================================================

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function body(array $overrides = []): array
    {
        return array_merge([
            'title'  => '1 prodotto al mese',
            'status' => 'active',
        ], $overrides);
    }

    private function putSub(User $user, string $externalId, array $body)
    {
        return $this->withHeaders(['Authorization' => 'Bearer ' . $this->tokenFor($user)])
            ->putJson(route('api.v1.subscriptions.upsert', $externalId), $body);
    }

    private function charge(User $user, PaymentMandate $mandate, Account $seller, int $amount, string $key, array $extra = [])
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

    /**
     * @param array<string, mixed> $overrides
     */
    // =========================================================================
    // 4. Il backoffice vede tutto
    // =========================================================================

    public function test_l_admin_vede_gli_abbonamenti_con_nome_del_portale_importo_e_stato(): void
    {
        [$user] = $this->buyer();
        $this->subscription($user, [
            'title' => '2 prodotti al mese', 'items' => ['2 × test'], 'amount' => 2500,
            'next_charge_at' => now()->addDays(10), 'last_charged_at' => now()->subDays(20),
        ]);
        $this->subscription($user, ['external_id' => 'sub-2', 'title' => 'Abbonamento fermato'], blocked: true);

        $this->actingAsWithSession($this->admin())
            ->get(route('admin.mandate-subscriptions.index'))
            ->assertOk()
            ->assertSee('Kosmoshop')
            ->assertSee($user->email)
            ->assertSee('2 prodotti al mese')
            ->assertSee('25,00 KY')
            ->assertSee(now()->addDays(10)->format('d/m/Y'))
            ->assertSee('Bloccato')
            ->assertSee('Attivo');
    }

    public function test_l_admin_filtra_per_stato_e_per_portale(): void
    {
        [$user] = $this->buyer();
        $this->subscription($user, ['title' => 'Quello attivo']);
        $this->subscription($user, ['external_id' => 'sub-2', 'title' => 'Quello bloccato'], blocked: true);
        $this->subscription($user, ['external_id' => 'sub-3', 'client_id' => 'altro-portale', 'title' => 'Quello di un altro portale']);

        $admin = $this->admin();

        $this->actingAsWithSession($admin)
            ->get(route('admin.mandate-subscriptions.index', ['stato' => 'blocked']))
            ->assertOk()
            ->assertSee('Quello bloccato')
            ->assertDontSee('Quello attivo');

        $this->actingAsWithSession($admin)
            ->get(route('admin.mandate-subscriptions.index', ['client_id' => 'altro-portale']))
            ->assertOk()
            ->assertSee('Quello di un altro portale')
            ->assertDontSee('Quello attivo');
    }

    public function test_la_pagina_admin_non_e_per_gli_utenti_normali(): void
    {
        [$user] = $this->buyer();

        $this->actingAsWithSession($user)
            ->get(route('admin.mandate-subscriptions.index'))
            ->assertForbidden();
    }

    private function admin(): User
    {
        $admin = User::create([
            'name'                => 'Admin',
            'email'               => 'admin-' . Str::random(10) . '@test.test',
            'password'            => 'secret123',
            'account_holder_type' => 'private',
            'company_id'          => null,
            'is_active'           => true,
            'is_super_admin'      => true,
        ]);
        $admin->forceFill(['email_verified_at' => now()])->save();

        return $admin;
    }

    private function subscription(User $user, array $overrides = [], bool $blocked = false): MandateSubscription
    {
        return MandateSubscription::create(array_merge([
            'user_id'         => $user->id,
            'client_id'       => self::CLIENT_ID,
            'external_id'     => 'sub-1',
            'title'           => '1 prodotto al mese',
            'status'          => 'active',
            'interval_months' => 1,
            'blocked_at'      => $blocked ? now() : null,
        ], $overrides));
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
