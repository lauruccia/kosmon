<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AgentCodeFeePayment;
use App\Models\Company;
use App\Notifications\AgentCodeFeePaidNotification;
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

    /**
     * 02/09/2026 — LA REGOLA E' CAMBIATA, e questo test con lei. Fino al
     * 01/09 la quota del codice fermava il conto a chiunque. Ora ferma solo
     * chi nel circuito non e' ancora entrato pagando: quota di iscrizione
     * SOSPESA, cioe' i 480 sono il suo ingresso.
     */
    public function test_chi_non_ha_mai_pagato_l_ingresso_non_puo_inviare_ky(): void
    {
        $this->attivaQuota();
        [$aspirante] = $this->makeAspiranteAgente();

        // Entrato dalla porta dell'agente: la quota dei privati e' sospesa e
        // nessun altro pagamento lo ha fatto entrare.
        $aspirante->forceFill(['registration_fee_due_cents' => 0])->save();

        $this->actingAs($aspirante->fresh())->get('/invia')
            ->assertRedirect(route('portal.mlm.agent-code-fee.show'));
    }

    public function test_chi_nel_circuito_c_era_gia_continua_a_operare_mentre_deve_i_quattrocentottanta(): void
    {
        $this->attivaQuota();
        [$aspirante] = $this->makeAspiranteAgente();

        // Colonna della quota privati a NULL: e' uno dei milletrecento
        // iscritti da prima. Chiedere di diventare agente non gli congela il
        // conto — gli manca solo la firma, ed e' li' che la strada e' chiusa.
        $this->assertNull($aspirante->registration_fee_due_cents);

        $this->actingAs($aspirante)->get('/invia')->assertOk();

        $this->actingAs($aspirante)->get(route('portal.mlm.agent-contract.show'))
            ->assertRedirect(route('portal.mlm.agent-code-fee.show'));
    }

    /**
     * LE PAGINE DEL PERCORSO SI DEVONO APRIRE DAVVERO (02/09/2026).
     *
     * Lezione del 01/09, pagata con un 500 in produzione: 44 test verdi non
     * avevano mai APERTO la pagina che si e' rotta. Da quando il percorso ha
     * una barra dei passi inclusa in quattro viste, un errore in quella
     * inclusione le rompe tutte insieme — e queste due erano le uniche che
     * nessun test apriva.
     */
    public function test_la_pagina_di_esito_si_apre_e_indica_il_passo_dopo(): void
    {
        $this->makeSystemAccount(0);
        $this->attivaQuota();
        [$aspirante] = $this->makeAspiranteAgente();

        $pagamento = app(AgentCodeFeeService::class)->payWithKy($aspirante);

        $this->actingAs($aspirante->fresh())
            ->get(route('portal.mlm.agent-code-fee.success', ['payment' => $pagamento->uuid]))
            ->assertOk()
            ->assertSee('Vai alla firma del contratto');
    }

    public function test_la_pagina_del_bonifico_si_apre(): void
    {
        $this->attivaQuota();
        [$aspirante] = $this->makeAspiranteAgente();

        $this->actingAs($aspirante)
            ->post('/mlm/quota-codice/bonifico')
            ->assertOk()
            ->assertSee('Istruzioni per il bonifico');
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

    /**
     * DECISIONE DI LAURA, 01/09/2026 (cambia la regola del 31/08). Prima chi
     * aveva pagato non poteva rinunciare: restava legato a un percorso che
     * non voleva piu' e senza nessun bottone. Ora la richiesta si chiude, ma
     * IL DENARO NON SI MUOVE — nessuno storno, quota ancora saldata. Se va
     * restituita, e' l'admin ad annullarla.
     */
    public function test_chi_ha_gia_pagato_puo_rinunciare_ma_i_soldi_non_si_muovono(): void
    {
        $this->makeSystemAccount(0);
        $this->attivaQuota();
        [$aspirante, $conto] = $this->makeAspiranteAgente(fido: self::QUOTA);

        app(AgentCodeFeeService::class)->payWithKy($aspirante);

        $saldoPrima     = (int) $conto->fresh()->available_balance;
        $movimentiPrima = Transfer::count();

        $this->actingAs($aspirante->fresh())->post('/mlm/quota-codice/rinuncia')
            ->assertRedirect(route('portal.dashboard'));

        $dopo = $aspirante->fresh();

        // Il percorso agente e' chiuso...
        $this->assertSame('cancelled', $dopo->mlm_agent_request_status);
        $this->assertFalse($dopo->mlmAgentAwaitingContract());
        $this->assertTrue($dopo->canRequestMlmAgent());

        // ...ma la quota resta pagata e nessun KY e' tornato indietro.
        $this->assertNotNull($dopo->agent_code_fee_paid_at);
        $this->assertSame(self::QUOTA, (int) $dopo->agent_code_fee_due_cents);
        $this->assertSame($saldoPrima, (int) $conto->fresh()->available_balance);
        $this->assertSame($movimentiPrima, Transfer::count());
    }

    public function test_chi_ha_gia_firmato_non_rinuncia_da_qui(): void
    {
        $this->makeSystemAccount(0);
        $this->attivaQuota();
        [$aspirante] = $this->makeAspiranteAgente(fido: self::QUOTA);

        app(AgentCodeFeeService::class)->payWithKy($aspirante);
        $aspirante->fresh()->forceFill(['mlm_role' => 'agente', 'mlm_activated_at' => now()])->save();

        $this->actingAs($aspirante->fresh())->post('/mlm/quota-codice/rinuncia');

        // Resta agente: la revoca di un agente e' un altro mestiere.
        $this->assertTrue($aspirante->fresh()->isMlmAgent());
        $this->assertNotSame('cancelled', $aspirante->fresh()->mlm_agent_request_status);
    }

    public function test_anche_l_esonerato_puo_rinunciare(): void
    {
        $this->attivaQuota();
        [$aspirante] = $this->makeAspiranteAgente();

        app(AgentCodeFeeService::class)->waive($aspirante, $this->superAdmin, 'Accordo commerciale.');

        $this->actingAs($aspirante->fresh())->post('/mlm/quota-codice/rinuncia')
            ->assertRedirect(route('portal.dashboard'));

        $dopo = $aspirante->fresh();

        $this->assertSame('cancelled', $dopo->mlm_agent_request_status);
        // Non avendo pagato niente, il debito sparisce del tutto: lo zero
        // dell'esonero non deve restare li' a coprire una richiesta futura.
        $this->assertNull($dopo->agent_code_fee_due_cents);
    }

    // ─── 5-bis. Annullamento della quota dal backoffice (01/09/2026) ────────

    public function test_annullare_una_quota_pagata_in_ky_storna_e_rimette_tutto_a_posto(): void
    {
        $this->makeSystemAccount(0);
        $this->attivaQuota();
        [$aspirante, $conto] = $this->makeAspiranteAgente(fido: self::QUOTA);

        $pagamento = app(AgentCodeFeeService::class)->payWithKy($aspirante);

        $this->assertSame(-self::QUOTA, (int) $conto->fresh()->available_balance);

        $this->actingAs($this->superAdmin)
            ->post("/admin/quote-codice-agente/{$pagamento->id}/annulla", ['admin_notes' => 'Rimborso concordato.'])
            ->assertRedirect(route('admin.agent-code-fees.index'));

        $dopo = $aspirante->fresh();

        $this->assertSame(0, (int) $conto->fresh()->available_balance);
        $this->assertSame(self::QUOTA, (int) $dopo->agent_code_fee_due_cents);
        $this->assertNull($dopo->agent_code_fee_paid_at);
        $this->assertSame(0, (int) $dopo->agent_code_fee_ky_allowance_cents);
        $this->assertSame(AgentCodeFeePayment::STATUS_CANCELLED, $pagamento->fresh()->status);
        $this->assertSame(1, Transfer::where('kind', 'agent_code_fee_reversal')->count());

        // E il blocco e' tornato: senza quota non si firma.
        $this->actingAs($dopo)->get('/mlm/contratto-agente')
            ->assertRedirect(route('portal.mlm.agent-code-fee.show'));
    }

    /**
     * Il pezzo che si sbaglia facilmente: chi si e' visto cancellare il
     * movimento a mano i KY li ha GIA' riavuti. Stornare in base allo stato
     * del pagamento glieli regalerebbe una seconda volta.
     */
    public function test_se_il_movimento_non_c_e_piu_non_si_storna_due_volte(): void
    {
        $this->makeSystemAccount(0);
        $this->attivaQuota();
        [$aspirante, $conto] = $this->makeAspiranteAgente(fido: self::QUOTA);

        $pagamento = app(AgentCodeFeeService::class)->payWithKy($aspirante);

        // Il movimento sparisce (e i KY erano gia' tornati con lui).
        Transfer::whereKey($pagamento->fresh()->transfer_id)->delete();
        $conto->fresh()->forceFill(['available_balance' => 0])->save();

        $this->actingAs($this->superAdmin)
            ->post("/admin/quote-codice-agente/{$pagamento->id}/annulla")
            ->assertRedirect(route('admin.agent-code-fees.index'));

        $this->assertSame(0, Transfer::where('kind', 'agent_code_fee_reversal')->count());
        $this->assertSame(0, (int) $conto->fresh()->available_balance);
        $this->assertSame(self::QUOTA, (int) $aspirante->fresh()->agent_code_fee_due_cents);
        $this->assertNull($aspirante->fresh()->agent_code_fee_paid_at);
    }

    /**
     * Il movimento c'e' ancora ma non e' piu' contabilizzato (`booked`): i
     * conti sono gia' stati rimessi a posto da qualcun altro. Il filtro sullo
     * stato serve a questo, e senza un test che lo esercita si potrebbe
     * togliere senza che niente diventi rosso.
     */
    public function test_un_movimento_non_piu_contabilizzato_non_si_storna(): void
    {
        $this->makeSystemAccount(0);
        $this->attivaQuota();
        [$aspirante, $conto] = $this->makeAspiranteAgente(fido: self::QUOTA);

        $pagamento = app(AgentCodeFeeService::class)->payWithKy($aspirante);

        Transfer::whereKey($pagamento->fresh()->transfer_id)->update(['status' => 'reversed']);
        $conto->fresh()->forceFill(['available_balance' => 0])->save();

        $this->actingAs($this->superAdmin)
            ->post("/admin/quote-codice-agente/{$pagamento->id}/annulla")
            ->assertRedirect(route('admin.agent-code-fees.index'));

        $this->assertSame(0, Transfer::where('kind', 'agent_code_fee_reversal')->count());
        $this->assertSame(0, (int) $conto->fresh()->available_balance);
        $this->assertSame(self::QUOTA, (int) $aspirante->fresh()->agent_code_fee_due_cents);
    }

    /**
     * Se la colonna e' a zero — un esonero scritto sopra un pagamento, una
     * riparazione a mano — l'annullamento deve rimettere in carico l'importo
     * DEL PAGAMENTO, non lasciare lo zero: con lo zero la quota risulterebbe
     * annullata e non dovuta insieme, cioe' un modo gratis di restare dentro.
     */
    public function test_annullare_con_la_colonna_azzerata_rimette_in_carico_l_importo_del_pagamento(): void
    {
        $this->makeSystemAccount(0);
        $this->attivaQuota();
        [$aspirante] = $this->makeAspiranteAgente(fido: self::QUOTA);

        $pagamento = app(AgentCodeFeeService::class)->payWithKy($aspirante);
        $aspirante->fresh()->forceFill(['agent_code_fee_due_cents' => 0])->save();

        $this->actingAs($this->superAdmin)
            ->post("/admin/quote-codice-agente/{$pagamento->id}/annulla")
            ->assertRedirect(route('admin.agent-code-fees.index'));

        $this->assertSame(self::QUOTA, (int) $aspirante->fresh()->agent_code_fee_due_cents);
        $this->assertTrue(app(AgentCodeFeeService::class)->isDueFor($aspirante->fresh()));
    }

    public function test_annullare_una_quota_pagata_in_euro_non_storna_niente_ma_la_rimette_dovuta(): void
    {
        $this->attivaQuota();
        [$aspirante] = $this->makeAspiranteAgente();

        $pagamento = app(AgentCodeFeeService::class)->startPayment($aspirante, AgentCodeFeePayment::METHOD_BANK_TRANSFER);
        app(AgentCodeFeeService::class)->completeEuroPayment($pagamento, $this->superAdmin->id);

        $movimentiPrima = Transfer::count();

        $this->actingAs($this->superAdmin)
            ->post("/admin/quote-codice-agente/{$pagamento->id}/annulla")
            ->assertRedirect(route('admin.agent-code-fees.index'));

        // In euro non era stato accreditato nessun KY: non c'e' niente da
        // stornare, e i soldi veri restano da restituire a mano.
        $this->assertSame($movimentiPrima, Transfer::count());
        $this->assertSame(self::QUOTA, (int) $aspirante->fresh()->agent_code_fee_due_cents);
        $this->assertNull($aspirante->fresh()->agent_code_fee_paid_at);
        $this->assertSame(AgentCodeFeePayment::STATUS_CANCELLED, $pagamento->fresh()->status);
    }

    public function test_una_quota_annullata_non_si_annulla_due_volte(): void
    {
        $this->attivaQuota();
        [$aspirante] = $this->makeAspiranteAgente();

        $pagamento = app(AgentCodeFeeService::class)->startPayment($aspirante, AgentCodeFeePayment::METHOD_BANK_TRANSFER);
        app(AgentCodeFeeService::class)->completeEuroPayment($pagamento, $this->superAdmin->id);

        $this->actingAs($this->superAdmin)->post("/admin/quote-codice-agente/{$pagamento->id}/annulla");

        $this->actingAs($this->superAdmin)
            ->post("/admin/quote-codice-agente/{$pagamento->id}/annulla")
            ->assertSessionHas('portal_error');
    }

    public function test_un_pagamento_mai_saldato_non_si_annulla(): void
    {
        $this->attivaQuota();
        [$aspirante] = $this->makeAspiranteAgente();

        $pagamento = app(AgentCodeFeeService::class)->startPayment($aspirante, AgentCodeFeePayment::METHOD_BANK_TRANSFER);

        $this->actingAs($this->superAdmin)
            ->post("/admin/quote-codice-agente/{$pagamento->id}/annulla")
            ->assertSessionHas('portal_error');

        $this->assertSame(AgentCodeFeePayment::STATUS_PENDING_BANK_TRANSFER, $pagamento->fresh()->status);
    }

    // ─── 5-ter. Esonero (01/09/2026) ────────────────────────────────────────

    public function test_l_esonero_azzera_la_quota_e_lascia_firmare_senza_pagare(): void
    {
        $this->attivaQuota();
        [$aspirante] = $this->makeAspiranteAgente();

        $this->actingAs($this->superAdmin)
            ->post("/admin/users/{$aspirante->id}/quota-codice-agente/esonera", ['reason' => 'Accordo commerciale con il fondatore.'])
            ->assertSessionHasNoErrors();

        $dopo = $aspirante->fresh();

        $this->assertSame(0, (int) $dopo->agent_code_fee_due_cents);
        $this->assertNull($dopo->agent_code_fee_paid_at);
        $this->assertFalse(app(AgentCodeFeeService::class)->isDueFor($dopo));
        $this->assertTrue(app(AgentCodeFeeService::class)->isWaived($dopo));

        // NESSUN pagamento finto: 480 euro mai entrati non devono comparire
        // fra gli incassi.
        $this->assertSame(0, AgentCodeFeePayment::where('user_id', $dopo->id)->count());

        $this->actingAs($dopo)->get('/mlm/contratto-agente')->assertOk();
    }

    public function test_l_esonero_senza_motivo_non_passa(): void
    {
        $this->attivaQuota();
        [$aspirante] = $this->makeAspiranteAgente();

        $this->actingAs($this->superAdmin)
            ->post("/admin/users/{$aspirante->id}/quota-codice-agente/esonera", ['reason' => ''])
            ->assertSessionHasErrors('reason');

        $this->assertSame(self::QUOTA, (int) $aspirante->fresh()->agent_code_fee_due_cents);
    }

    public function test_non_si_esonera_chi_ha_gia_pagato(): void
    {
        $this->makeSystemAccount(0);
        $this->attivaQuota();
        [$aspirante] = $this->makeAspiranteAgente(fido: self::QUOTA);

        app(AgentCodeFeeService::class)->payWithKy($aspirante);

        $this->actingAs($this->superAdmin)
            ->post("/admin/users/{$aspirante->id}/quota-codice-agente/esonera", ['reason' => 'Ci ho ripensato.'])
            ->assertSessionHas('portal_error');

        $this->assertNotNull($aspirante->fresh()->agent_code_fee_paid_at);
    }

    public function test_non_si_esonera_chi_non_e_mai_entrato_nel_percorso(): void
    {
        $this->attivaQuota();
        [$privato] = $this->makePrivate(0);

        $this->actingAs($this->superAdmin)
            ->post("/admin/users/{$privato->id}/quota-codice-agente/esonera", ['reason' => 'Motivo qualsiasi.'])
            ->assertSessionHas('portal_error');

        $this->assertNull($privato->fresh()->agent_code_fee_due_cents);

        // IL MESSAGGIO, non solo il rifiuto: senza questa riga la guardia
        // «non e' nel percorso» e' indistinguibile da quella «e' gia'
        // esonerato» — (int) null fa zero, e la seconda coprirebbe la prima
        // dicendo all'admin una cosa falsa.
        $this->assertStringContainsString('Non ha nessuna quota', (string) session('portal_error'));
    }

    /**
     * La revoca rimette in carico L'IMPORTO DI PRIMA, non quello di oggi in
     * impostazioni: dopo lo zero, l'unico posto dove quella cifra e' rimasta
     * scritta e' l'audit log dell'esonero.
     */
    public function test_la_revoca_dell_esonero_rimette_in_carico_l_importo_di_prima(): void
    {
        $this->attivaQuota();
        [$aspirante] = $this->makeAspiranteAgente();

        app(AgentCodeFeeService::class)->waive($aspirante->fresh(), $this->superAdmin, 'Accordo commerciale.');

        // Nel frattempo la quota in impostazioni cambia.
        $this->attivaQuota(importo: 60000);

        $this->actingAs($this->superAdmin)
            ->post("/admin/users/{$aspirante->id}/quota-codice-agente/revoca-esonero")
            ->assertSessionHasNoErrors();

        $dopo = $aspirante->fresh();

        $this->assertSame(self::QUOTA, (int) $dopo->agent_code_fee_due_cents);
        $this->assertTrue(app(AgentCodeFeeService::class)->isDueFor($dopo));

        $this->actingAs($dopo)->get('/mlm/contratto-agente')
            ->assertRedirect(route('portal.mlm.agent-code-fee.show'));
    }

    public function test_l_esonero_non_si_revoca_dopo_la_firma(): void
    {
        $this->attivaQuota();
        [$aspirante] = $this->makeAspiranteAgente();

        app(AgentCodeFeeService::class)->waive($aspirante->fresh(), $this->superAdmin, 'Accordo commerciale.');
        $aspirante->fresh()->forceFill(['mlm_role' => 'agente', 'mlm_activated_at' => now()])->save();

        $this->actingAs($this->superAdmin)
            ->post("/admin/users/{$aspirante->id}/quota-codice-agente/revoca-esonero")
            ->assertSessionHas('portal_error');

        $this->assertSame(0, (int) $aspirante->fresh()->agent_code_fee_due_cents);
    }

    // ─── 5-quater. Il rifiuto dell'admin ────────────────────────────────────

    public function test_il_rifiuto_cancella_il_debito_non_pagato(): void
    {
        $this->attivaQuota();
        [$aspirante] = $this->makeAspiranteAgente();

        $this->actingAs($this->superAdmin)
            ->post("/admin/mlm/richieste/{$aspirante->id}/rifiuta", ['reason' => 'Documenti incompleti.'])
            ->assertRedirect();

        $this->assertNull($aspirante->fresh()->agent_code_fee_due_cents);
    }

    public function test_il_rifiuto_a_quota_gia_pagata_non_tocca_i_soldi_e_lo_dice(): void
    {
        $this->makeSystemAccount(0);
        $this->attivaQuota();
        [$aspirante, $conto] = $this->makeAspiranteAgente(fido: self::QUOTA);

        app(AgentCodeFeeService::class)->payWithKy($aspirante);
        $saldoPrima = (int) $conto->fresh()->available_balance;

        $this->actingAs($this->superAdmin)
            ->post("/admin/mlm/richieste/{$aspirante->id}/rifiuta", ['reason' => 'Documenti incompleti.'])
            ->assertRedirect();

        $dopo = $aspirante->fresh();

        $this->assertSame('rejected', $dopo->mlm_agent_request_status);
        $this->assertNotNull($dopo->agent_code_fee_paid_at);
        $this->assertSame($saldoPrima, (int) $conto->fresh()->available_balance);

        // L'avviso: chi rifiuta deve sapere ADESSO che ci sono soldi fermi.
        $this->assertStringContainsString(
            'aveva già saldato',
            (string) session('portal_success')
        );
    }

    // ─── 5-quinquies. Il webhook Stripe (01/09/2026) ────────────────────────

    /**
     * Il bug che Laura ha chiesto di chiudere: una riga finita `failed`
     * veniva saltata dal webhook, e chi aveva pagato restava senza niente per
     * sempre. Ora la porta e' aperta, ma solo se Stripe conferma l'incasso.
     */
    public function test_il_webhook_ripesca_una_quota_agente_finita_failed(): void
    {
        $this->attivaQuota();
        [$aspirante] = $this->makeAspiranteAgente();
        $this->fingiStripe(true);

        $pagamento = $this->pagamentoAgenteFallito($aspirante);

        $this->postWebhookStripe($pagamento->stripe_checkout_session_id)->assertOk();

        $this->assertTrue($pagamento->fresh()->isCompleted());
        $this->assertNotNull($aspirante->fresh()->agent_code_fee_paid_at);
    }

    public function test_senza_la_prova_di_stripe_il_webhook_non_salda_niente(): void
    {
        $this->attivaQuota();
        [$aspirante] = $this->makeAspiranteAgente();
        $this->fingiStripe(false);

        $pagamento = $this->pagamentoAgenteFallito($aspirante);

        $this->postWebhookStripe($pagamento->stripe_checkout_session_id)->assertOk();

        $this->assertFalse($pagamento->fresh()->isCompleted());
        $this->assertNull($aspirante->fresh()->agent_code_fee_paid_at);
    }

    /**
     * L'altra meta' della tolleranza: `cancelled` e' una risposta gia' data.
     * Un webhook in ritardo non deve riaprire una quota che l'admin ha
     * annullato apposta.
     */
    public function test_il_webhook_non_resuscita_una_quota_annullata(): void
    {
        $this->attivaQuota();
        [$aspirante] = $this->makeAspiranteAgente();
        $this->fingiStripe(true);

        $pagamento = $this->pagamentoAgenteFallito($aspirante);
        $pagamento->update(['status' => AgentCodeFeePayment::STATUS_CANCELLED]);

        $this->postWebhookStripe($pagamento->stripe_checkout_session_id)->assertOk();

        $this->assertSame(AgentCodeFeePayment::STATUS_CANCELLED, $pagamento->fresh()->status);
        $this->assertNull($aspirante->fresh()->agent_code_fee_paid_at);
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

    // ─── 5-sexies. Le pagine si aprono davvero (01/09/2026) ─────────────────

    /**
     * QUESTO TEST NASCE DA UN 500 IN PRODUZIONE. La pagina delle quote
     * mandava «syntax error, unexpected token "else"»: nel testo di conferma
     * del bottone «Annulla quota» avevo scritto `@endif@if(...)` attaccati, e
     * **Blade non riconosce una direttiva incollata a una lettera** (il suo
     * `\B@` fallisce fra `f` e `@`). Quel secondo `@if` restava testo, il suo
     * `@endif` chiudeva il blocco esterno, e l'`@else` piu' sotto diventava
     * orfano.
     *
     * Nessuno dei 44 test lo ha visto perche' NESSUNO APRIVA LA PAGINA con
     * una riga saldata dentro: i test parlavano tutti al controller e al
     * servizio. Una vista si rompe solo quando la si compila, e la si compila
     * solo se qualcosa la chiede.
     *
     * Per questo qui dentro ci sono TUTTI gli stati di riga insieme: ognuno
     * accende un ramo diverso del template.
     */
    public function test_la_pagina_admin_delle_quote_si_apre_con_ogni_stato_di_riga(): void
    {
        $this->makeSystemAccount(0);
        $this->attivaQuota();

        // 1. pagata in KY (storno possibile)
        [$inKy] = $this->makeAspiranteAgente(fido: self::QUOTA);
        app(AgentCodeFeeService::class)->payWithKy($inKy);

        // 2. pagata in euro (nessuno storno, rimborso a mano)
        [$inEuro] = $this->makeAspiranteAgente();
        $pagEuro = app(AgentCodeFeeService::class)->startPayment($inEuro, AgentCodeFeePayment::METHOD_BANK_TRANSFER);
        app(AgentCodeFeeService::class)->completeEuroPayment($pagEuro, $this->superAdmin->id);

        // 3. pagata da chi ha GIA' firmato (il terzo avviso nella conferma)
        [$agente] = $this->makeAspiranteAgente();
        $pagAgente = app(AgentCodeFeeService::class)->startPayment($agente, AgentCodeFeePayment::METHOD_BANK_TRANSFER);
        app(AgentCodeFeeService::class)->completeEuroPayment($pagAgente, $this->superAdmin->id);
        $agente->fresh()->forceFill(['mlm_role' => 'agente', 'mlm_activated_at' => now()])->save();

        // 4. bonifico in attesa, 5. fallita, 6. annullata
        [$inAttesa] = $this->makeAspiranteAgente();
        app(AgentCodeFeeService::class)->startPayment($inAttesa, AgentCodeFeePayment::METHOD_BANK_TRANSFER);

        [$fallita] = $this->makeAspiranteAgente();
        $this->pagamentoAgenteFallito($fallita);

        // 5-bis. fallita ma pagata con BONIFICO: e' l'altro ramo del bottone
        // di ripescaggio (02/09/2026), quello senza nessun server da
        // interrogare. Due rami Blade diversi, e uno non compilato e' un 500
        // che nessun test verde vede — e' gia' successo l'01/09.
        [$fallitaBonifico] = $this->makeAspiranteAgente();
        $this->pagamentoAgenteFallito($fallitaBonifico)
            ->update(['payment_method' => AgentCodeFeePayment::METHOD_BANK_TRANSFER]);

        [$annullata] = $this->makeAspiranteAgente();
        $this->pagamentoAgenteFallito($annullata)
            ->update(['status' => AgentCodeFeePayment::STATUS_CANCELLED]);

        $this->actingAs($this->superAdmin)
            ->get('/admin/quote-codice-agente')
            ->assertOk()
            ->assertSee('Annulla quota')
            ->assertSee('Verifica e salda')
            ->assertSee('Salda comunque');
    }

    public function test_la_scheda_utente_si_apre_con_la_quota_dovuta_esonerata_o_pagata(): void
    {
        $this->makeSystemAccount(0);
        $this->attivaQuota();

        // Dovuta.
        [$dovuta] = $this->makeAspiranteAgente();
        $this->actingAs($this->superAdmin)->get("/admin/users/{$dovuta->id}")
            ->assertOk()->assertSee('Esonera dalla quota');

        // Esonerata.
        app(AgentCodeFeeService::class)->waive($dovuta->fresh(), $this->superAdmin, 'Accordo commerciale.');
        $this->actingAs($this->superAdmin)->get("/admin/users/{$dovuta->id}")
            ->assertOk()->assertSee('Revoca l\'esonero', false);

        // Pagata.
        [$pagata] = $this->makeAspiranteAgente(fido: self::QUOTA);
        app(AgentCodeFeeService::class)->payWithKy($pagata);
        $this->actingAs($this->superAdmin)->get("/admin/users/{$pagata->id}")
            ->assertOk()->assertSee('Quote codice agente');

        // E chi non c'entra niente col percorso agente non vede la sezione.
        [$estraneo] = $this->makePrivate(0);
        $this->actingAs($this->superAdmin)->get("/admin/users/{$estraneo->id}")
            ->assertOk()->assertDontSee('Esonera dalla quota');
    }

    // ─── 8. I checkout aperti si chiudono col percorso (02/09/2026) ─────────

    /**
     * IL PIU' CARO DEI BUCHI TROVATI IL 02/09/2026, e vale 480 euro a colpo.
     *
     * Ogni click su "paga con carta" apre una riga `pending` e una sessione
     * Stripe che resta valida per ore. Dal 01/09 il webhook accredita
     * QUALUNQUE riga che non sia gia' `completed` o `cancelled` — tolleranza
     * voluta, serve a ripescare chi ha pagato davvero. Ma nessuno chiudeva le
     * righe quando il percorso si chiudeva: si rinunciava con la scheda
     * ancora aperta, si pagava lo stesso, e il circuito incassava 480 euro
     * per un codice che non sarebbe mai arrivato.
     *
     * Questo test e' scritto dalla parte che conta — il webhook, con la firma
     * vera e con Stripe che CONFERMA l'incasso. Se un giorno qualcuno rimette
     * `failed` al posto di `cancelled` in closeOpenAttempts(), qui torna
     * rosso: `failed` e' voluto che resti ripescabile.
     */
    public function test_dopo_la_rinuncia_il_webhook_non_incassa_piu_i_quattrocentottanta(): void
    {
        $this->attivaQuota();
        [$aspirante] = $this->makeAspiranteAgente();
        $this->fingiStripe(true);

        $aperto = $this->checkoutAperto($aspirante);

        $this->actingAs($aspirante)->post('/mlm/quota-codice/rinuncia')
            ->assertRedirect(route('portal.dashboard'));

        $this->postWebhookStripe($aperto->stripe_checkout_session_id)->assertOk();

        $this->assertFalse($aperto->fresh()->isCompleted());
        $this->assertTrue($aperto->fresh()->isCancelled());
        $this->assertNull($aspirante->fresh()->agent_code_fee_paid_at);
    }

    public function test_dopo_il_rifiuto_dell_admin_il_webhook_non_incassa_piu_i_quattrocentottanta(): void
    {
        $this->attivaQuota();
        [$aspirante] = $this->makeAspiranteAgente();
        $this->fingiStripe(true);

        $aperto = $this->checkoutAperto($aspirante);

        $this->actingAs($this->superAdmin)
            ->post(route('admin.mlm.requests.reject', $aspirante), ['reason' => 'Documenti non conformi.'])
            ->assertRedirect();

        $this->postWebhookStripe($aperto->stripe_checkout_session_id)->assertOk();

        $this->assertTrue($aperto->fresh()->isCancelled());
        $this->assertNull($aspirante->fresh()->agent_code_fee_paid_at);
    }

    /**
     * Anche il bonifico in attesa si chiude: la sua causale non deve restare
     * viva addosso a una richiesta che non esiste piu'. E' l'unico stato,
     * insieme a `pending`, che significa "stiamo ancora aspettando qualcosa".
     */
    public function test_si_chiude_anche_il_bonifico_rimasto_in_attesa(): void
    {
        $this->attivaQuota();
        [$aspirante] = $this->makeAspiranteAgente();

        $bonifico = app(AgentCodeFeeService::class)
            ->startPayment($aspirante, AgentCodeFeePayment::METHOD_BANK_TRANSFER);

        $this->assertTrue($bonifico->isPendingBankTransfer());

        $this->actingAs($aspirante)->post('/mlm/quota-codice/rinuncia')->assertRedirect();

        $this->assertTrue($bonifico->fresh()->isCancelled());
    }

    /**
     * SI CHIUDE ANCHE A CHI HA GIA' PAGATO, e non e' un di piu': chi salda in
     * KY puo' avere lasciato indietro un checkout con carta abbandonato, e
     * quella riga incasserebbe una seconda volta 480 euro — senza nemmeno il
     * Log::warning del doppio incasso che esiste sui privati, perche' qui non
     * c'e' nessun accredito da cui accorgersene.
     */
    public function test_chi_ha_pagato_in_ky_non_si_fa_incassare_una_seconda_volta_dal_checkout_abbandonato(): void
    {
        $this->makeSystemAccount(0);
        $this->attivaQuota();
        [$aspirante] = $this->makeAspiranteAgente(fido: self::QUOTA);
        $this->fingiStripe(true);

        $abbandonato = $this->checkoutAperto($aspirante);
        app(AgentCodeFeeService::class)->payWithKy($aspirante->fresh());

        $this->actingAs($aspirante->fresh())->post('/mlm/quota-codice/rinuncia')->assertRedirect();

        $this->postWebhookStripe($abbandonato->stripe_checkout_session_id)->assertOk();

        $this->assertTrue($abbandonato->fresh()->isCancelled());
        $this->assertSame(1, AgentCodeFeePayment::where('user_id', $aspirante->id)
            ->where('status', AgentCodeFeePayment::STATUS_COMPLETED)->count());
    }

    /**
     * Le righe gia' chiuse non si toccano. `failed` in particolare: e' lo
     * stato di un accredito andato storto o di un tentativo dato per
     * abbandonato, e webhook e pagina di esito devono poterlo ancora
     * riaprire se il pagamento e' arrivato davvero (01/09/2026). Marcarlo
     * `cancelled` da qui vorrebbe dire buttare via un incasso vero.
     */
    public function test_la_chiusura_non_tocca_le_righe_gia_chiuse(): void
    {
        $this->attivaQuota();
        [$aspirante] = $this->makeAspiranteAgente();

        $fallita = $this->pagamentoAgenteFallito($aspirante);

        $this->actingAs($aspirante)->post('/mlm/quota-codice/rinuncia')->assertRedirect();

        $this->assertSame(AgentCodeFeePayment::STATUS_FAILED, $fallita->fresh()->status);
    }

    // ─── 14. Il margine se ne va col percorso (02/09/2026) ─────────────────

    /**
     * DECISIONE DI LAURA, 02/09/2026. Il fido aggiuntivo esisteva per una
     * ragione sola: reggere il -480 di chi pagava la quota in KY. Chiuso il
     * percorso, chiusa la ragione. Chi aveva pagato cosi' resta con il conto
     * SOTTO il limite — puo' incassare, non inviare — ed e' voluto: la quota
     * resta pagata, i KY restano al circuito, e la capienza in piu' non ha
     * piu' motivo di esistere.
     */
    public function test_rinunciando_il_margine_concesso_per_la_quota_se_ne_va(): void
    {
        $this->makeSystemAccount(0);
        $this->attivaQuota();
        [$aspirante, $conto] = $this->makeAspiranteAgente();

        app(AgentCodeFeeService::class)->payWithKy($aspirante);

        // Prima: sotto di 480, ma dentro il margine concesso.
        $this->assertSame(-self::QUOTA, (int) $conto->fresh()->available_balance);
        $this->assertSame(self::QUOTA, $conto->fresh()->massimale());

        $this->actingAs($aspirante->fresh())->post('/mlm/quota-codice/rinuncia')
            ->assertRedirect(route('portal.dashboard'))
            ->assertSessionHas('portal_success', fn (string $m): bool => str_contains($m, 'non inviare KY'));

        $dopo = $aspirante->fresh();

        $this->assertSame(0, (int) $dopo->agent_code_fee_ky_allowance_cents);
        // Il saldo NON si muove: la quota resta pagata, i KY restano al
        // circuito. Quel che cambia e' quanto puo' scendere.
        $this->assertSame(-self::QUOTA, (int) $conto->fresh()->available_balance);
        $this->assertSame(0, $conto->fresh()->massimale());
        $this->assertSame(-self::QUOTA, $conto->fresh()->saldoDisponibile());
    }

    /**
     * Stessa regola quando a chiudere il percorso e' l'admin: altrimenti
     * converrebbe farsi rifiutare invece di rinunciare. E l'interessato lo
     * legge nella mail del rifiuto, non lo scopre al primo pagamento respinto.
     */
    public function test_anche_il_rifiuto_dell_admin_toglie_il_margine(): void
    {
        $this->makeSystemAccount(0);
        $this->attivaQuota();
        [$aspirante, $conto] = $this->makeAspiranteAgente();

        app(AgentCodeFeeService::class)->payWithKy($aspirante);

        $this->actingAs($this->superAdmin)
            ->post(route('admin.mlm.requests.reject', $aspirante), ['reason' => 'Documenti non conformi.'])
            ->assertRedirect()
            ->assertSessionHas('portal_success', fn (string $m): bool => str_contains($m, 'sotto il limite'));

        $this->assertSame(0, (int) $aspirante->fresh()->agent_code_fee_ky_allowance_cents);
        $this->assertSame(0, $conto->fresh()->massimale());

        Notification::assertSentTo(
            $aspirante->fresh(),
            \App\Notifications\MlmAgentRequestReviewedNotification::class,
            fn ($notifica): bool => $notifica->fidoTolto === self::QUOTA
        );
    }

    /** In euro nessun margine e' mai stato concesso: non c'e' niente da togliere. */
    public function test_chi_ha_pagato_in_euro_non_ha_nessun_margine_da_perdere(): void
    {
        $this->attivaQuota();
        [$aspirante, $conto] = $this->makeAspiranteAgente();

        $pagamento = $this->checkoutAperto($aspirante);
        app(AgentCodeFeeService::class)->completeEuroPayment($pagamento);

        $this->actingAs($aspirante->fresh())->post('/mlm/quota-codice/rinuncia')
            ->assertRedirect()
            ->assertSessionHas('portal_success', fn (string $m): bool => str_contains($m, 'pienamente operativo'));

        $this->assertSame(0, (int) $conto->fresh()->available_balance);
        $this->assertSame(0, (int) $aspirante->fresh()->agent_code_fee_ky_allowance_cents);
    }

    /**
     * La via d'uscita vera per chi vuole indietro i soldi: l'annullamento dal
     * backoffice storna il movimento e riporta il saldo a zero. Il margine era
     * gia' andato via con la rinuncia, e non deve tornare.
     */
    public function test_annullare_dopo_la_rinuncia_riporta_il_saldo_a_zero(): void
    {
        $this->makeSystemAccount(0);
        $this->attivaQuota();
        [$aspirante, $conto] = $this->makeAspiranteAgente();

        app(AgentCodeFeeService::class)->payWithKy($aspirante);
        app(AgentCodeFeeService::class)->giveUp($aspirante->fresh());

        $pagamento = AgentCodeFeePayment::where('user_id', $aspirante->id)->firstOrFail();
        app(AgentCodeFeeService::class)->cancel($pagamento, $this->superAdmin, 'Rimborso concordato.');

        $this->assertSame(0, (int) $conto->fresh()->available_balance);
        $this->assertSame(0, (int) $aspirante->fresh()->agent_code_fee_ky_allowance_cents);
        $this->assertSame(0, $conto->fresh()->massimale());
    }

    /** Un checkout con carta aperto e mai concluso: la riga che il webhook vede. */
    private function checkoutAperto(User $utente): AgentCodeFeePayment
    {
        return AgentCodeFeePayment::create([
            'user_id'                    => $utente->id,
            'amount_eur_cents'           => self::QUOTA,
            'ky_amount'                  => self::QUOTA,
            'status'                     => AgentCodeFeePayment::STATUS_PENDING,
            'payment_method'             => AgentCodeFeePayment::METHOD_STRIPE,
            'stripe_checkout_session_id' => 'cs_test_' . Str::random(16),
        ]);
    }

    // ─── 9. La ricevuta dei 480 (02/09/2026) ────────────────────────────────

    /**
     * Per tre giorni chi pagava 480 euro non riceveva niente, mentre chi ne
     * pagava 30 riceveva la sua ricevuta dall'01/09. L'unico segnale era la
     * pagina di esito, che si vede solo se non si chiude la scheda.
     */
    public function test_chi_paga_in_ky_riceve_la_ricevuta(): void
    {
        $this->makeSystemAccount(0);
        $this->attivaQuota();
        [$aspirante] = $this->makeAspiranteAgente(fido: self::QUOTA);

        app(AgentCodeFeeService::class)->payWithKy($aspirante);

        Notification::assertSentTo($aspirante, AgentCodeFeePaidNotification::class);
    }

    public function test_chi_paga_in_euro_riceve_la_ricevuta(): void
    {
        $this->attivaQuota();
        [$aspirante] = $this->makeAspiranteAgente();

        // Riga costruita a mano e non da startPayment(): attivaQuota() lascia
        // Stripe spento, e qui interessa la chiusura del pagamento, non i
        // metodi accesi.
        $pagamento = $this->checkoutAperto($aspirante);

        app(AgentCodeFeeService::class)->completeEuroPayment($pagamento);

        Notification::assertSentTo($aspirante, AgentCodeFeePaidNotification::class);
    }

    /**
     * Webhook e pagina di esito possono arrivare insieme: la seconda chiamata
     * esce dalla transazione senza scrivere niente, e non deve mandare una
     * seconda ricevuta. Guardare lo stato fuori dalla transazione non
     * basterebbe — lo troverebbe saldato in tutti e due i casi.
     */
    public function test_la_ricevuta_non_parte_due_volte(): void
    {
        $this->attivaQuota();
        [$aspirante] = $this->makeAspiranteAgente();

        $pagamento = $this->checkoutAperto($aspirante);

        // La stessa copia in mano a due richieste diverse: e' cosi' che la
        // corsa avviene davvero, e la guardia in cima al metodo non scatta.
        $copia = AgentCodeFeePayment::find($pagamento->id);

        app(AgentCodeFeeService::class)->completeEuroPayment($pagamento);
        app(AgentCodeFeeService::class)->completeEuroPayment($copia);

        Notification::assertSentToTimes($aspirante, AgentCodeFeePaidNotification::class, 1);
    }

    // ─── 10. Il bonifico si riprende, non si riapre (02/09/2026) ────────────

    /**
     * La causale contiene l'uuid del pagamento. Aprirne uno nuovo a ogni visita
     * vuol dire dare all'utente una causale diversa da quella che ha gia'
     * scritto sul bonifico vero, e ritrovarsi tre righe `pending_bank_transfer`
     * per la stessa persona senza sapere quale sia quella buona.
     */
    public function test_il_bonifico_gia_chiesto_si_riprende_invece_di_riaprirlo(): void
    {
        $this->attivaQuota();
        [$aspirante] = $this->makeAspiranteAgente();

        $this->actingAs($aspirante)->post('/mlm/quota-codice/bonifico')->assertOk();
        $primo = AgentCodeFeePayment::where('user_id', $aspirante->id)->latest('id')->firstOrFail();

        $this->actingAs($aspirante)->post('/mlm/quota-codice/bonifico')->assertOk();

        $this->assertSame(1, AgentCodeFeePayment::where('user_id', $aspirante->id)->count());
        $this->assertSame(
            $primo->bank_transfer_reference,
            $primo->fresh()->bank_transfer_reference
        );
    }

    public function test_la_pagina_mostra_il_bonifico_in_corso_invece_dei_bottoni(): void
    {
        $this->attivaQuota();
        [$aspirante] = $this->makeAspiranteAgente();

        $this->actingAs($aspirante)->post('/mlm/quota-codice/bonifico')->assertOk();
        $bonifico = AgentCodeFeePayment::where('user_id', $aspirante->id)->latest('id')->firstOrFail();

        $this->actingAs($aspirante->fresh())->get('/mlm/quota-codice')
            ->assertOk()
            ->assertSee('Hai scelto il bonifico bancario')
            ->assertSee($bonifico->bank_transfer_reference)
            ->assertSee('Cambia metodo di pagamento');
    }

    /**
     * `failed` e non `cancelled`: se il bonifico arriva lo stesso, l'admin lo
     * deve poter ancora dare per saldato. `cancelled` e' riservato alle
     * risposte gia' date.
     */
    public function test_cambia_metodo_chiude_il_bonifico_e_riporta_alla_scelta(): void
    {
        $this->attivaQuota();
        [$aspirante] = $this->makeAspiranteAgente();

        $this->actingAs($aspirante)->post('/mlm/quota-codice/bonifico')->assertOk();
        $bonifico = AgentCodeFeePayment::where('user_id', $aspirante->id)->latest('id')->firstOrFail();

        $this->actingAs($aspirante->fresh())->post('/mlm/quota-codice/bonifico/annulla')
            ->assertRedirect(route('portal.mlm.agent-code-fee.show'));

        $this->assertSame(AgentCodeFeePayment::STATUS_FAILED, $bonifico->fresh()->status);

        $this->actingAs($aspirante->fresh())->get('/mlm/quota-codice')
            ->assertOk()
            ->assertDontSee('Hai scelto il bonifico bancario');
    }

    // ─── 11. PayPal: la rete di sicurezza che non c'era (02/09/2026) ────────

    /**
     * PayPal, in questo progetto, non ha nessun webhook: l'unica strada che
     * saldava era la `capture` sincrona al ritorno. Chi chiudeva la scheda un
     * istante prima aveva pagato 480 euro e nessun processo lo recuperava.
     * Ora la pagina di esito CHIEDE a PayPal, come faceva gia' con Stripe.
     */
    public function test_la_pagina_di_esito_salda_un_pagamento_paypal_incassato(): void
    {
        $this->attivaQuota();
        [$aspirante] = $this->makeAspiranteAgente();
        $this->fingiPayPal(true);

        $pagamento = $this->pagamentoPaypalFallito($aspirante);

        $this->actingAs($aspirante)->get('/mlm/quota-codice/esito/' . $pagamento->uuid)->assertOk();

        $this->assertTrue($pagamento->fresh()->isCompleted());
        $this->assertNotNull($aspirante->fresh()->agent_code_fee_paid_at);
    }

    public function test_senza_la_prova_di_paypal_la_pagina_di_esito_non_salda_niente(): void
    {
        $this->attivaQuota();
        [$aspirante] = $this->makeAspiranteAgente();
        $this->fingiPayPal(false);

        $pagamento = $this->pagamentoPaypalFallito($aspirante);

        $this->actingAs($aspirante)->get('/mlm/quota-codice/esito/' . $pagamento->uuid)->assertOk();

        $this->assertFalse($pagamento->fresh()->isCompleted());
        $this->assertNull($aspirante->fresh()->agent_code_fee_paid_at);
    }

    /** L'altra meta': una quota annullata apposta non si riapre da sola. */
    public function test_la_pagina_di_esito_non_resuscita_una_quota_paypal_annullata(): void
    {
        $this->attivaQuota();
        [$aspirante] = $this->makeAspiranteAgente();
        $this->fingiPayPal(true);

        $pagamento = $this->pagamentoPaypalFallito($aspirante);
        $pagamento->update(['status' => AgentCodeFeePayment::STATUS_CANCELLED]);

        $this->actingAs($aspirante)->get('/mlm/quota-codice/esito/' . $pagamento->uuid)->assertOk();

        $this->assertFalse($pagamento->fresh()->isCompleted());
        $this->assertNull($aspirante->fresh()->agent_code_fee_paid_at);
    }

    // ─── 12. Il ripescaggio dal backoffice (02/09/2026) ─────────────────────

    public function test_l_admin_ripesca_una_quota_agente_finita_failed(): void
    {
        $this->attivaQuota();
        [$aspirante] = $this->makeAspiranteAgente();
        $this->fingiStripe(true);

        $pagamento = $this->pagamentoAgenteFallito($aspirante);

        $this->actingAs($this->superAdmin)
            ->post(route('admin.agent-code-fees.retry-credit', $pagamento))
            ->assertRedirect(route('admin.agent-code-fees.index'));

        $this->assertTrue($pagamento->fresh()->isCompleted());
        $this->assertNotNull($aspirante->fresh()->agent_code_fee_paid_at);
    }

    public function test_senza_prova_il_ripescaggio_non_salda_niente(): void
    {
        $this->attivaQuota();
        [$aspirante] = $this->makeAspiranteAgente();
        $this->fingiStripe(false);

        $pagamento = $this->pagamentoAgenteFallito($aspirante);

        $this->actingAs($this->superAdmin)
            ->post(route('admin.agent-code-fees.retry-credit', $pagamento))
            ->assertRedirect();

        $this->assertFalse($pagamento->fresh()->isCompleted());
        $this->assertNull($aspirante->fresh()->agent_code_fee_paid_at);
    }

    /**
     * Il bonifico non ha nessun server da interrogare: la prova e' l'admin, e
     * dev'essere una spunta esplicita. Senza, il bottone diventa un modo per
     * regalare il codice agente premendolo per inerzia.
     */
    public function test_il_bonifico_si_salda_solo_con_la_conferma_esplicita(): void
    {
        $this->attivaQuota();
        [$aspirante] = $this->makeAspiranteAgente();

        $pagamento = $this->pagamentoAgenteFallito($aspirante);
        $pagamento->update(['payment_method' => AgentCodeFeePayment::METHOD_BANK_TRANSFER]);

        $this->actingAs($this->superAdmin)
            ->post(route('admin.agent-code-fees.retry-credit', $pagamento))
            ->assertRedirect();

        $this->assertFalse($pagamento->fresh()->isCompleted());

        $this->actingAs($this->superAdmin)
            ->post(route('admin.agent-code-fees.retry-credit', $pagamento), ['bonifico_ricevuto' => '1'])
            ->assertRedirect();

        $this->assertTrue($pagamento->fresh()->isCompleted());
    }

    /**
     * IL SERVIZIO SI PRENDE IL SUO TEST, e non e' un doppione di quello qui
     * sotto: dalla rotta un pagamento in KY finisce nel ramo finale del
     * controller e non arriva mai alla guardia del servizio — spegnendola, la
     * suite restava verde. E' la stessa coppia di difese che si nasconde a
     * vicenda gia' vista undici volte, e la stessa soluzione adottata l'01/09
     * per la gemella dei privati: la guardia resta nel servizio, dove protegge
     * QUALUNQUE chiamante, e si prova chiamandolo direttamente.
     */
    public function test_il_servizio_rifiuta_il_ripescaggio_di_un_pagamento_in_ky(): void
    {
        $this->attivaQuota();
        [$aspirante] = $this->makeAspiranteAgente();

        $pagamento = $this->pagamentoAgenteFallito($aspirante);
        $pagamento->update(['payment_method' => AgentCodeFeePayment::METHOD_KY]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('vale solo per i pagamenti in euro');

        app(AgentCodeFeeService::class)->retryEuroCredit($pagamento, $this->superAdmin);
    }

    public function test_il_ripescaggio_non_vale_sui_pagamenti_in_ky(): void
    {
        $this->makeSystemAccount(0);
        $this->attivaQuota();
        [$aspirante] = $this->makeAspiranteAgente(fido: self::QUOTA);

        $pagamento = $this->pagamentoAgenteFallito($aspirante);
        $pagamento->update(['payment_method' => AgentCodeFeePayment::METHOD_KY]);

        $this->actingAs($this->superAdmin)
            ->post(route('admin.agent-code-fees.retry-credit', $pagamento))
            ->assertRedirect();

        $this->assertFalse($pagamento->fresh()->isCompleted());
        $this->assertNull($aspirante->fresh()->agent_code_fee_paid_at);
    }

    // ─── 13. Il comando notturno legge anche questa quota (02/09/2026) ──────

    public function test_il_comando_notturno_chiude_anche_i_tentativi_della_quota_agente(): void
    {
        $this->attivaQuota();
        [$aspirante] = $this->makeAspiranteAgente();

        $vecchio = $this->checkoutAperto($aspirante);
        $vecchio->forceFill(['created_at' => now()->subDays(3)])->save();

        $recente = $this->checkoutAperto($aspirante);

        $this->artisan('quote:scadi-tentativi')->assertSuccessful();

        $this->assertSame(AgentCodeFeePayment::STATUS_FAILED, $vecchio->fresh()->status);
        $this->assertSame(AgentCodeFeePayment::STATUS_PENDING, $recente->fresh()->status);
    }

    /** I bonifici aspettare e' il loro mestiere: non si chiudono d'ufficio. */
    public function test_il_comando_notturno_non_tocca_mai_i_bonifici_della_quota_agente(): void
    {
        $this->attivaQuota();
        [$aspirante] = $this->makeAspiranteAgente();

        $bonifico = app(AgentCodeFeeService::class)
            ->startPayment($aspirante, AgentCodeFeePayment::METHOD_BANK_TRANSFER);
        $bonifico->forceFill(['created_at' => now()->subDays(30)])->save();

        $this->artisan('quote:scadi-tentativi')->assertSuccessful();

        $this->assertTrue($bonifico->fresh()->isPendingBankTransfer());
    }

    /**
     * Sostituisce il verificatore di PayPal, come si fa gia' con quello di
     * Stripe: e' l'unico modo di provare che si salda SOLO quando la prova
     * c'e', senza chiamare i server di PayPal.
     */
    private function fingiPayPal(bool $incassato): void
    {
        $this->instance(
            \App\Services\PayPalOrderVerifier::class,
            new class($incassato) extends \App\Services\PayPalOrderVerifier {
                public function __construct(private readonly bool $incassato) {}

                public function isCompletedFor(?string $storedOrderId, int $expectedAmountCents, string $expectedReference, string $context = 'paypal'): bool
                {
                    return $this->incassato;
                }
            }
        );
    }

    /** Una riga PayPal finita male: quella che la pagina di esito deve riaprire. */
    private function pagamentoPaypalFallito(User $utente): AgentCodeFeePayment
    {
        return AgentCodeFeePayment::create([
            'user_id'          => $utente->id,
            'amount_eur_cents' => self::QUOTA,
            'ky_amount'        => self::QUOTA,
            'status'           => AgentCodeFeePayment::STATUS_FAILED,
            'payment_method'   => AgentCodeFeePayment::METHOD_PAYPAL,
            'paypal_order_id'  => 'PAY-' . Str::random(14),
            'admin_notes'      => 'Ritorno da PayPal mai arrivato.',
        ]);
    }

    // ─── Aiutanti ───────────────────────────────────────────────────────────

    /**
     * Sostituisce il verificatore di Stripe con uno che risponde quello che
     * serve al test: e' l'unico modo di provare che il ripescaggio accredita
     * SOLO quando la prova c'e', senza chiamare i server di Stripe.
     */
    private function fingiStripe(bool $pagata): void
    {
        $this->instance(
            \App\Services\StripeCheckoutVerifier::class,
            new class($pagata) extends \App\Services\StripeCheckoutVerifier {
                public function __construct(private readonly bool $pagata) {}

                public function isPaidFor(?string $storedSessionId, int $expectedAmountCents, string $expectedReference, string $context = 'stripe'): bool
                {
                    return $this->pagata;
                }

                public function sessionMatches(object $session, int $expectedAmountCents, string $expectedReference, string $context = 'stripe'): bool
                {
                    return $this->pagata;
                }
            }
        );
    }

    /** Una riga in euro finita male: quella che il webhook deve poter riaprire. */
    private function pagamentoAgenteFallito(User $utente): AgentCodeFeePayment
    {
        return AgentCodeFeePayment::create([
            'user_id'                    => $utente->id,
            'amount_eur_cents'           => self::QUOTA,
            'ky_amount'                  => self::QUOTA,
            'status'                     => AgentCodeFeePayment::STATUS_FAILED,
            'payment_method'             => AgentCodeFeePayment::METHOD_STRIPE,
            'stripe_checkout_session_id' => 'cs_test_' . Str::random(16),
            'admin_notes'                => 'Accredito non riuscito.',
        ]);
    }

    /**
     * Il webhook vero, firma compresa: la firma la controlla la libreria di
     * Stripe prima ancora di guardare il contenuto, quindi un test che non la
     * calcola non entra nemmeno nel metodo.
     */
    private function postWebhookStripe(string $sessionId): \Illuminate\Testing\TestResponse
    {
        config([
            'services.stripe.secret'         => 'sk_test_finta',
            'services.stripe.webhook_secret' => 'whsec_test_finta',
        ]);

        $payload = json_encode([
            'id'   => 'evt_' . Str::random(12),
            'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'id'             => $sessionId,
                'object'         => 'checkout.session',
                'payment_intent' => 'pi_' . Str::random(12),
                'amount_total'   => self::QUOTA,
            ]],
        ], JSON_THROW_ON_ERROR);

        $t    = time();
        $firma = hash_hmac('sha256', $t . '.' . $payload, 'whsec_test_finta');

        return $this->call(
            'POST',
            '/stripe/webhook',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => 't=' . $t . ',v1=' . $firma],
            $payload
        );
    }

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
