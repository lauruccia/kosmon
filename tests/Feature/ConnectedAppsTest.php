<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\PaymentMandate;
use App\Models\PaymentMandateCharge;
use App\Models\Transfer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * La pagina "App collegate" (fase 2a, §5).
 *
 * Regola di fondo, ed è il motivo per cui questi test esistono: **spegnere
 * dev'essere sempre più facile che accendere.** Concedere il mandato chiede lo
 * step-up e alzarne il tetto pure; revocarlo no — un permesso che si fatica a
 * togliere è un permesso che resta acceso.
 */
class ConnectedAppsTest extends TestCase
{
    use RefreshDatabase;

    private const CLIENT_ID = 'kshop-test-client';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('oauth.clients.kshop', [
            'name'          => 'Kosmoshop',
            'client_id'     => self::CLIENT_ID,
            'secret'        => 'segreto-di-prova-molto-lungo',
            'redirect_uris' => ['https://kosmoshop.test/oauth/callback'],
            'scopes'        => ['profile', 'account.read', 'orders.write', 'mandate'],
        ]);
    }

    // =========================================================================
    // 1. La pagina
    // =========================================================================

    public function test_senza_app_collegate_la_pagina_lo_dice_chiaramente(): void
    {
        [$user] = $this->buyer();

        $this->actingAs($user)
            ->get(route('portal.connected-apps.index'))
            ->assertOk()
            ->assertSee('Nessuna app collegata');
    }

    public function test_la_pagina_mostra_nome_tetto_e_stato_dell_autorizzazione(): void
    {
        [$user, $account] = $this->buyer();
        $this->mandate($user, $account, cap: 5000);

        $this->actingAs($user)
            ->get(route('portal.connected-apps.index'))
            ->assertOk()
            ->assertSee('Kosmoshop')
            ->assertSee('50,00 KY')
            ->assertSee('Attiva');
    }

    public function test_la_pagina_mostra_gli_addebiti_automatici(): void
    {
        [$user, $account] = $this->buyer();
        $mandate = $this->mandate($user, $account);

        $this->addebito($mandate, 'Cesto di prodotti tipici', 1200);

        $this->actingAs($user)
            ->get(route('portal.connected-apps.index'))
            ->assertOk()
            ->assertSee('Cesto di prodotti tipici')
            ->assertSee('12,00 KY');
    }

    public function test_la_pagina_non_mostra_le_autorizzazioni_di_altri(): void
    {
        [$user]                 = $this->buyer();
        [$altro, $altroAccount] = $this->buyer();

        $this->mandate($altro, $altroAccount);

        $this->actingAs($user)
            ->get(route('portal.connected-apps.index'))
            ->assertOk()
            ->assertSee('Nessuna app collegata');
    }

    public function test_la_pagina_sicurezza_porta_alle_app_collegate(): void
    {
        [$user] = $this->buyer();

        $this->actingAs($user)
            ->get(route('portal.security'))
            ->assertOk()
            ->assertSee(route('portal.connected-apps.index'));
    }

    // =========================================================================
    // 2. Revoca — un clic, senza cerimonie
    // =========================================================================

    public function test_revocare_non_richiede_lo_step_up(): void
    {
        [$user, $account] = $this->buyer();
        $mandate = $this->mandate($user, $account);

        // Nessuno step-up in sessione, di proposito.
        $this->actingAs($user)
            ->post(route('portal.connected-apps.revoke', $mandate->uuid))
            ->assertRedirect(route('portal.connected-apps.index'));

        $this->assertNotNull($mandate->fresh()->revoked_at);
        $this->assertFalse($mandate->fresh()->isActive());
    }

    public function test_la_revoca_lascia_traccia_in_audit_log(): void
    {
        [$user, $account] = $this->buyer();
        $mandate = $this->mandate($user, $account);

        $this->actingAs($user)->post(route('portal.connected-apps.revoke', $mandate->uuid));

        $this->assertTrue(AuditLog::query()->where('event', 'mandate.revoked')->exists());
    }

    public function test_non_si_puo_revocare_l_autorizzazione_di_un_altro(): void
    {
        [$user]                 = $this->buyer();
        [$altro, $altroAccount] = $this->buyer();

        $mandate = $this->mandate($altro, $altroAccount);

        $this->actingAs($user)
            ->post(route('portal.connected-apps.revoke', $mandate->uuid))
            ->assertNotFound();

        $this->assertNull($mandate->fresh()->revoked_at);
    }

    public function test_revocare_due_volte_non_rompe_niente(): void
    {
        [$user, $account] = $this->buyer();
        $mandate = $this->mandate($user, $account);

        $this->actingAs($user)->post(route('portal.connected-apps.revoke', $mandate->uuid));
        $primaRevoca = $mandate->fresh()->revoked_at;

        $this->travel(5)->minutes();

        $this->actingAs($user)
            ->post(route('portal.connected-apps.revoke', $mandate->uuid))
            ->assertRedirect(route('portal.connected-apps.index'));

        $this->assertEquals($primaRevoca, $mandate->fresh()->revoked_at, 'La data di revoca non deve spostarsi.');
    }

    // =========================================================================
    // 3. Tetto — alzarlo è un'azione sensibile
    // =========================================================================

    public function test_cambiare_il_tetto_richiede_lo_step_up(): void
    {
        [$user, $account] = $this->buyer();
        $mandate = $this->mandate($user, $account, cap: 5000);

        $this->actingAs($user)
            ->post(route('portal.connected-apps.limit', $mandate->uuid), ['max_per_transaction' => '100.00'])
            ->assertRedirect(route('portal.step-up.show'));

        $this->assertSame(5000, $mandate->fresh()->max_per_transaction);
    }

    public function test_con_lo_step_up_il_tetto_si_cambia(): void
    {
        [$user, $account] = $this->buyer();
        $mandate = $this->mandate($user, $account, cap: 5000);

        $this->actingAs($user)
            ->withSession(['step_up_verified_at' => now()->timestamp])
            ->post(route('portal.connected-apps.limit', $mandate->uuid), ['max_per_transaction' => '80,00'])
            ->assertRedirect(route('portal.connected-apps.index'));

        $this->assertSame(8000, $mandate->fresh()->max_per_transaction);
        $this->assertTrue(AuditLog::query()->where('event', 'mandate.limit_changed')->exists());
    }

    public function test_un_tetto_fuori_dai_limiti_viene_rifiutato(): void
    {
        [$user, $account] = $this->buyer();
        $mandate = $this->mandate($user, $account, cap: 5000);

        $this->actingAs($user)
            ->withSession(['step_up_verified_at' => now()->timestamp])
            ->post(route('portal.connected-apps.limit', $mandate->uuid), ['max_per_transaction' => '5000.00'])
            ->assertSessionHasErrors('max_per_transaction');

        $this->assertSame(5000, $mandate->fresh()->max_per_transaction);
    }

    // =========================================================================
    // Helper
    // =========================================================================

    private function mandate(User $user, Account $account, int $cap = 5000): PaymentMandate
    {
        return PaymentMandate::create([
            'user_id'             => $user->id,
            'account_id'          => $account->id,
            'client_id'           => self::CLIENT_ID,
            'max_per_transaction' => $cap,
            'authorized_sellers'  => [],
            'expires_at'          => now()->addYear(),
        ]);
    }

    private function addebito(PaymentMandate $mandate, string $titolo, int $importo): void
    {
        $transfer = Transfer::create([
            'uuid'            => (string) Str::uuid(),
            'from_account_id' => $mandate->account_id,
            'to_account_id'   => $mandate->account_id,
            'amount'          => $importo,
            'currency_code'   => 'KY',
            'kind'            => 'portal_marketplace_order',
            'status'          => 'booked',
            'initiated_by'    => $mandate->user_id,
            'idempotency_key' => (string) Str::uuid(),
        ]);

        PaymentMandateCharge::create([
            'payment_mandate_id'    => $mandate->id,
            'transfer_id'           => $transfer->id,
            'amount'                => $importo,
            'seller_account_number' => 'KYB0000000TEST',
            'order_title'           => $titolo,
            'quantity'              => 1,
            'idempotency_key'       => (string) Str::uuid(),
        ]);
    }

    /**
     * @return array{0: User, 1: Account}
     */
    private function buyer(): array
    {
        $user = User::create([
            'name'                => 'Mario Rossi',
            'email'               => 'utente-' . Str::random(8) . '@test.test',
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
            'available_balance' => 100000,
        ]);

        return [$user->fresh(), $account->fresh()];
    }
}
