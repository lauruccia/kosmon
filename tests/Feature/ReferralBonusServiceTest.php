<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\Transfer;
use App\Models\User;
use App\Services\ReferralBonusService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Punto 3 del 27/07 — bonus KY per segnalazioni (amico/agente/attività).
 * Vedi ReferralBonusService per la logica (non cumulativa, livelli dedotti
 * automaticamente da cosa fa l'invitato).
 */
class ReferralBonusServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeSuperAdmin(): User
    {
        $user = User::create([
            'name'                => 'Super Admin',
            'email'               => 'superadmin-' . Str::random(6) . '@test.test',
            'password'            => 'secret123',
            'account_holder_type' => 'private',
            'is_active'           => true,
            'is_super_admin'      => true,
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();

        return $user;
    }

    /** Utente privato con conto attivo, referral_code generato. Usato come segnalante. */
    private function makeReferrer(): User
    {
        $referrer = User::create([
            'name'                => 'Referrer',
            'email'               => 'referrer-' . Str::random(6) . '@test.test',
            'password'            => 'secret123',
            'account_holder_type' => 'private',
            'is_active'           => true,
            'is_super_admin'      => false,
        ]);
        $referrer->forceFill(['email_verified_at' => now()])->save();

        Account::create([
            'owner_user_id'      => $referrer->id,
            'owner_type'         => 'private',
            'type'               => 'primary',
            'account_name'       => 'Conto personale Referrer',
            'currency_code'      => 'KY',
            'status'             => 'active',
            'available_balance'  => 0,
        ]);

        $referrer->referralCode(); // genera referral_code

        return $referrer->refresh();
    }

    private function referrerBalance(User $referrer): int
    {
        return (int) Account::where('owner_user_id', $referrer->id)
            ->whereNull('parent_account_id')
            ->firstOrFail()
            ->available_balance;
    }

    // ── Tier "amico" via registrazione reale ────────────────────────────────

    /**
     * Dal 28/08/2026 il bonus "amico" NON esce piu' all'invio del form di
     * registrazione ma all'email verificata: prima bastava registrarsi con un
     * indirizzo inesistente per far uscire 10 KY dal conto dell'agente di
     * riferimento, all'infinito. Vedi App\Listeners\AwardFriendReferralBonus.
     */
    public function test_amico_bonus_awarded_to_referrer_only_after_email_is_verified(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->makeSuperAdmin();
        $referrer = $this->makeReferrer();

        $response = $this->post('/register', [
            'account_holder_type' => 'private',
            'name'    => 'Amico Invitato',
            'email'   => 'amico@example.test',
            'phone'   => '333999888',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
            'ref'     => $referrer->referral_code,
        ]);

        $response->assertRedirect('/dashboard');

        $invited = User::where('email', 'amico@example.test')->firstOrFail();
        $this->assertSame($referrer->id, $invited->referred_by_user_id);

        // Registrato ma non verificato: ancora niente.
        $this->assertSame(0, $this->referrerBalance($referrer));
        $this->assertSame(0, Transfer::where('idempotency_key', "referral_bonus_{$invited->id}_amico")->count());

        // L'utente apre il link di verifica: e' qui che il bonus matura.
        $invited->markEmailAsVerified();
        event(new \Illuminate\Auth\Events\Verified($invited));

        $invited->refresh();

        $this->assertSame(1000, $this->referrerBalance($referrer)); // 10,00 KY default
        $this->assertSame(1000, $invited->referral_bonus_paid_amount);
        $this->assertSame('amico', $invited->referral_bonus_tier);

        $this->assertSame(1, Transfer::where('idempotency_key', "referral_bonus_{$invited->id}_amico")->count());
    }

    public function test_no_bonus_without_referral_code(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->makeSuperAdmin();

        $response = $this->post('/register', [
            'account_holder_type' => 'private',
            'name'    => 'Nessun Referral',
            'email'   => 'noref@example.test',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ]);

        $response->assertRedirect('/dashboard');
        $this->assertSame(0, Transfer::where('kind', 'portal_cashback')->where('description', 'like', 'Bonus segnalazione%')->count());
    }

    public function test_company_registration_does_not_trigger_amico_bonus(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->makeSuperAdmin();
        $referrer = $this->makeReferrer();

        $response = $this->post('/register', [
            'account_holder_type' => 'company',
            'name'    => 'Titolare Azienda',
            'email'   => 'azienda@example.test',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
            'company_name' => 'Azienda Invitata SRL',
            'ref'     => $referrer->referral_code,
        ]);

        $response->assertRedirect('/dashboard');
        $this->assertSame(0, $this->referrerBalance($referrer)); // niente bonus finché non arriva il KYC
    }

    // ── Tier "attività" via approvazione KYC reale ──────────────────────────

    public function test_attivita_bonus_awarded_to_referrer_on_kyc_approval(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = $this->makeSuperAdmin();
        $referrer = $this->makeReferrer();

        $company = Company::create([
            'name'          => 'Attività Invitata',
            'slug'          => 'attivita-invitata-' . Str::random(4),
            'email'         => 'attivita-invitata@test.test',
            'status'        => 'active',
            'kyc_status'    => 'under_review',
            'currency_code' => 'KY',
        ]);

        $companyUser = User::create([
            'company_id'          => $company->id,
            'account_holder_type' => 'company',
            'name'                => 'Titolare Attività',
            'email'               => 'titolare-' . Str::random(6) . '@test.test',
            'password'            => 'secret123',
            'is_active'           => true,
            'referred_by_user_id' => $referrer->id,
        ]);
        $companyUser->forceFill(['email_verified_at' => now()])->save();

        Account::create([
            'company_id'        => $company->id,
            'owner_user_id'     => $companyUser->id,
            'owner_type'        => 'company',
            'type'              => 'primary',
            'account_name'      => 'Conto Attività Invitata',
            'currency_code'     => 'KY',
            'status'            => 'active',
            'available_balance' => 0,
        ]);

        $this->actingAs($admin)->post("/admin/kyc/{$company->id}/approve", ['notes' => 'ok']);

        $this->assertSame(10000, $this->referrerBalance($referrer)); // 100,00 KY default

        $companyUser->refresh();
        $this->assertSame(10000, $companyUser->referral_bonus_paid_amount);
        $this->assertSame('attivita', $companyUser->referral_bonus_tier);
    }

    // ── Tier "agente" + comportamento non cumulativo (chiamata diretta al servizio) ──

    public function test_agente_tier_tops_up_delta_only_when_amico_already_paid(): void
    {
        $referrer = $this->makeReferrer();
        $this->makeSuperAdmin();

        $invited = User::create([
            'name'                => 'Futuro Agente',
            'email'               => 'futuroagente@test.test',
            'password'            => 'secret123',
            'account_holder_type' => 'private',
            'is_active'           => true,
            'referred_by_user_id' => $referrer->id,
        ]);
        $invited->forceFill(['email_verified_at' => now()])->save();

        $service = app(ReferralBonusService::class);

        // Prima il livello "amico" (come alla registrazione)
        $service->awardTierOrFail($invited, ReferralBonusService::TIER_AMICO);
        $this->assertSame(1000, $this->referrerBalance($referrer));

        // Poi diventa anche agente: deve arrivare SOLO la differenza (5000-1000=4000), non 5000 pieni
        $service->awardTierOrFail($invited, ReferralBonusService::TIER_AGENTE);
        $this->assertSame(5000, $this->referrerBalance($referrer)); // 1000 + 4000, mai 1000+5000=6000

        $invited->refresh();
        $this->assertSame(5000, $invited->referral_bonus_paid_amount);
        $this->assertSame('agente', $invited->referral_bonus_tier);

        $this->assertSame(1, Transfer::where('idempotency_key', "referral_bonus_{$invited->id}_amico")->count());
        $this->assertSame(1, Transfer::where('idempotency_key', "referral_bonus_{$invited->id}_agente")->count());
    }

    public function test_awarding_same_tier_twice_is_idempotent(): void
    {
        $referrer = $this->makeReferrer();
        $this->makeSuperAdmin();

        $invited = User::create([
            'name'                => 'Doppio Trigger',
            'email'               => 'doppiotrigger@test.test',
            'password'            => 'secret123',
            'account_holder_type' => 'private',
            'is_active'           => true,
            'referred_by_user_id' => $referrer->id,
        ]);
        $invited->forceFill(['email_verified_at' => now()])->save();

        $service = app(ReferralBonusService::class);

        $service->awardTierOrFail($invited, ReferralBonusService::TIER_AMICO);
        $service->awardTierOrFail($invited, ReferralBonusService::TIER_AMICO); // replay

        $this->assertSame(1000, $this->referrerBalance($referrer)); // non 2000
        $this->assertSame(1, Transfer::where('idempotency_key', "referral_bonus_{$invited->id}_amico")->count());
    }

    // ── Idoneità del segnalante: solo privati "normali" ──────────────────────

    public function test_no_bonus_when_referrer_is_a_company(): void
    {
        $company = Company::create([
            'name'          => 'Azienda Segnalante',
            'slug'          => 'azienda-segnalante-' . Str::random(4),
            'email'         => 'azienda-segnalante@test.test',
            'status'        => 'active',
            'kyc_status'    => 'approved',
            'currency_code' => 'KY',
        ]);

        $referrer = User::create([
            'company_id'          => $company->id,
            'account_holder_type' => 'company',
            'name'                => 'Titolare Azienda Segnalante',
            'email'               => 'titolare-segnalante-' . Str::random(6) . '@test.test',
            'password'            => 'secret123',
            'is_active'           => true,
        ]);
        $referrer->forceFill(['email_verified_at' => now()])->save();

        Account::create([
            'company_id'        => $company->id,
            'owner_user_id'     => $referrer->id,
            'owner_type'        => 'company',
            'type'              => 'primary',
            'account_name'      => 'Conto Azienda Segnalante',
            'currency_code'     => 'KY',
            'status'            => 'active',
            'available_balance' => 0,
        ]);

        $this->makeSuperAdmin();

        $invited = User::create([
            'name'                => 'Invitato da Azienda',
            'email'               => 'invitatoazienda@test.test',
            'password'            => 'secret123',
            'account_holder_type' => 'private',
            'is_active'           => true,
            'referred_by_user_id' => $referrer->id,
        ]);
        $invited->forceFill(['email_verified_at' => now()])->save();

        app(ReferralBonusService::class)->awardTierOrFail($invited, ReferralBonusService::TIER_AMICO);

        $this->assertSame(0, $this->referrerBalance($referrer)); // nessun bonus: il segnalante è un'azienda
        $this->assertSame(0, Transfer::where('idempotency_key', "referral_bonus_{$invited->id}_amico")->count());

        $invited->refresh();
        $this->assertSame(0, $invited->referral_bonus_paid_amount);
        $this->assertNull($invited->referral_bonus_tier);
    }

    public function test_no_bonus_when_referrer_is_an_mlm_agent(): void
    {
        $referrer = $this->makeReferrer();
        $referrer->forceFill(['mlm_role' => 'agente'])->save();

        $this->makeSuperAdmin();

        $invited = User::create([
            'name'                => 'Invitato da Agente',
            'email'               => 'invitatoagente@test.test',
            'password'            => 'secret123',
            'account_holder_type' => 'private',
            'is_active'           => true,
            'referred_by_user_id' => $referrer->id,
        ]);
        $invited->forceFill(['email_verified_at' => now()])->save();

        app(ReferralBonusService::class)->awardTierOrFail($invited, ReferralBonusService::TIER_AMICO);

        $this->assertSame(0, $this->referrerBalance($referrer)); // nessun bonus: il segnalante è già un agente KNM
        $this->assertSame(0, Transfer::where('idempotency_key', "referral_bonus_{$invited->id}_amico")->count());
    }

    public function test_no_bonus_when_amount_disabled(): void
    {
        $referrer = $this->makeReferrer();
        $this->makeSuperAdmin();

        \App\Models\SystemSetting::userLimitDefaults()->forceFill(['referral_bonus_amico_amount' => 0])->save();

        $invited = User::create([
            'name'                => 'Livello Disattivato',
            'email'               => 'disattivato@test.test',
            'password'            => 'secret123',
            'account_holder_type' => 'private',
            'is_active'           => true,
            'referred_by_user_id' => $referrer->id,
        ]);
        $invited->forceFill(['email_verified_at' => now()])->save();

        app(ReferralBonusService::class)->awardTierOrFail($invited, ReferralBonusService::TIER_AMICO);

        $this->assertSame(0, $this->referrerBalance($referrer));
        $this->assertSame(0, Transfer::where('idempotency_key', "referral_bonus_{$invited->id}_amico")->count());
    }

    // ── Chi paga il bonus (28/07/2026): amico/agente -> agente del cliente, attività -> conto madre ──

    /** Crea un agente KNM con un proprio conto attivo (saldo iniziale a scelta). */
    private function makeAgentWithAccount(int $initialBalance = 0): User
    {
        $agent = User::create([
            'name'                => 'Agente Riferimento',
            'email'               => 'agente-' . Str::random(6) . '@test.test',
            'password'            => 'secret123',
            'account_holder_type' => 'private',
            'is_active'           => true,
            'is_super_admin'      => false,
            'mlm_role'            => 'agente',
        ]);
        $agent->forceFill(['email_verified_at' => now()])->save();

        Account::create([
            'owner_user_id'      => $agent->id,
            'owner_type'         => 'private',
            'type'               => 'primary',
            'account_name'       => 'Conto Agente',
            'currency_code'      => 'KY',
            'status'             => 'active',
            'available_balance'  => $initialBalance,
        ]);

        return $agent->refresh();
    }

    private function accountBalance(User $user): int
    {
        return (int) Account::where('owner_user_id', $user->id)
            ->whereNull('parent_account_id')
            ->firstOrFail()
            ->available_balance;
    }

    public function test_amico_bonus_is_charged_to_referrers_agent_when_assigned(): void
    {
        $referrer = $this->makeReferrer();
        $this->makeSuperAdmin();

        $agent = $this->makeAgentWithAccount(5000); // 50,00 KY di partenza, copre il bonus amico (10 KY)
        $referrer->forceFill(['mlm_client_agent_id' => $agent->id])->save();

        $invited = User::create([
            'name'                => 'Amico Con Agente Assegnato',
            'email'               => 'amicoagente@test.test',
            'password'            => 'secret123',
            'account_holder_type' => 'private',
            'is_active'           => true,
            'referred_by_user_id' => $referrer->id,
        ]);
        $invited->forceFill(['email_verified_at' => now()])->save();

        app(ReferralBonusService::class)->awardTierOrFail($invited, ReferralBonusService::TIER_AMICO);

        // Il cliente riceve comunque i 10 KY...
        $this->assertSame(1000, $this->referrerBalance($referrer));
        // ...ma a pagarli è il SUO agente, non il conto madre.
        $this->assertSame(4000, $this->accountBalance($agent)); // 5000 - 1000

        $transfer = Transfer::where('idempotency_key', "referral_bonus_{$invited->id}_amico")->firstOrFail();
        $agentAccount = Account::where('owner_user_id', $agent->id)->whereNull('parent_account_id')->firstOrFail();
        $this->assertSame($agentAccount->id, $transfer->from_account_id);

        $this->assertSame(
            1,
            $agent->fresh()->notifications()
                ->where('type', \App\Notifications\ReferralBonusChargedToAgentNotification::class)
                ->count()
        );
    }

    public function test_amico_bonus_still_paid_by_system_account_when_referrer_has_no_agent_assigned(): void
    {
        // Stesso comportamento di prima di questa modifica: il segnalante non
        // ha nessun agente di riferimento (mlm_client_agent_id nullo, es. mlm
        // disattivato) -> il bonus non deve MAI bloccarsi, paga il conto madre.
        $referrer = $this->makeReferrer();
        $this->makeSuperAdmin();

        $invited = User::create([
            'name'                => 'Amico Senza Agente Assegnato',
            'email'               => 'amiconoagente@test.test',
            'password'            => 'secret123',
            'account_holder_type' => 'private',
            'is_active'           => true,
            'referred_by_user_id' => $referrer->id,
        ]);
        $invited->forceFill(['email_verified_at' => now()])->save();

        app(ReferralBonusService::class)->awardTierOrFail($invited, ReferralBonusService::TIER_AMICO);

        $this->assertSame(1000, $this->referrerBalance($referrer));

        $transfer = Transfer::where('idempotency_key', "referral_bonus_{$invited->id}_amico")->firstOrFail();
        $systemAccount = Account::systemAccount();
        $this->assertSame($systemAccount->id, $transfer->from_account_id);
    }

    public function test_attivita_bonus_is_always_paid_by_system_account_even_with_agent_assigned(): void
    {
        // Anche se il segnalante ha un agente assegnato, il livello attività
        // (100 KY, segnalazione di un'azienda) resta a carico del conto madre.
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = $this->makeSuperAdmin();
        $referrer = $this->makeReferrer();

        $agent = $this->makeAgentWithAccount(5000);
        $referrer->forceFill(['mlm_client_agent_id' => $agent->id])->save();

        $company = Company::create([
            'name'          => 'Attività Con Agente Assegnato',
            'slug'          => 'attivita-agente-assegnato-' . Str::random(4),
            'email'         => 'attivita-agente-assegnato@test.test',
            'status'        => 'active',
            'kyc_status'    => 'under_review',
            'currency_code' => 'KY',
        ]);

        $companyUser = User::create([
            'company_id'          => $company->id,
            'account_holder_type' => 'company',
            'name'                => 'Titolare Attività Con Agente',
            'email'               => 'titolare-agente-' . Str::random(6) . '@test.test',
            'password'            => 'secret123',
            'is_active'           => true,
            'referred_by_user_id' => $referrer->id,
        ]);
        $companyUser->forceFill(['email_verified_at' => now()])->save();

        Account::create([
            'company_id'        => $company->id,
            'owner_user_id'     => $companyUser->id,
            'owner_type'        => 'company',
            'type'              => 'primary',
            'account_name'      => 'Conto Attività Con Agente',
            'currency_code'     => 'KY',
            'status'            => 'active',
            'available_balance' => 0,
        ]);

        $this->actingAs($admin)->post("/admin/kyc/{$company->id}/approve", ['notes' => 'ok']);

        $this->assertSame(10000, $this->referrerBalance($referrer)); // 100,00 KY default

        // Il conto dell'agente NON viene toccato per il livello attività.
        $this->assertSame(5000, $this->accountBalance($agent));

        $transfer = Transfer::where('idempotency_key', "referral_bonus_{$companyUser->id}_attivita")->firstOrFail();
        $systemAccount = Account::systemAccount();
        $this->assertSame($systemAccount->id, $transfer->from_account_id);
    }
}
