<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AgentCodeFeePayment;
use App\Models\Company;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\Transfer;
use App\Models\User;
use App\Services\AgentCodeFeeService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Quota per il codice agente (31/08/2026).
 *
 * Le cose che questi test devono dimostrare, perche' sono quelle su cui si
 * perdono soldi o si crea un agente che non ha pagato:
 *
 *  1. chi non ha saldato NON arriva alla firma, da nessuna delle tre porte —
 *     e siccome si diventa agente solo firmando, non esiste un agente non
 *     pagante;
 *  2. in euro NON si accredita un solo KY (e' la differenza sostanziale dai
 *     30 dei privati, dove invece si accreditano);
 *  3. in KY si va a -480 e quel debito non mangia il fido;
 *  4. chi rinuncia esce davvero, e chi non ha la quota attiva non la deve.
 */
class AgentCodeFeeTest extends TestCase
{
    use RefreshDatabase;

    private const QUOTA = 48000; // 480,00

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Mail::fake();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->makeSuperAdmin();
        config(['kmoney.bank_iban' => 'IT60X0542811101000000123456']);
    }

    // ─── 1. Il debito nasce all'approvazione, da tutte le porte ─────────────

    public function test_l_approvazione_admin_fa_nascere_il_debito(): void
    {
        $this->attivaQuota();
        [$aspirante] = $this->makeAspiranteAgente(richiestaPending: true);

        $this->actingAs($this->superAdmin)
            ->post("/admin/mlm/richieste/{$aspirante->id}/approva")
            ->assertRedirect();

        $this->assertSame(self::QUOTA, (int) $aspirante->fresh()->agent_code_fee_due_cents);
        $this->assertTrue(app(AgentCodeFeeService::class)->isDueFor($aspirante->fresh()));
    }

    public function test_con_la_quota_spenta_l_approvazione_non_crea_nessun_debito(): void
    {
        $this->attivaQuota(attiva: false);
        [$aspirante] = $this->makeAspiranteAgente(richiestaPending: true);

        $this->actingAs($this->superAdmin)
            ->post("/admin/mlm/richieste/{$aspirante->id}/approva")
            ->assertRedirect();

        $this->assertNull($aspirante->fresh()->agent_code_fee_due_cents);
    }

    public function test_riapprovare_non_raddoppia_ne_azzera_il_debito(): void
    {
        $this->attivaQuota();
        [$aspirante] = $this->makeAspiranteAgente();

        // L'admin cambia l'importo e ripassa dalla stessa porta.
        $this->attivaQuota(importo: 60000);
        app(AgentCodeFeeService::class)->markDueOnApproval($aspirante->fresh());

        $this->assertSame(self::QUOTA, (int) $aspirante->fresh()->agent_code_fee_due_cents);
    }

    public function test_chi_e_gia_agente_non_deve_la_quota(): void
    {
        $this->attivaQuota();
        [$agente] = $this->makePrivate(0);
        $agente->forceFill(['mlm_role' => 'agente'])->save();

        app(AgentCodeFeeService::class)->markDueOnApproval($agente->fresh());

        $this->assertNull($agente->fresh()->agent_code_fee_due_cents);
    }

    /**
     * SENTINELLA STRUTTURALE. Il debito nasce dove si scrive 'approved', e
     * oggi quei punti sono tre: approvazione admin, promozione admin, e
     * l'agente che ne registra uno sotto di se'. Se qualcuno ne aggiunge un
     * quarto e si dimentica di chiamare markDueOnApproval(), nasce un agente
     * che non paga il codice — e nessun altro test se ne accorgerebbe, perche'
     * i test coprono le porte che conoscono. Questo conta le porte.
     */
    public function test_non_esistono_altre_porte_che_approvano_una_richiesta_agente(): void
    {
        $trovate = [];

        $iter = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path()));
        foreach ($iter as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $contenuto = file_get_contents($file->getPathname());
            if (preg_match("/'mlm_agent_request_status'\s*=>\s*'approved'/", $contenuto)) {
                $trovate[] = str_replace(app_path() . DIRECTORY_SEPARATOR, '', $file->getPathname());
            }
        }

        sort($trovate);
        $attese = [
            'Http' . DIRECTORY_SEPARATOR . 'Controllers' . DIRECTORY_SEPARATOR . 'Admin' . DIRECTORY_SEPARATOR . 'MlmAgentRequestController.php',
            'Http' . DIRECTORY_SEPARATOR . 'Controllers' . DIRECTORY_SEPARATOR . 'MlmPortalController.php',
        ];
        sort($attese);

        $this->assertSame(
            $attese,
            $trovate,
            "È comparso un nuovo punto che approva una richiesta agente. Deve chiamare "
            . "AgentCodeFeeService::markDueOnApproval(), altrimenti quell'agente non paga il codice."
        );
    }

    // ─── 2. La firma e' sbarrata finche' non paga ───────────────────────────

    public function test_chi_non_ha_pagato_non_vede_la_pagina_della_firma(): void
    {
        $this->attivaQuota();
        [$aspirante] = $this->makeAspiranteAgente();

        $this->actingAs($aspirante)->get('/mlm/contratto-agente')
            ->assertRedirect(route('portal.mlm.agent-code-fee.show'));
    }

    public function test_chi_non_ha_pagato_non_ottiene_l_otp(): void
    {
        $this->attivaQuota();
        [$aspirante] = $this->makeAspiranteAgente();

        $this->actingAs($aspirante)->post('/mlm/contratto-agente/otp')
            ->assertRedirect(route('portal.mlm.agent-code-fee.show'));

        $this->assertNull($aspirante->fresh()->mlm_agent_contract_otp);
    }

    public function test_chi_non_ha_pagato_non_puo_firmare_neanche_con_un_otp_valido(): void
    {
        $this->attivaQuota();
        [$aspirante] = $this->makeAspiranteAgente();

        // Gli mettiamo in mano un OTP valido: deve fermarlo la quota, non l'OTP.
        $aspirante->forceFill([
            'mlm_agent_contract_otp'            => '123456',
            'mlm_agent_contract_otp_expires_at' => now()->addMinutes(10),
        ])->save();

        $this->actingAs($aspirante->fresh())
            ->post('/mlm/contratto-agente/firma', ['otp' => '123456'])
            ->assertRedirect(route('portal.mlm.agent-code-fee.show'));

        $this->assertFalse($aspirante->fresh()->isMlmAgent());
        $this->assertNull($aspirante->fresh()->mlm_agent_contract_signed_at);
    }

    public function test_pagata_la_quota_la_pagina_della_firma_si_apre(): void
    {
        $this->makeSystemAccount(0);
        $this->attivaQuota();
        [$aspirante] = $this->makeAspiranteAgente();

        app(AgentCodeFeeService::class)->payWithKy($aspirante);

        $this->actingAs($aspirante->fresh())->get('/mlm/contratto-agente')->assertOk();
    }

    // ─── 3. Pagamento in euro: nessun KY ────────────────────────────────────

    public function test_pagando_in_euro_non_si_muove_un_solo_ky(): void
    {
        $sistema = $this->makeSystemAccount(100000);
        $this->attivaQuota();
        [$aspirante, $conto] = $this->makeAspiranteAgente();

        $pagamento = app(AgentCodeFeeService::class)
            ->startPayment($aspirante, AgentCodeFeePayment::METHOD_BANK_TRANSFER);

        app(AgentCodeFeeService::class)->completeEuroPayment($pagamento);

        // È la differenza sostanziale con la quota dei privati, dove invece
        // il conto sale di 30: qui i 480 sono il prezzo del codice.
        $this->assertSame(0, (int) $conto->fresh()->available_balance);
        $this->assertSame(100000, (int) $sistema->fresh()->available_balance);
        $this->assertSame(0, Transfer::where('kind', 'agent_code_fee')->count());
        $this->assertNotNull($aspirante->fresh()->agent_code_fee_paid_at);
    }

    public function test_l_accredito_in_euro_chiamato_due_volte_salda_una_volta_sola(): void
    {
        $this->makeSystemAccount(100000);
        $this->attivaQuota();
        [$aspirante] = $this->makeAspiranteAgente();

        $pagamento = app(AgentCodeFeeService::class)
            ->startPayment($aspirante, AgentCodeFeePayment::METHOD_BANK_TRANSFER);

        app(AgentCodeFeeService::class)->completeEuroPayment($pagamento);
        $primoSaldo = $aspirante->fresh()->agent_code_fee_paid_at;

        app(AgentCodeFeeService::class)->completeEuroPayment($pagamento->fresh());

        $this->assertEquals($primoSaldo, $aspirante->fresh()->agent_code_fee_paid_at);
        $this->assertSame(1, AgentCodeFeePayment::where('status', 'completed')->count());
    }

    public function test_l_admin_conferma_il_bonifico_e_la_quota_e_saldata(): void
    {
        $this->makeSystemAccount(0);
        $this->attivaQuota();
        [$aspirante] = $this->makeAspiranteAgente();

        $pagamento = app(AgentCodeFeeService::class)
            ->startPayment($aspirante, AgentCodeFeePayment::METHOD_BANK_TRANSFER);

        $this->actingAs($this->superAdmin)
            ->post("/admin/quote-codice-agente/{$pagamento->id}/conferma")
            ->assertRedirect(route('admin.agent-code-fees.index'));

        $this->assertNotNull($aspirante->fresh()->agent_code_fee_paid_at);
        $this->assertSame($this->superAdmin->id, (int) $pagamento->fresh()->confirmed_by);
    }

    // ─── 4. Pagamento in KY ─────────────────────────────────────────────────

    public function test_pagando_in_ky_il_conto_va_a_meno_quattrocentottanta(): void
    {
        $sistema = $this->makeSystemAccount(0);
        $this->attivaQuota();
        [$aspirante, $conto] = $this->makeAspiranteAgente();

        app(AgentCodeFeeService::class)->payWithKy($aspirante);

        $this->assertSame(-self::QUOTA, (int) $conto->fresh()->available_balance);
        $this->assertSame(self::QUOTA, (int) $sistema->fresh()->available_balance);
        $this->assertNotNull($aspirante->fresh()->agent_code_fee_paid_at);
    }

    public function test_la_quota_codice_non_mangia_il_fido(): void
    {
        $this->makeSystemAccount(0);
        $this->attivaQuota();
        [$aspirante, $conto] = $this->makeAspiranteAgente(fido: 5000);

        app(AgentCodeFeeService::class)->payWithKy($aspirante);

        // 50 di fido + 480 di quota.
        $this->assertSame(53000, $conto->fresh()->massimale());
    }

    /**
     * Le due quote si sommano: un privato che ha pagato in KY entrambe si
     * porta dietro 30 + 480 di capienza in piu' del fido, che e' esattamente
     * il debito che si e' assunto. Se qui uscisse solo una delle due, la
     * seconda quota gli avrebbe mangiato la capienza della prima.
     */
    public function test_le_due_quote_pagate_in_ky_si_sommano_sul_massimale(): void
    {
        $this->makeSystemAccount(0);
        $this->attivaQuota();
        [$utente, $conto] = $this->makeAspiranteAgente(fido: 5000);

        // Come chi ha gia' pagato in KY la quota di iscrizione da privato.
        $utente->forceFill([
            'registration_fee_ky_allowance_cents' => 3000,
        ])->save();

        app(AgentCodeFeeService::class)->payWithKy($utente->fresh());

        $this->assertSame(5000 + 3000 + self::QUOTA, $conto->fresh()->massimale());
    }

    public function test_due_click_sul_bottone_paga_in_ky_addebitano_una_volta_sola(): void
    {
        $this->makeSystemAccount(0);
        $this->attivaQuota();
        [$aspirante, $conto] = $this->makeAspiranteAgente();

        app(AgentCodeFeeService::class)->payWithKy($aspirante);

        try {
            app(AgentCodeFeeService::class)->payWithKy($aspirante->fresh());
            $this->fail('Il secondo pagamento doveva essere rifiutato.');
        } catch (\RuntimeException $e) {
            // atteso
        }

        $this->assertSame(-self::QUOTA, (int) $conto->fresh()->available_balance);
        $this->assertSame(1, Transfer::where('kind', 'agent_code_fee')->count());
    }

    public function test_un_metodo_spento_non_e_pagabile_neanche_forzando_la_rotta(): void
    {
        $this->makeSystemAccount(0);
        $this->attivaQuota();
        [$aspirante] = $this->makeAspiranteAgente();

        SystemSetting::userLimitDefaults()->forceFill([
            'agent_code_fee_ky_enabled'            => false,
            'agent_code_fee_bank_transfer_enabled' => true,
        ])->save();

        $this->actingAs($aspirante)->post('/mlm/quota-codice/ky')
            ->assertRedirect(route('portal.mlm.agent-code-fee.show'));

        $this->assertSame(0, AgentCodeFeePayment::where('status', 'completed')->count());
    }

    // ─── 5. Il blocco e la via d'uscita ─────────────────────────────────────

    public function test_con_la_quota_codice_da_saldare_il_conto_resta_visibile(): void
    {
        $this->attivaQuota();
        [$aspirante] = $this->makeAspiranteAgente();

        $this->actingAs($aspirante)->get('/dashboard')->assertOk();
        $this->actingAs($aspirante)->get('/movimenti')->assertOk();
    }

    public function test_con_la_quota_codice_da_saldare_non_si_puo_inviare_ky(): void
    {
        $this->attivaQuota();
        [$aspirante] = $this->makeAspiranteAgente();

        $this->actingAs($aspirante)->get('/invia')
            ->assertRedirect(route('portal.mlm.agent-code-fee.show'));
    }

    public function test_chi_rinuncia_torna_cliente_e_riprende_a_operare(): void
    {
        $this->attivaQuota();
        [$aspirante] = $this->makeAspiranteAgente();

        $this->actingAs($aspirante)->post('/mlm/quota-codice/rinuncia')
            ->assertRedirect(route('portal.dashboard'));

        $dopo = $aspirante->fresh();

        $this->assertNull($dopo->agent_code_fee_due_cents);
        $this->assertFalse($dopo->mlmAgentAwaitingContract());
        $this->assertTrue($dopo->canRequestMlmAgent());

        // E il blocco e' caduto davvero.
        $this->actingAs($dopo)->get('/invia')->assertOk();
    }

    public function test_chi_ha_gia_pagato_non_puo_rinunciare_per_farsi_annullare_la_quota(): void
    {
        $this->makeSystemAccount(0);
        $this->attivaQuota();
        [$aspirante] = $this->makeAspiranteAgente();

        app(AgentCodeFeeService::class)->payWithKy($aspirante);

        $this->actingAs($aspirante->fresh())->post('/mlm/quota-codice/rinuncia')
            ->assertRedirect(route('portal.dashboard'));

        // La richiesta resta approvata: i soldi sono usciti, non si torna indietro
        // da soli con un bottone.
        $this->assertTrue($aspirante->fresh()->mlmAgentAwaitingContract());
    }

    // ─── 6. Impostazioni admin ──────────────────────────────────────────────

    public function test_l_admin_non_puo_attivare_la_quota_senza_metodi(): void
    {
        $this->actingAs($this->superAdmin)
            ->post('/admin/quote-codice-agente/impostazioni', [
                'agent_code_fee_enabled' => '1',
                'agent_code_fee_amount'  => '480',
            ])
            ->assertSessionHasErrors('agent_code_fee_enabled');

        $this->assertFalse((bool) SystemSetting::userLimitDefaults()->agent_code_fee_enabled);
    }

    public function test_l_admin_cambia_importo_e_metodi(): void
    {
        $this->actingAs($this->superAdmin)
            ->post('/admin/quote-codice-agente/impostazioni', [
                'agent_code_fee_enabled'            => '1',
                'agent_code_fee_amount'             => '480,50',
                'agent_code_fee_bank_transfer_enabled' => '1',
            ])
            ->assertSessionHasNoErrors();

        $settings = SystemSetting::userLimitDefaults()->fresh();

        $this->assertSame(48050, $settings->agentCodeFeeAmount());
        $this->assertTrue($settings->agentCodeFeeEnabled());
        $this->assertSame(['bank_transfer'], array_keys($settings->agentCodeFeeMethods()));
    }

    /**
     * Le due quote hanno interruttori indipendenti: accendere quella degli
     * agenti non deve accendere quella dei privati, o si ritroverebbero a
     * pagare persone che Laura non ha deciso di far pagare.
     */
    public function test_le_due_quote_hanno_interruttori_indipendenti(): void
    {
        $this->attivaQuota();

        $settings = SystemSetting::userLimitDefaults()->fresh();

        $this->assertTrue($settings->agentCodeFeeEnabled());
        $this->assertFalse($settings->registrationFeeEnabled());
    }

    // ─── Aiutanti ───────────────────────────────────────────────────────────

    private function attivaQuota(bool $attiva = true, int $importo = self::QUOTA): void
    {
        SystemSetting::userLimitDefaults()->forceFill([
            'agent_code_fee_enabled'               => $attiva,
            'agent_code_fee_amount_cents'          => $importo,
            'agent_code_fee_stripe_enabled'        => false,
            'agent_code_fee_paypal_enabled'        => false,
            'agent_code_fee_bank_transfer_enabled' => true,
            'agent_code_fee_ky_enabled'            => true,
        ])->save();
    }

    private function makeSuperAdmin(): void
    {
        $this->superAdmin = User::create([
            'name'                => 'Super Admin',
            'email'               => 'sa-' . Str::random(8) . '@test.test',
            'password'            => 'secret123',
            'account_holder_type' => 'private',
            'role'                => 'private-owner',
            'is_active'           => true,
            'is_super_admin'      => true,
            'email_verified_at'   => now(),
            'contract_signed_at'  => now(),
        ]);
    }

    /** @return array{0: User, 1: Account} */
    private function makePrivate(int $saldo, int $fido = 0): array
    {
        $user = User::create([
            'name'                         => 'Privato ' . Str::random(4),
            'email'                        => 'priv-' . Str::random(8) . '@test.test',
            'password'                     => 'secret123',
            'account_holder_type'          => 'private',
            'company_id'                   => null,
            'role'                         => 'private-member',
            'is_active'                    => true,
            'is_super_admin'               => false,
            'email_verified_at'            => now(),
            'contract_signed_at'           => now(),
            'transfer_limits_use_defaults' => false,
            'negative_balance_limit'       => $fido,
            'mlm_role'                     => 'cliente',
        ]);

        $user->roles()->sync([Role::where('slug', 'private-member')->firstOrFail()->id]);

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
     * Un aspirante agente con la richiesta gia' approvata (lo stato in cui si
     * trova chi deve pagare la quota) e i dati anagrafici completi, cosi' la
     * firma sarebbe possibile e a fermarlo resta SOLO la quota.
     *
     * @return array{0: User, 1: Account}
     */
    private function makeAspiranteAgente(int $fido = 0, bool $richiestaPending = false): array
    {
        [$user, $account] = $this->makePrivate(0, $fido);

        $user->forceFill([
            'mlm_agent_request_status'   => $richiestaPending ? 'pending' : 'approved',
            'mlm_agent_requested_at'     => now(),
            'mlm_agent_reviewed_at'      => $richiestaPending ? null : now(),
            'fiscal_code'                => strtoupper(Str::random(16)),
            'birth_date'                 => now()->subYears(30)->toDateString(),
            'birth_place'                => 'Roma',
            'residence_address'          => 'Via Roma 1',
            'residence_zip'              => '00100',
            'residence_city'             => 'Roma',
            'residence_province'         => 'RM',
        ])->save();

        if (! $richiestaPending) {
            app(AgentCodeFeeService::class)->markDueOnApproval($user->fresh());
        }

        return [$user->fresh(), $account];
    }

    private function makeSystemAccount(int $saldo): Account
    {
        $esistente = Account::systemAccount();

        if ($esistente !== null) {
            $esistente->forceFill([
                'available_balance' => $saldo,
                'status'            => 'active',
                'company_id'        => $esistente->company_id ?? $this->makeCircuitCompany()->id,
            ])->save();

            return $esistente->fresh();
        }

        return Account::create([
            'company_id'        => $this->makeCircuitCompany()->id,
            'owner_type'        => 'company',
            'type'              => 'member',
            'status'            => 'active',
            'available_balance' => $saldo,
            'is_system_account' => true,
        ]);
    }

    private function makeCircuitCompany(): Company
    {
        $slug = 'circuito-' . Str::random(6);

        return Company::create([
            'name'          => 'Circuito ' . Str::random(4),
            'slug'          => $slug,
            'email'         => $slug . '@test.test',
            'status'        => 'active',
            'kyc_status'    => 'approved',
            'currency_code' => 'KY',
            'sector'        => 'servizi',
            'description'   => 'Conto sistema di test',
        ]);
    }
}
