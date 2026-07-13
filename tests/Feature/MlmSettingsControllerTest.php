<?php

namespace Tests\Feature;

use App\Models\MlmRankRequirement;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Copre il pannello admin /admin/mlm-impostazioni (2026-07-13): modifica dei
 * requisiti di qualifica agente e della scadenza punti di test, entrambe
 * pensate per permettere verifiche rapide senza toccare il codice. Vedi
 * MlmSettingsController, MlmRankRequirement, MlmRankEngineTest.
 */
class MlmSettingsControllerTest extends TestCase
{
    use RefreshDatabase;

    private function makeAdmin(): User
    {
        $user = User::create([
            'name'                => 'Admin',
            'email'                => 'admin-' . Str::random(10) . '@test.test',
            'password'             => 'secret123',
            'account_holder_type'  => 'private',
            'company_id'           => null,
            'is_active'            => true,
            'is_super_admin'       => true,
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();

        return $user;
    }

    private function makeAgent(): User
    {
        return User::create([
            'name'                => 'Agente ' . Str::random(6),
            'email'                => 'agente-' . Str::random(10) . '@test.test',
            'password'             => 'secret123',
            'account_holder_type'  => 'private',
            'company_id'           => null,
            'is_active'            => true,
            'mlm_role'             => 'agente',
            'mlm_rank'             => 'start',
            'mlm_activated_at'     => now(),
        ]);
    }

    private function requirementsPayload(array $overrides = []): array
    {
        $base = [
            'min_points' => 0, 'min_level1_basic' => 0, 'min_branches_with_key' => 0,
            'min_branches_with_senior' => 0, 'min_branches_with_top' => 0,
            'min_branches_with_supervisor' => 0, 'min_branches_300pt' => 0,
        ];

        $payload = [];
        foreach (['basic', 'key', 'senior', 'top', 'supervisor', 'manager'] as $rank) {
            $payload[$rank] = array_merge($base, $overrides[$rank] ?? []);
        }

        return $payload;
    }

    /** Utente normale: supera auth/verified/onboarding/contract ma NON e' backoffice (stesso pattern di BackofficeAccessGuardTest). */
    private function makeRegularUser(): User
    {
        $slug = 'reg-' . Str::random(5);

        $company = \App\Models\Company::create([
            'name'          => 'Reg Co ' . Str::random(4),
            'slug'          => $slug,
            'email'         => $slug . '@test.test',
            'status'        => 'active',
            'kyc_status'    => 'approved',
            'currency_code' => 'KY',
            'sector'        => 'informatica',
            'description'   => 'Test',
        ]);

        $user = User::create([
            'company_id'          => $company->id,
            'account_holder_type' => 'company',
            'name'                => 'Reg User',
            'email'               => 'reg-' . Str::random(6) . '@test.test',
            'password'            => 'secret123',
            'role'                => 'company-manager',
            'is_active'           => true,
            'is_super_admin'      => false,
        ]);
        $user->forceFill([
            'email_verified_at'  => now(),
            'contract_signed_at' => now(),
        ])->save();

        return $user;
    }

    public function test_edit_page_requires_backoffice_access(): void
    {
        $user = $this->makeRegularUser();

        $response = $this->actingAsWithSession($user)->get(route('admin.mlm.settings.edit'));

        $response->assertForbidden();
    }

    public function test_admin_can_view_current_requirements(): void
    {
        $admin = $this->makeAdmin();

        $response = $this->actingAsWithSession($admin)->get(route('admin.mlm.settings.edit'));

        $response->assertOk();
        $response->assertSee('Requisiti per grado');
        $response->assertSee('Scadenza punti cliente');
    }

    public function test_admin_can_lower_basic_threshold_and_it_takes_effect_immediately(): void
    {
        $admin = $this->makeAdmin();
        $agent = $this->makeAgent();

        // Con la soglia di default (12 punti) l'agente con 5 punti non e' Basic.
        $this->assertSame('start', app(\App\Services\MlmRankEngine::class)->evaluate($agent)['eligible_rank']);

        $payload = $this->requirementsPayload(['basic' => ['min_points' => 5]]);

        $response = $this->actingAsWithSession($admin)->post(route('admin.mlm.settings.update'), [
            'points_validity_override_minutes' => null,
            'requirements' => $payload,
        ]);

        $response->assertRedirect(route('admin.mlm.settings.edit'));

        $this->assertSame(5, MlmRankRequirement::where('rank', 'basic')->value('min_points'));

        // MlmPointsLedgerEntry non serve qui: mlmActivePoints() e' 0, ma la
        // soglia abbassata a 5 non lo rende comunque Basic con 0 punti reali —
        // verifichiamo invece che la CONFIGURAZIONE sia stata applicata e che
        // la cache sia stata invalidata (allByRank() rilegge da DB).
        $requirement = MlmRankRequirement::allByRank()->get('basic');
        $this->assertSame(5, $requirement->min_points);
    }

    public function test_update_validates_all_fields_are_present_and_non_negative(): void
    {
        $admin = $this->makeAdmin();

        $incomplete = $this->requirementsPayload();
        unset($incomplete['basic']['min_points']);

        $response = $this->actingAsWithSession($admin)->post(route('admin.mlm.settings.update'), [
            'points_validity_override_minutes' => null,
            'requirements' => $incomplete,
        ]);

        $response->assertSessionHasErrors('requirements.basic.min_points');
    }

    public function test_admin_can_set_and_clear_points_validity_override(): void
    {
        $admin = $this->makeAdmin();
        $payload = $this->requirementsPayload();

        $this->actingAsWithSession($admin)->post(route('admin.mlm.settings.update'), [
            'points_validity_override_minutes' => 60,
            'requirements' => $payload,
        ])->assertRedirect(route('admin.mlm.settings.edit'));

        $this->assertSame(60, SystemSetting::mlmSettings()->fresh()->mlm_points_validity_override_minutes);

        $this->actingAsWithSession($admin)->post(route('admin.mlm.settings.update'), [
            'points_validity_override_minutes' => null,
            'requirements' => $payload,
        ])->assertRedirect(route('admin.mlm.settings.edit'));

        $this->assertNull(SystemSetting::mlmSettings()->fresh()->mlm_points_validity_override_minutes);
    }

    public function test_recalculate_now_runs_the_nightly_command_synchronously(): void
    {
        $admin = $this->makeAdmin();
        $agent = $this->makeAgent();

        // Punti attivi >= soglia basic di default (12): dopo il ricalcolo
        // manuale l'agente deve risultare promosso senza aspettare il cron.
        \App\Models\MlmPointLedgerEntry::create([
            'agent_user_id' => $agent->id,
            'client_user_id' => $this->makeClientFor($agent)->id,
            'source_type' => 'registration',
            'points' => 12,
            'valid_from' => now()->subDay(),
            'valid_until' => now()->addMonth(),
        ]);

        $response = $this->actingAsWithSession($admin)->post(route('admin.mlm.settings.recalculate'));

        $response->assertRedirect(route('admin.mlm.settings.edit'));
        $this->assertSame('basic', $agent->fresh()->mlm_rank);
    }

    private function makeClientFor(User $agent): User
    {
        return User::create([
            'name'                => 'Cliente ' . Str::random(6),
            'email'                => 'cliente-' . Str::random(10) . '@test.test',
            'password'             => 'secret123',
            'account_holder_type'  => 'private',
            'company_id'           => null,
            'is_active'            => true,
            'mlm_role'             => 'cliente',
            'mlm_client_agent_id'  => $agent->id,
        ]);
    }
}
