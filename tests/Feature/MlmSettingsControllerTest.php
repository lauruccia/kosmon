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
            'min_points' => 0, 'min_clients' => 0, 'min_level1_basic' => 0, 'min_branches_with_key' => 0,
            'min_branches_with_senior' => 0, 'min_branches_with_top' => 0,
            'min_branches_with_supervisor' => 0, 'min_branches_300pt' => 0,
        ];

        $payload = [];
        foreach (['basic', 'key', 'senior', 'top', 'supervisor', 'manager'] as $rank) {
            $payload[$rank] = array_merge($base, $overrides[$rank] ?? []);
        }

        return $payload;
    }

    /** Payload valido per la tabella "punti per evento" (2026-07-22): i valori del seed. */
    private function pointRulesPayload(): array
    {
        return [
            'registration_points' => 1,
            'registration_duration_days' => 90,
            'deposit_rules' => [
                ['amount_eur' => 120, 'points' => 2, 'duration_days' => 30],
                ['amount_eur' => 600, 'points' => 2, 'duration_days' => 180],
                ['amount_eur' => 1200, 'points' => 2, 'duration_days' => 360],
            ],
        ];
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
        ] + $this->pointRulesPayload());

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
        ] + $this->pointRulesPayload());

        $response->assertSessionHasErrors('requirements.basic.min_points');
    }

    public function test_admin_can_set_and_clear_points_validity_override(): void
    {
        $admin = $this->makeAdmin();
        $payload = $this->requirementsPayload();

        $this->actingAsWithSession($admin)->post(route('admin.mlm.settings.update'), [
            'points_validity_override_minutes' => 60,
            'requirements' => $payload,
        ] + $this->pointRulesPayload())->assertRedirect(route('admin.mlm.settings.edit'));

        $this->assertSame(60, SystemSetting::mlmSettings()->fresh()->mlm_points_validity_override_minutes);

        $this->actingAsWithSession($admin)->post(route('admin.mlm.settings.update'), [
            'points_validity_override_minutes' => null,
            'requirements' => $payload,
        ] + $this->pointRulesPayload())->assertRedirect(route('admin.mlm.settings.edit'));

        $this->assertNull(SystemSetting::mlmSettings()->fresh()->mlm_points_validity_override_minutes);
    }

    public function test_admin_can_change_the_knm_margin_and_missing_field_falls_back_to_30(): void
    {
        $admin = $this->makeAdmin();
        $payload = $this->requirementsPayload();

        // Imposta il margine KNM ("Prov K") al 10% (la tabella slide col 10%).
        $this->actingAsWithSession($admin)->post(route('admin.mlm.settings.update'), [
            'points_validity_override_minutes' => null,
            'knm_margin_percent' => 10,
            'requirements' => $payload,
        ] + $this->pointRulesPayload())->assertRedirect(route('admin.mlm.settings.edit'));

        $this->assertSame(10, SystemSetting::mlmSettings()->fresh()->mlmKnmMarginPercent());

        // Senza il campo (form vecchi / campo svuotato) torna al default 30.
        $this->actingAsWithSession($admin)->post(route('admin.mlm.settings.update'), [
            'points_validity_override_minutes' => null,
            'requirements' => $payload,
        ] + $this->pointRulesPayload())->assertRedirect(route('admin.mlm.settings.edit'));

        $this->assertSame(30, SystemSetting::mlmSettings()->fresh()->mlmKnmMarginPercent());

        // Fuori range (>100) viene rifiutato.
        $this->actingAsWithSession($admin)->post(route('admin.mlm.settings.update'), [
            'points_validity_override_minutes' => null,
            'knm_margin_percent' => 250,
            'requirements' => $payload,
        ] + $this->pointRulesPayload())->assertSessionHasErrors('knm_margin_percent');
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

        // Requisito clienti registrati di Basic (22/07): 6 in totale
        // (il cliente del ledger sopra + questi 5).
        for ($i = 0; $i < 5; $i++) {
            $this->makeClientFor($agent);
        }

        $response = $this->actingAsWithSession($admin)->post(route('admin.mlm.settings.recalculate'));

        $response->assertRedirect(route('admin.mlm.settings.edit'));
        $this->assertSame('basic', $agent->fresh()->mlm_rank);
    }

    // ── Tabella "punti per evento" (2026-07-22) ─────────────────────────────

    public function test_edit_page_shows_the_point_rules_table(): void
    {
        $admin = $this->makeAdmin();

        $response = $this->actingAsWithSession($admin)->get(route('admin.mlm.settings.edit'));

        $response->assertOk();
        $response->assertSee('Punti per evento');
        $response->assertSee('Apertura conto');
    }

    public function test_admin_can_add_and_remove_deposit_tiers(): void
    {
        $admin = $this->makeAdmin();

        // Nuovo assetto: via il taglio 600, dentro un taglio 2.400 da 4 punti
        // per 720 giorni; il taglio 120 passa a 3 punti / 60 giorni.
        $rules = $this->pointRulesPayload();
        $rules['deposit_rules'] = [
            ['amount_eur' => 120, 'points' => 3, 'duration_days' => 60],
            ['amount_eur' => 1200, 'points' => 2, 'duration_days' => 360],
            ['amount_eur' => 2400, 'points' => 4, 'duration_days' => 720],
        ];

        $this->actingAsWithSession($admin)->post(route('admin.mlm.settings.update'), [
            'points_validity_override_minutes' => null,
            'requirements' => $this->requirementsPayload(),
        ] + $rules)->assertRedirect(route('admin.mlm.settings.edit'));

        $tiers = \App\Models\MlmPointRule::where('event_type', 'deposit')
            ->orderBy('deposit_amount_eur_cents')
            ->get(['deposit_amount_eur_cents', 'points', 'duration_days']);

        $this->assertSame(
            [[12_000, 3.0, 60], [120_000, 2.0, 360], [240_000, 4.0, 720]],
            $tiers->map(fn ($r) => [$r->deposit_amount_eur_cents, (float) $r->points, $r->duration_days])->all()
        );

        // Il servizio punti segue subito la nuova tabella: 600 EUR non e'
        // piu' un taglio, ricade sul 120 (3 punti / 60 giorni).
        $agent = $this->makeAgent();
        $client = $this->makeClientFor($agent);
        app(\App\Services\MlmPointsService::class)->awardDepositPoints($client, 60_000);

        $entry = \App\Models\MlmPointLedgerEntry::where('agent_user_id', $agent->id)->sole();
        $this->assertEqualsWithDelta(3.0, $entry->points, 0.001);
        $this->assertTrue($entry->valid_until->isSameDay(now()->addDays(60)));
    }

    public function test_admin_can_update_the_registration_rule(): void
    {
        $admin = $this->makeAdmin();

        $rules = $this->pointRulesPayload();
        $rules['registration_points'] = 2;
        $rules['registration_duration_days'] = 15;

        $this->actingAsWithSession($admin)->post(route('admin.mlm.settings.update'), [
            'points_validity_override_minutes' => null,
            'requirements' => $this->requirementsPayload(),
        ] + $rules)->assertRedirect(route('admin.mlm.settings.edit'));

        $rule = \App\Models\MlmPointRule::registrationRule();
        $this->assertEqualsWithDelta(2.0, $rule->points, 0.001);
        $this->assertSame(15, $rule->duration_days);
    }

    public function test_point_rules_validation_rejects_duplicate_tiers_and_missing_fields(): void
    {
        $admin = $this->makeAdmin();

        // Due righe con lo stesso taglio (120 EUR) -> distinct fallisce.
        $rules = $this->pointRulesPayload();
        $rules['deposit_rules'] = [
            ['amount_eur' => 120, 'points' => 2, 'duration_days' => 30],
            ['amount_eur' => 120, 'points' => 5, 'duration_days' => 90],
        ];

        $this->actingAsWithSession($admin)->post(route('admin.mlm.settings.update'), [
            'points_validity_override_minutes' => null,
            'requirements' => $this->requirementsPayload(),
        ] + $rules)->assertSessionHasErrors('deposit_rules.0.amount_eur');

        // Senza la riga registrazione il form e' invalido.
        $rules = $this->pointRulesPayload();
        unset($rules['registration_points']);

        $this->actingAsWithSession($admin)->post(route('admin.mlm.settings.update'), [
            'points_validity_override_minutes' => null,
            'requirements' => $this->requirementsPayload(),
        ] + $rules)->assertSessionHasErrors('registration_points');
    }

    public function test_admin_can_change_the_minimum_clients_per_rank(): void
    {
        $admin = $this->makeAdmin();

        $payload = $this->requirementsPayload(['basic' => ['min_points' => 12, 'min_clients' => 3]]);

        $this->actingAsWithSession($admin)->post(route('admin.mlm.settings.update'), [
            'points_validity_override_minutes' => null,
            'requirements' => $payload,
        ] + $this->pointRulesPayload())->assertRedirect(route('admin.mlm.settings.edit'));

        $this->assertSame(3, MlmRankRequirement::where('rank', 'basic')->value('min_clients'));

        // E il motore lo usa subito: 12 punti + 3 clienti = Basic con la
        // soglia abbassata (col default 6 non lo sarebbe).
        $agent = $this->makeAgent();
        \App\Models\MlmPointLedgerEntry::create([
            'agent_user_id' => $agent->id,
            'client_user_id' => $this->makeClientFor($agent)->id,
            'source_type' => 'registration',
            'points' => 12,
            'valid_from' => now()->subDay(),
            'valid_until' => now()->addMonth(),
        ]);
        $this->makeClientFor($agent);

        // Con 2 clienti la soglia (3) non e' ancora raggiunta...
        $this->assertFalse(app(\App\Services\MlmRankEngine::class)->evaluate($agent)['satisfied']['basic']);

        // ...col terzo si'.
        $this->makeClientFor($agent);
        $this->assertTrue(app(\App\Services\MlmRankEngine::class)->evaluate($agent)['satisfied']['basic']);
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
