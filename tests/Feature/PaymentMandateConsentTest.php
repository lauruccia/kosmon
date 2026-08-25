<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\PaymentMandate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * La schermata con cui si concede il mandato di pagamento (fase 2a, §5).
 *
 * Il punto che questi test difendono non è "la pagina si apre": è che
 * **un'autorizzazione a muovere soldi non si possa dare per sbaglio, né di
 * nascosto**. Quindi: step-up obbligatorio, indirizzo di ritorno verificato
 * prima di qualunque redirect, tetto entro limiti, un solo mandato vivo per
 * applicazione, e traccia in AuditLog.
 */
class PaymentMandateConsentTest extends TestCase
{
    use RefreshDatabase;

    private const CLIENT_ID = 'kshop-test-client';
    private const RETURN_URL = 'https://kosmoshop.test/oauth/callback';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('oauth.clients.kshop', [
            'name'          => 'Kosmoshop',
            'client_id'     => self::CLIENT_ID,
            'secret'        => 'segreto-di-prova-molto-lungo',
            'redirect_uris' => [self::RETURN_URL],
            'scopes'        => ['profile', 'account.read', 'orders.write', 'mandate'],
        ]);
    }

    // =========================================================================
    // 1. Chi può arrivare alla schermata
    // =========================================================================

    public function test_senza_step_up_non_si_arriva_alla_schermata(): void
    {
        [$user] = $this->buyer();

        $this->actingAs($user)
            ->get($this->mandateUrl())
            ->assertRedirect(route('portal.step-up.show'));

        $this->assertSame(0, PaymentMandate::count());
    }

    public function test_client_sconosciuto_non_riceve_nessun_redirect(): void
    {
        [$user] = $this->buyer();

        $this->actingAs($user)->withSession($this->stepUp())
            ->get($this->mandateUrl(['client_id' => 'app-mai-vista']))
            ->assertStatus(400)
            ->assertSee('Applicazione sconosciuta');
    }

    public function test_indirizzo_di_ritorno_fuori_lista_non_riceve_nessun_redirect(): void
    {
        [$user] = $this->buyer();

        $this->actingAs($user)->withSession($this->stepUp())
            ->get($this->mandateUrl(['return_url' => 'https://sito-dell-attaccante.test/callback']))
            ->assertStatus(400)
            ->assertSee('Indirizzo di ritorno non autorizzato');
    }

    // =========================================================================
    // 2. La schermata
    // =========================================================================

    public function test_la_schermata_propone_il_tetto_di_cinquanta_ky(): void
    {
        [$user] = $this->buyer();

        $this->actingAs($user)->withSession($this->stepUp())
            ->get($this->mandateUrl())
            ->assertOk()
            ->assertSee('Kosmoshop')
            ->assertSee('50.00', false)      // il valore precompilato nel campo
            ->assertSee('non viene bloccato', false);
    }

    public function test_la_schermata_dice_quale_venditore_sta_per_essere_autorizzato(): void
    {
        [$user]                    = $this->buyer();
        [$sellerAccount, $company] = $this->seller();

        $this->actingAs($user)->withSession($this->stepUp())
            ->get($this->mandateUrl(['seller' => $sellerAccount->uuid]))
            ->assertOk()
            ->assertSee($company->name);
    }

    public function test_aprire_la_schermata_non_autorizza_niente(): void
    {
        [$user] = $this->buyer();

        $this->actingAs($user)->withSession($this->stepUp())->get($this->mandateUrl())->assertOk();

        $this->assertSame(0, PaymentMandate::count());
    }

    // =========================================================================
    // 3. La concessione
    // =========================================================================

    public function test_autorizzare_crea_il_mandato_e_riporta_all_applicazione(): void
    {
        [$user, $account]  = $this->buyer();
        [$sellerAccount]   = $this->seller();

        $this->actingAs($user)->withSession($this->stepUp())
            ->get($this->mandateUrl(['seller' => $sellerAccount->uuid]));

        $response = $this->actingAs($user)->withSession($this->stepUp())
            ->post(route('oauth.mandate.grant'), ['max_per_transaction' => '50.00']);

        $response->assertRedirect(self::RETURN_URL . '?mandate=granted');

        $mandate = PaymentMandate::sole();

        $this->assertSame($user->id, $mandate->user_id);
        $this->assertSame($account->id, $mandate->account_id);
        $this->assertSame(self::CLIENT_ID, $mandate->client_id);
        $this->assertSame(5000, $mandate->max_per_transaction);
        $this->assertSame([$sellerAccount->uuid], $mandate->authorized_sellers);
        $this->assertTrue($mandate->isActive());
    }

    public function test_il_mandato_scade_da_solo_dopo_dodici_mesi(): void
    {
        [$user] = $this->buyer();

        $this->actingAs($user)->withSession($this->stepUp())->get($this->mandateUrl());
        $this->actingAs($user)->withSession($this->stepUp())
            ->post(route('oauth.mandate.grant'), ['max_per_transaction' => '50.00']);

        $mandate = PaymentMandate::sole();

        $this->assertEqualsWithDelta(
            365,
            now()->diffInDays($mandate->expires_at),
            2,
            'Il mandato deve scadere circa 12 mesi dopo.'
        );
    }

    public function test_l_utente_puo_scegliere_un_tetto_diverso(): void
    {
        [$user] = $this->buyer();

        $this->actingAs($user)->withSession($this->stepUp())->get($this->mandateUrl());
        $this->actingAs($user)->withSession($this->stepUp())
            ->post(route('oauth.mandate.grant'), ['max_per_transaction' => '25,50']);

        $this->assertSame(2550, PaymentMandate::sole()->max_per_transaction);
    }

    public function test_un_tetto_fuori_dai_limiti_viene_rifiutato(): void
    {
        [$user] = $this->buyer();

        $this->actingAs($user)->withSession($this->stepUp())->get($this->mandateUrl());

        $this->actingAs($user)->withSession($this->stepUp())
            ->post(route('oauth.mandate.grant'), ['max_per_transaction' => '99999.00'])
            ->assertSessionHasErrors('max_per_transaction');

        $this->assertSame(0, PaymentMandate::count());
    }

    public function test_autorizzare_di_nuovo_sostituisce_il_permesso_precedente(): void
    {
        [$user] = $this->buyer();

        $this->actingAs($user)->withSession($this->stepUp())->get($this->mandateUrl());
        $this->actingAs($user)->withSession($this->stepUp())
            ->post(route('oauth.mandate.grant'), ['max_per_transaction' => '50.00']);

        $this->actingAs($user)->withSession($this->stepUp())->get($this->mandateUrl());
        $this->actingAs($user)->withSession($this->stepUp())
            ->post(route('oauth.mandate.grant'), ['max_per_transaction' => '30.00']);

        // Due righe in tabella, ma un solo permesso vivo: niente autorizzazioni
        // dimenticate in giro.
        $this->assertSame(2, PaymentMandate::count());
        $this->assertSame(1, PaymentMandate::whereNull('revoked_at')->count());
        $this->assertSame(3000, PaymentMandate::whereNull('revoked_at')->sole()->max_per_transaction);
    }

    public function test_la_concessione_lascia_traccia_in_audit_log(): void
    {
        [$user] = $this->buyer();

        $this->actingAs($user)->withSession($this->stepUp())->get($this->mandateUrl());
        $this->actingAs($user)->withSession($this->stepUp())
            ->post(route('oauth.mandate.grant'), ['max_per_transaction' => '50.00']);

        $this->assertTrue(AuditLog::query()->where('event', 'mandate.granted')->exists());
    }

    public function test_rifiutare_non_crea_nessun_mandato(): void
    {
        [$user] = $this->buyer();

        $this->actingAs($user)->withSession($this->stepUp())->get($this->mandateUrl());

        $this->actingAs($user)->withSession($this->stepUp())
            ->delete(route('oauth.mandate.deny'))
            ->assertRedirect(self::RETURN_URL . '?mandate=denied');

        $this->assertSame(0, PaymentMandate::count());
        $this->assertTrue(AuditLog::query()->where('event', 'mandate.denied')->exists());
    }

    public function test_autorizzare_senza_una_richiesta_in_corso_non_crea_niente(): void
    {
        [$user] = $this->buyer();

        $this->actingAs($user)->withSession($this->stepUp())
            ->post(route('oauth.mandate.grant'), ['max_per_transaction' => '50.00'])
            ->assertRedirect(route('portal.dashboard'));

        $this->assertSame(0, PaymentMandate::count());
    }

    // =========================================================================
    // Helper
    // =========================================================================

    /**
     * @param array<string, string> $overrides
     */
    private function mandateUrl(array $overrides = []): string
    {
        return route('oauth.mandate', array_merge([
            'client_id'  => self::CLIENT_ID,
            'return_url' => self::RETURN_URL,
        ], $overrides));
    }

    /**
     * @return array<string, int>
     */
    private function stepUp(): array
    {
        return ['step_up_verified_at' => now()->timestamp];
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

    /**
     * @return array{0: Account, 1: Company}
     */
    private function seller(): array
    {
        $slug = 'venditore-' . Str::random(6);

        $company = Company::create([
            'name'          => 'Bottega ' . Str::random(4),
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
            'available_balance' => 0,
        ]);

        return [$account->fresh(), $company->fresh()];
    }
}
