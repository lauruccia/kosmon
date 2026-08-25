<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\PaymentMandate;
use App\Models\PaymentMandateCharge;
use App\Models\Transfer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * "Riattiva" — fase 2b.
 *
 * L'antifurto della 2a sospende il mandato da solo dopo dieci addebiti in
 * un'ora. Era la difesa giusta, ma senza via d'uscita: l'utente poteva soltanto
 * revocare e rifare tutta la cerimonia da capo, magari per un allarme che aveva
 * fatto scattare lui comprando otto regali in mezz'ora.
 *
 * Due cose vanno tenute insieme, e la seconda è quella che di solito si
 * dimentica:
 *
 *  1. **riaccendere è ridare un permesso**, quindi sta dietro allo step-up
 *     come alzare il tetto — non come revocare, che resta senza cerimonie;
 *  2. **riaccendere deve funzionare davvero.** Se la finestra dell'antifurto
 *     non riparte, i dieci addebiti sono ancora lì e il primo acquisto dopo la
 *     riattivazione fa scattare tutto un'altra volta: un bottone che sembra
 *     funzionare e non funziona è peggio di nessun bottone.
 */
class MandateReactivationTest extends TestCase
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
    // 1. La cerimonia
    // =========================================================================

    public function test_riattivare_richiede_lo_step_up(): void
    {
        [$user, $account] = $this->buyer();
        $mandate          = $this->sospeso($user, $account);

        $this->actingAs($user)
            ->post(route('portal.connected-apps.reactivate', $mandate->uuid))
            ->assertRedirect(route('portal.step-up.show'));

        $this->assertTrue($mandate->fresh()->isSuspended());
    }

    public function test_con_lo_step_up_l_autorizzazione_torna_attiva(): void
    {
        [$user, $account] = $this->buyer();
        $mandate          = $this->sospeso($user, $account);

        $this->actingAs($user)
            ->withSession(['step_up_verified_at' => now()->timestamp])
            ->post(route('portal.connected-apps.reactivate', $mandate->uuid))
            ->assertRedirect(route('portal.connected-apps.index'));

        $mandate->refresh();

        $this->assertFalse($mandate->isSuspended());
        $this->assertTrue($mandate->isActive());
        $this->assertNotNull($mandate->reactivated_at);

        $this->assertTrue(
            AuditLog::query()->where('event', 'mandate.reactivated')->exists(),
            'Riaccendere un permesso deve lasciare una traccia, come accenderlo la prima volta.'
        );
    }

    public function test_il_bottone_compare_solo_su_un_mandato_sospeso(): void
    {
        [$user, $account] = $this->buyer();
        $mandate          = $this->mandate($user, $account);

        $this->actingAs($user)
            ->get(route('portal.connected-apps.index'))
            ->assertOk()
            ->assertDontSee('Riattiva');

        $mandate->forceFill(['suspended_at' => now()])->save();

        $this->actingAs($user)
            ->get(route('portal.connected-apps.index'))
            ->assertOk()
            ->assertSee('Riattiva');
    }

    public function test_un_mandato_di_un_altro_utente_non_si_tocca(): void
    {
        [$user, $account] = $this->buyer();
        [$estraneo]       = $this->buyer();
        $mandate          = $this->sospeso($user, $account);

        $this->actingAs($estraneo)
            ->withSession(['step_up_verified_at' => now()->timestamp])
            ->post(route('portal.connected-apps.reactivate', $mandate->uuid))
            ->assertNotFound();

        $this->assertTrue($mandate->fresh()->isSuspended());
    }

    public function test_un_mandato_revocato_non_si_riaccende(): void
    {
        [$user, $account] = $this->buyer();
        $mandate          = $this->sospeso($user, $account);
        $mandate->forceFill(['revoked_at' => now()])->save();

        $this->actingAs($user)
            ->withSession(['step_up_verified_at' => now()->timestamp])
            ->post(route('portal.connected-apps.reactivate', $mandate->uuid))
            ->assertRedirect(route('portal.connected-apps.index'));

        $this->assertTrue($mandate->fresh()->isRevoked());
        $this->assertTrue($mandate->fresh()->isSuspended());
    }

    public function test_un_mandato_scaduto_non_si_riaccende(): void
    {
        [$user, $account] = $this->buyer();
        $mandate          = $this->sospeso($user, $account);
        $mandate->forceFill(['expires_at' => now()->subDay()])->save();

        $this->actingAs($user)
            ->withSession(['step_up_verified_at' => now()->timestamp])
            ->post(route('portal.connected-apps.reactivate', $mandate->uuid));

        $this->assertTrue($mandate->fresh()->isSuspended());
    }

    // =========================================================================
    // 2. Riaccendere deve servire a qualcosa
    // =========================================================================

    public function test_la_finestra_dell_antifurto_riparte_dalla_riattivazione(): void
    {
        config()->set('oauth.mandate.rate_limit.max_charges', 3);

        [$user, $account] = $this->buyer();
        [$seller]         = $this->seller();
        $mandate          = $this->mandate($user, $account, [$seller->uuid]);

        // Tre addebiti automatici: il quarto farebbe scattare l'antifurto.
        foreach (['a', 'b', 'c'] as $chiave) {
            PaymentMandateCharge::create([
                'payment_mandate_id'    => $mandate->id,
                'transfer_id'           => $this->transfer($user, $account, $seller)->id,
                'amount'                => 500,
                'seller_account_number' => $seller->uuid,
                'idempotency_key'       => $chiave,
            ]);
        }

        $mandate->forceFill(['suspended_at' => now()])->save();
        $this->assertSame(3, $mandate->fresh()->recentChargesCount());

        $this->actingAs($user)
            ->withSession(['step_up_verified_at' => now()->timestamp])
            ->post(route('portal.connected-apps.reactivate', $mandate->uuid));

        // Il punto: senza il ripartire della finestra il contatore sarebbe
        // ancora 3, e il primo acquisto dopo la riattivazione ri-sospenderebbe
        // il mandato un secondo dopo averlo riacceso.
        $this->assertSame(0, $mandate->fresh()->recentChargesCount());
        $this->assertFalse($mandate->fresh()->hasHitRateLimit());
    }

    public function test_riattivare_non_cambia_ne_il_tetto_ne_i_venditori(): void
    {
        [$user, $account] = $this->buyer();
        [$seller]         = $this->seller();
        $mandate          = $this->sospeso($user, $account, [$seller->uuid]);

        $this->actingAs($user)
            ->withSession(['step_up_verified_at' => now()->timestamp])
            ->post(route('portal.connected-apps.reactivate', $mandate->uuid));

        $mandate->refresh();

        $this->assertSame(5000, $mandate->max_per_transaction);
        $this->assertSame([$seller->uuid], $mandate->authorized_sellers);
    }

    // =========================================================================

    private function sospeso(User $user, Account $account, array $sellers = []): PaymentMandate
    {
        $mandate = $this->mandate($user, $account, $sellers);
        $mandate->forceFill(['suspended_at' => now()])->save();

        return $mandate->fresh();
    }

    private function mandate(User $user, Account $account, array $sellers = [], int $cap = 5000): PaymentMandate
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

    private function transfer(User $user, Account $from, Account $to): Transfer
    {
        return app(\App\Services\TransferBookingService::class)->book([
            'initiated_by'    => $user->id,
            'from_account_id' => $from->id,
            'to_account_id'   => $to->id,
            'amount'          => 500,
            'kind'            => 'portal_marketplace_order',
            'idempotency_key' => 'mov-' . Str::random(10),
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
