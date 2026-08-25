<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\OAuthAccessToken;
use App\Models\PaymentMandate;
use App\Models\PaymentMandateCharge;
use App\Models\Transfer;
use App\Models\User;
use App\Notifications\MandateChargedNotification;
use App\Notifications\MandateSuspendedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Il mandato di pagamento — l'addebito in un clic (fase 2a, PIANO_SHOP_ESTERNO.md §5).
 *
 * Questi test sorvegliano il punto più delicato di tutto il progetto shop
 * esterno: l'unico posto in cui un'applicazione che non è KMoney può far
 * uscire KY da un conto senza che l'utente prema niente.
 *
 * Il criterio con cui sono scritti: **ogni "no" deve restare un no anche se
 * qualcuno riscrive il codice attorno**, e ogni "no" deve essere del tipo
 * giusto — 402 quando l'acquisto si può ancora salvare chiedendo conferma
 * all'utente (fase 2b), 422 quando la richiesta proprio non sta in piedi.
 */
class PaymentMandateChargeTest extends TestCase
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
    // 1. L'addebito che funziona
    // =========================================================================

    public function test_addebito_autorizzato_sposta_i_ky_dal_compratore_al_venditore(): void
    {
        [$user, $account]           = $this->buyer(saldo: 100000);
        [$sellerAccount]            = $this->seller();
        $mandate                    = $this->mandate($user, $account, [$sellerAccount->uuid]);

        $this->charge($user, $mandate, $sellerAccount, amount: 3000)
            ->assertOk()
            ->assertJsonPath('status', 'booked')
            ->assertJsonPath('amount', 3000)
            ->assertJsonPath('repeated', false);

        $this->assertSame(97000, $account->fresh()->available_balance);
        $this->assertSame(3000, $sellerAccount->fresh()->available_balance);
    }

    public function test_il_movimento_porta_lo_snapshot_dell_ordine_esterno(): void
    {
        [$user, $account] = $this->buyer();
        [$sellerAccount]  = $this->seller();
        $mandate          = $this->mandate($user, $account, [$sellerAccount->uuid]);

        $this->charge($user, $mandate, $sellerAccount, amount: 2500, extra: [
            'external_order_uuid' => 'ORD-12345',
            'order_title'         => 'Cesto di prodotti tipici',
            'quantity'            => 2,
        ])->assertOk();

        $transfer = Transfer::query()->where('kind', 'portal_marketplace_order')->sole();

        $this->assertSame('ORD-12345', $transfer->external_order_uuid);
        $this->assertSame('Cesto di prodotti tipici', $transfer->order_title);
        $this->assertSame(Transfer::ORDER_SOURCE_KSHOP, $transfer->order_source);
        $this->assertSame(2, (int) $transfer->quantity);

        // Partita doppia: il mandato non scavalca il motore finanziario.
        $this->assertSame(2, $transfer->ledgerEntries()->count());
    }

    public function test_ogni_addebito_avvisa_l_utente_e_lascia_traccia(): void
    {
        [$user, $account] = $this->buyer();
        [$sellerAccount]  = $this->seller();
        $mandate          = $this->mandate($user, $account, [$sellerAccount->uuid]);

        $this->charge($user, $mandate, $sellerAccount, amount: 1000)->assertOk();

        Notification::assertSentTo($user, MandateChargedNotification::class);

        $this->assertTrue(
            AuditLog::query()->where('event', 'mandate.charged')->exists(),
            'Ogni addebito automatico deve lasciare una traccia in AuditLog.'
        );
    }

    public function test_il_contatore_e_l_ultimo_utilizzo_si_aggiornano(): void
    {
        [$user, $account] = $this->buyer();
        [$sellerAccount]  = $this->seller();
        $mandate          = $this->mandate($user, $account, [$sellerAccount->uuid]);

        $this->charge($user, $mandate, $sellerAccount, amount: 1000)->assertOk();
        $this->charge($user, $mandate, $sellerAccount, amount: 1000, key: 'seconda')->assertOk();

        $mandate->refresh();

        $this->assertSame(2, $mandate->charges_count);
        $this->assertNotNull($mandate->last_used_at);
    }

    // =========================================================================
    // 2. Idempotenza — la promessa "un retry non addebita due volte"
    // =========================================================================

    public function test_la_stessa_chiave_non_addebita_due_volte(): void
    {
        [$user, $account] = $this->buyer(saldo: 100000);
        [$sellerAccount]  = $this->seller();
        $mandate          = $this->mandate($user, $account, [$sellerAccount->uuid]);

        $this->charge($user, $mandate, $sellerAccount, amount: 3000, key: 'ordine-42')->assertOk();

        $this->charge($user, $mandate, $sellerAccount, amount: 3000, key: 'ordine-42')
            ->assertOk()
            ->assertJsonPath('repeated', true);

        $this->assertSame(97000, $account->fresh()->available_balance);
        $this->assertSame(1, Transfer::where('kind', 'portal_marketplace_order')->count());
        $this->assertSame(1, PaymentMandateCharge::count());
    }

    public function test_il_retry_risponde_con_lo_stesso_movimento(): void
    {
        [$user, $account] = $this->buyer();
        [$sellerAccount]  = $this->seller();
        $mandate          = $this->mandate($user, $account, [$sellerAccount->uuid]);

        $primo   = $this->charge($user, $mandate, $sellerAccount, amount: 1500, key: 'ordine-7')->json();
        $secondo = $this->charge($user, $mandate, $sellerAccount, amount: 1500, key: 'ordine-7')->json();

        $this->assertSame($primo['transfer_uuid'], $secondo['transfer_uuid']);
        $this->assertSame($primo['charge_uuid'], $secondo['charge_uuid']);
    }

    /**
     * Il retry deve funzionare anche se nel frattempo il mandato è stato
     * revocato: la risposta di un addebito già avvenuto non può cambiare.
     */
    public function test_il_retry_funziona_anche_se_il_mandato_e_stato_revocato_nel_frattempo(): void
    {
        [$user, $account] = $this->buyer();
        [$sellerAccount]  = $this->seller();
        $mandate          = $this->mandate($user, $account, [$sellerAccount->uuid]);

        $this->charge($user, $mandate, $sellerAccount, amount: 1000, key: 'ordine-9')->assertOk();

        $mandate->forceFill(['revoked_at' => now()])->save();

        $this->charge($user, $mandate, $sellerAccount, amount: 1000, key: 'ordine-9')
            ->assertOk()
            ->assertJsonPath('repeated', true);

        $this->assertSame(1, PaymentMandateCharge::count());
    }

    // =========================================================================
    // 3. Chi può usare il mandato
    // =========================================================================

    public function test_senza_token_non_si_addebita(): void
    {
        [$user, $account] = $this->buyer();
        [$sellerAccount]  = $this->seller();
        $mandate          = $this->mandate($user, $account, [$sellerAccount->uuid]);

        $this->postJson(route('api.v1.mandates.charge', $mandate->uuid), [])
            ->assertStatus(401);

        $this->assertSame(0, Transfer::count());
    }

    public function test_un_token_senza_scope_mandate_non_addebita(): void
    {
        [$user, $account] = $this->buyer();
        [$sellerAccount]  = $this->seller();
        $mandate          = $this->mandate($user, $account, [$sellerAccount->uuid]);

        $token = $this->tokenFor($user, ['profile', 'orders.write']);

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson(route('api.v1.mandates.charge', $mandate->uuid), $this->payload($sellerAccount, 1000))
            ->assertStatus(403)
            ->assertJsonPath('error', 'insufficient_scope');

        $this->assertSame(0, Transfer::count());
    }

    public function test_il_mandato_di_un_altro_utente_non_si_puo_usare(): void
    {
        [$user, $account]     = $this->buyer();
        [$altro, $altroConto] = $this->buyer();
        [$sellerAccount]      = $this->seller();

        $mandateAltrui = $this->mandate($altro, $altroConto, [$sellerAccount->uuid]);

        $this->charge($user, $mandateAltrui, $sellerAccount, amount: 1000)
            ->assertStatus(404)
            ->assertJsonPath('reason', 'mandate_not_found');

        $this->assertSame(0, Transfer::count());
    }

    public function test_il_mandato_di_un_altra_applicazione_non_si_puo_usare(): void
    {
        [$user, $account] = $this->buyer();
        [$sellerAccount]  = $this->seller();

        $mandate = $this->mandate($user, $account, [$sellerAccount->uuid]);
        $mandate->forceFill(['client_id' => 'un-altra-app'])->save();

        $this->charge($user, $mandate, $sellerAccount, amount: 1000)
            ->assertStatus(404);

        $this->assertSame(0, Transfer::count());
    }

    // =========================================================================
    // 4. Quando serve la conferma dell'utente (402)
    // =========================================================================

    public function test_mandato_revocato_chiede_conferma(): void
    {
        [$user, $account] = $this->buyer();
        [$sellerAccount]  = $this->seller();
        $mandate          = $this->mandate($user, $account, [$sellerAccount->uuid]);

        $mandate->forceFill(['revoked_at' => now()])->save();

        $this->charge($user, $mandate, $sellerAccount, amount: 1000)
            ->assertStatus(402)
            ->assertJsonPath('reason', 'mandate_revoked');

        $this->assertSame(0, Transfer::count());
    }

    public function test_mandato_scaduto_chiede_conferma(): void
    {
        [$user, $account] = $this->buyer();
        [$sellerAccount]  = $this->seller();
        $mandate          = $this->mandate($user, $account, [$sellerAccount->uuid]);

        $mandate->forceFill(['expires_at' => now()->subDay()])->save();

        $this->charge($user, $mandate, $sellerAccount, amount: 1000)
            ->assertStatus(402)
            ->assertJsonPath('reason', 'mandate_expired');

        $this->assertSame(0, Transfer::count());
    }

    public function test_mandato_sospeso_chiede_conferma(): void
    {
        [$user, $account] = $this->buyer();
        [$sellerAccount]  = $this->seller();
        $mandate          = $this->mandate($user, $account, [$sellerAccount->uuid]);

        $mandate->forceFill(['suspended_at' => now()])->save();

        $this->charge($user, $mandate, $sellerAccount, amount: 1000)
            ->assertStatus(402)
            ->assertJsonPath('reason', 'mandate_suspended');

        $this->assertSame(0, Transfer::count());
    }

    public function test_sopra_il_tetto_non_si_addebita_da_soli(): void
    {
        [$user, $account] = $this->buyer(saldo: 100000);
        [$sellerAccount]  = $this->seller();
        $mandate          = $this->mandate($user, $account, [$sellerAccount->uuid], cap: 5000);

        $this->charge($user, $mandate, $sellerAccount, amount: 5001)
            ->assertStatus(402)
            ->assertJsonPath('reason', 'amount_above_limit')
            ->assertJsonPath('max_per_transaction', 5000);

        $this->assertSame(100000, $account->fresh()->available_balance);
        $this->assertSame(0, Transfer::count());
    }

    public function test_esattamente_al_tetto_si_addebita(): void
    {
        [$user, $account] = $this->buyer(saldo: 100000);
        [$sellerAccount]  = $this->seller();
        $mandate          = $this->mandate($user, $account, [$sellerAccount->uuid], cap: 5000);

        $this->charge($user, $mandate, $sellerAccount, amount: 5000)->assertOk();

        $this->assertSame(95000, $account->fresh()->available_balance);
    }

    public function test_venditore_mai_autorizzato_chiede_conferma(): void
    {
        [$user, $account] = $this->buyer();
        [$sellerAccount]  = $this->seller();

        // Mandato senza nessun venditore approvato: è il primo acquisto.
        $mandate = $this->mandate($user, $account, []);

        $this->charge($user, $mandate, $sellerAccount, amount: 1000)
            ->assertStatus(402)
            ->assertJsonPath('reason', 'seller_not_authorized');

        $this->assertSame(0, Transfer::count());
    }

    public function test_un_venditore_autorizzato_non_ne_autorizza_un_altro(): void
    {
        [$user, $account] = $this->buyer();
        [$primoVenditore] = $this->seller();
        [$altroVenditore] = $this->seller();

        $mandate = $this->mandate($user, $account, [$primoVenditore->uuid]);

        $this->charge($user, $mandate, $altroVenditore, amount: 1000)
            ->assertStatus(402)
            ->assertJsonPath('reason', 'seller_not_authorized');

        $this->assertSame(0, Transfer::count());
    }

    public function test_limite_della_banca_superato_chiede_conferma(): void
    {
        [$user, $account] = $this->buyer(saldo: 100000);
        [$sellerAccount]  = $this->seller();

        // Limite personale per singola operazione: 50,00 KY.
        $user->forceFill([
            'transfer_limits_use_defaults' => false,
            'per_movement_limit'           => 5000,
        ])->save();

        $mandate = $this->mandate($user, $account, [$sellerAccount->uuid], cap: 100000);

        $this->charge($user, $mandate, $sellerAccount, amount: 6000)
            ->assertStatus(402)
            ->assertJsonPath('reason', 'limit_exceeded');

        $this->assertSame(100000, $account->fresh()->available_balance);
        $this->assertSame(0, Transfer::count());
    }

    // =========================================================================
    // 5. Richieste che non stanno in piedi (422)
    // =========================================================================

    public function test_venditore_inesistente(): void
    {
        [$user, $account] = $this->buyer();
        [$sellerAccount]  = $this->seller();
        $mandate          = $this->mandate($user, $account, ['KYB0000000MAI0']);

        $token = $this->tokenFor($user);

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson(route('api.v1.mandates.charge', $mandate->uuid), [
                'seller_account_number' => 'KYB0000000MAI0',
                'amount'                => 1000,
                'idempotency_key'       => (string) Str::uuid(),
            ])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'seller_unknown');
    }

    public function test_non_si_puo_pagare_un_conto_personale(): void
    {
        [$user, $account]         = $this->buyer();
        [$altroUtente, $personale] = $this->buyer();

        $mandate = $this->mandate($user, $account, [$personale->uuid]);

        $this->charge($user, $mandate, $personale, amount: 1000)
            ->assertStatus(422)
            ->assertJsonPath('reason', 'seller_not_a_company');

        $this->assertSame(0, Transfer::count());
    }

    public function test_non_si_puo_pagare_se_stessi(): void
    {
        [$user, $account] = $this->buyer();
        $mandate          = $this->mandate($user, $account, [$account->uuid]);

        // Il conto del compratore è personale: il controllo che scatta prima è
        // quello sul tipo di conto, ed è comunque un rifiuto netto.
        $this->charge($user, $mandate, $account, amount: 1000)
            ->assertStatus(422);

        $this->assertSame(0, Transfer::count());
    }

    public function test_importo_a_zero_e_rifiutato_dalla_validazione(): void
    {
        [$user, $account] = $this->buyer();
        [$sellerAccount]  = $this->seller();
        $mandate          = $this->mandate($user, $account, [$sellerAccount->uuid]);

        $this->charge($user, $mandate, $sellerAccount, amount: 0)->assertStatus(422);

        $this->assertSame(0, Transfer::count());
    }

    public function test_senza_chiave_di_idempotenza_non_si_addebita(): void
    {
        [$user, $account] = $this->buyer();
        [$sellerAccount]  = $this->seller();
        $mandate          = $this->mandate($user, $account, [$sellerAccount->uuid]);

        $token = $this->tokenFor($user);

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson(route('api.v1.mandates.charge', $mandate->uuid), [
                'seller_account_number' => $sellerAccount->uuid,
                'amount'                => 1000,
            ])
            ->assertStatus(422);

        $this->assertSame(0, Transfer::count());
    }

    // =========================================================================
    // 6. Antifurto
    // =========================================================================

    public function test_dieci_addebiti_in_un_ora_sospendono_il_mandato(): void
    {
        [$user, $account] = $this->buyer(saldo: 1000000);
        [$sellerAccount]  = $this->seller();
        $mandate          = $this->mandate($user, $account, [$sellerAccount->uuid]);

        for ($i = 1; $i <= 10; $i++) {
            $this->charge($user, $mandate, $sellerAccount, amount: 100, key: "ordine-{$i}")->assertOk();
        }

        // L'undicesimo non passa, e spegne il mandato.
        $this->charge($user, $mandate, $sellerAccount, amount: 100, key: 'ordine-11')
            ->assertStatus(402)
            ->assertJsonPath('reason', 'mandate_suspended');

        $this->assertNotNull($mandate->fresh()->suspended_at);
        Notification::assertSentTo($user, MandateSuspendedNotification::class);
    }

    public function test_gli_addebiti_vecchi_non_contano_per_l_antifurto(): void
    {
        [$user, $account] = $this->buyer(saldo: 1000000);
        [$sellerAccount]  = $this->seller();
        $mandate          = $this->mandate($user, $account, [$sellerAccount->uuid]);

        for ($i = 1; $i <= 10; $i++) {
            $this->charge($user, $mandate, $sellerAccount, amount: 100, key: "vecchio-{$i}")->assertOk();
        }

        // Passata l'ora, la finestra è vuota: si ricomincia a comprare.
        $this->travel(61)->minutes();

        $this->charge($user, $mandate, $sellerAccount, amount: 100, key: 'nuovo')->assertOk();

        $this->assertNull($mandate->fresh()->suspended_at);
    }

    // =========================================================================
    // 7. Elenco dei mandati
    // =========================================================================

    public function test_elenco_mostra_solo_i_mandati_vivi_di_questa_applicazione(): void
    {
        [$user, $account] = $this->buyer();
        [$sellerAccount]  = $this->seller();

        $vivo = $this->mandate($user, $account, [$sellerAccount->uuid]);

        $revocato = $this->mandate($user, $account, []);
        $revocato->forceFill(['revoked_at' => now()])->save();

        $token = $this->tokenFor($user);

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->getJson(route('api.v1.mandates.index'))
            ->assertOk();

        $this->assertCount(1, $response->json('mandates'));
        $this->assertSame($vivo->uuid, $response->json('mandates.0.uuid'));
        $this->assertSame(5000, $response->json('mandates.0.max_per_transaction'));
    }

    public function test_l_elenco_non_espone_i_mandati_di_altri_utenti(): void
    {
        [$user, $account]     = $this->buyer();
        [$altro, $altroConto] = $this->buyer();
        [$sellerAccount]      = $this->seller();

        $this->mandate($altro, $altroConto, [$sellerAccount->uuid]);

        $token = $this->tokenFor($user);

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->getJson(route('api.v1.mandates.index'))
            ->assertOk();

        $this->assertCount(0, $response->json('mandates'));
    }

    // =========================================================================
    // Helper
    // =========================================================================

    private function charge(User $user, PaymentMandate $mandate, Account $seller, int $amount, string $key = 'ordine-1', array $extra = [])
    {
        $token = $this->tokenFor($user);

        return $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson(
                route('api.v1.mandates.charge', $mandate->uuid),
                array_merge($this->payload($seller, $amount, $key), $extra)
            );
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Account $seller, int $amount, string $key = 'ordine-1'): array
    {
        return [
            'seller_account_number' => $seller->uuid,
            'amount'                => $amount,
            'idempotency_key'       => $key,
        ];
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
