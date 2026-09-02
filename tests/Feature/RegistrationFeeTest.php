<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureRegistrationFeePaid;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\RegistrationFeePayment;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\Transfer;
use App\Models\User;
use App\Notifications\RegistrationFeeCancelledNotification;
use App\Notifications\RegistrationFeeRequestedNotification;
use App\Services\RegistrationFeeService;
use App\Services\TransferBookingService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Quota di iscrizione dei privati (31/08/2026).
 *
 * Le tre cose che questi test devono davvero dimostrare, perche' sono le tre
 * su cui si perdono soldi veri se sbagliate:
 *
 *  1. la quota tocca SOLO i privati che si registrano da quando e' accesa —
 *     un errore qui mette in debito milletrecento persone gia' iscritte;
 *  2. pagando in euro l'utente RICEVE i KY (non e' un costo in KY), pagando
 *     in KY va sotto e i KY finiscono al conto di sistema;
 *  3. il -30 non mangia il fido: dopo aver pagato in KY con fido 50 il conto
 *     deve poter arrivare a -80, e fermarsi li'.
 */
class RegistrationFeeTest extends TestCase
{
    use RefreshDatabase;

    private const QUOTA = 3000; // 30,00

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Mail::fake();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->makeSuperAdmin();

        // Senza IBAN in configurazione il bonifico non e' un metodo
        // disponibile: e' la stessa regola che vale in produzione
        // (SystemSetting::registrationFeeMethods).
        config(['kmoney.bank_iban' => 'IT60X0542811101000000123456']);
    }

    // ─── 1. A chi si applica ────────────────────────────────────────────────

    public function test_un_privato_che_si_registra_con_la_quota_attiva_la_deve(): void
    {
        $this->attivaQuota();

        $this->post('/register', $this->datiRegistrazione());

        $utente = User::where('email', 'nuovo@test.test')->firstOrFail();

        $this->assertSame(self::QUOTA, (int) $utente->registration_fee_due_cents);
        $this->assertNull($utente->registration_fee_paid_at);
        $this->assertTrue(app(RegistrationFeeService::class)->isDueFor($utente));
    }

    public function test_con_la_quota_spenta_nessuno_deve_niente(): void
    {
        $this->attivaQuota(attiva: false);

        $this->post('/register', $this->datiRegistrazione());

        $utente = User::where('email', 'nuovo@test.test')->firstOrFail();

        $this->assertNull($utente->registration_fee_due_cents);
        $this->assertFalse(app(RegistrationFeeService::class)->isDueFor($utente));
    }

    public function test_le_aziende_non_pagano_la_quota_dei_privati(): void
    {
        $this->attivaQuota();

        $this->post('/register', $this->datiRegistrazione([
            'account_holder_type' => 'company',
            'company_name'        => 'Azienda Nuova SRL',
        ]));

        $utente = User::where('email', 'nuovo@test.test')->firstOrFail();

        $this->assertNull($utente->registration_fee_due_cents);
    }

    public function test_chi_era_gia_iscritto_non_deve_niente_neanche_dopo_l_attivazione(): void
    {
        [$vecchio] = $this->makePrivate(0);
        $this->attivaQuota();

        $this->assertFalse(app(RegistrationFeeService::class)->isDueFor($vecchio->fresh()));
    }

    public function test_l_importo_e_uno_scatto_alla_registrazione_e_non_segue_l_admin(): void
    {
        $this->attivaQuota();
        $this->post('/register', $this->datiRegistrazione());

        // L'admin raddoppia la quota il giorno dopo.
        $this->attivaQuota(importo: 6000);

        $utente = User::where('email', 'nuovo@test.test')->firstOrFail();

        $this->assertSame(self::QUOTA, (int) $utente->registration_fee_due_cents);
    }

    // ─── 2. Pagamento in KY ─────────────────────────────────────────────────

    public function test_pagando_in_ky_il_conto_va_sotto_e_i_ky_vanno_al_conto_di_sistema(): void
    {
        $sistema = $this->makeSystemAccount(0);
        [$utente, $conto] = $this->makePrivateConQuota(0);

        app(RegistrationFeeService::class)->payWithKy($utente);

        $this->assertSame(-self::QUOTA, (int) $conto->fresh()->available_balance);
        $this->assertSame(self::QUOTA, (int) $sistema->fresh()->available_balance);
        $this->assertNotNull($utente->fresh()->registration_fee_paid_at);
        $this->assertFalse(app(RegistrationFeeService::class)->isDueFor($utente->fresh()));
    }

    public function test_pagando_in_ky_non_arrivano_ky_all_utente(): void
    {
        $this->makeSystemAccount(0);
        [$utente, $conto] = $this->makePrivateConQuota(1000);

        app(RegistrationFeeService::class)->payWithKy($utente);

        // 10,00 - 30,00 = -20,00. Se qui uscisse 1000 vorrebbe dire che
        // qualcuno ha accreditato i KY anche in questo ramo.
        $this->assertSame(1000 - self::QUOTA, (int) $conto->fresh()->available_balance);
    }

    public function test_due_click_sul_bottone_paga_in_ky_addebitano_una_volta_sola(): void
    {
        $this->makeSystemAccount(0);
        [$utente, $conto] = $this->makePrivateConQuota(0);

        app(RegistrationFeeService::class)->payWithKy($utente);

        try {
            app(RegistrationFeeService::class)->payWithKy($utente->fresh());
            $this->fail('Il secondo pagamento doveva essere rifiutato.');
        } catch (\RuntimeException $e) {
            // atteso
        }

        $this->assertSame(-self::QUOTA, (int) $conto->fresh()->available_balance);
        $this->assertSame(1, Transfer::where('kind', 'registration_fee')->count());
    }

    // ─── 3. Il fido non viene mangiato dalla quota ──────────────────────────

    public function test_con_fido_cinquanta_dopo_la_quota_il_conto_arriva_a_meno_ottanta(): void
    {
        $this->makeSystemAccount(0);
        [$utente, $conto] = $this->makePrivateConQuota(0, fido: 5000);
        [$destinatario, $contoDestinatario] = $this->makePrivate(0);

        app(RegistrationFeeService::class)->payWithKy($utente);

        // Il conto e' a -30. Il fido concesso e' 50: deve poter spendere
        // ancora 50 esatti, arrivando a -80.
        app(TransferBookingService::class)->book([
            'initiated_by'    => $utente->id,
            'from_account_id' => $conto->id,
            'to_account_id'   => $contoDestinatario->id,
            'amount'          => 5000,
            'kind'            => 'portal_transfer',
            'idempotency_key' => 'test_' . Str::random(8),
        ]);

        $this->assertSame(-8000, (int) $conto->fresh()->available_balance);
    }

    public function test_oltre_il_fido_piu_la_quota_il_motore_rifiuta(): void
    {
        $this->makeSystemAccount(0);
        [$utente, $conto] = $this->makePrivateConQuota(0, fido: 5000);
        [$destinatario, $contoDestinatario] = $this->makePrivate(0);

        app(RegistrationFeeService::class)->payWithKy($utente);

        $this->expectException(\RuntimeException::class);

        app(TransferBookingService::class)->book([
            'initiated_by'    => $utente->id,
            'from_account_id' => $conto->id,
            'to_account_id'   => $contoDestinatario->id,
            'amount'          => 5001, // un centesimo oltre -80,00
            'kind'            => 'portal_transfer',
            'idempotency_key' => 'test_' . Str::random(8),
        ]);
    }

    public function test_il_massimale_mostrato_include_la_quota(): void
    {
        $this->makeSystemAccount(0);
        [$utente, $conto] = $this->makePrivateConQuota(0, fido: 5000);

        app(RegistrationFeeService::class)->payWithKy($utente);

        // Quel che si vede deve coincidere con quel che il motore consente:
        // 50 di fido + 30 di quota.
        $this->assertSame(8000, $conto->fresh()->massimale());
    }

    public function test_chi_non_ha_pagato_la_quota_in_ky_non_riceve_nessun_fido_in_piu(): void
    {
        [, $conto] = $this->makePrivate(0, fido: 5000);

        $this->assertSame(5000, $conto->fresh()->massimale());
    }

    // ─── 4. Pagamento in euro ───────────────────────────────────────────────

    public function test_pagando_in_euro_l_utente_riceve_i_ky_dal_conto_di_sistema(): void
    {
        $sistema = $this->makeSystemAccount(100000);
        [$utente, $conto] = $this->makePrivateConQuota(0);

        $pagamento = app(RegistrationFeeService::class)
            ->startPayment($utente, RegistrationFeePayment::METHOD_BANK_TRANSFER);

        app(RegistrationFeeService::class)->completeEuroPayment($pagamento);

        $this->assertSame(self::QUOTA, (int) $conto->fresh()->available_balance);
        $this->assertSame(100000 - self::QUOTA, (int) $sistema->fresh()->available_balance);
        $this->assertNotNull($utente->fresh()->registration_fee_paid_at);
        $this->assertTrue($pagamento->fresh()->isCompleted());
    }

    public function test_pagando_in_euro_il_conto_non_riceve_nessun_fido_aggiuntivo(): void
    {
        $this->makeSystemAccount(100000);
        [$utente, $conto] = $this->makePrivateConQuota(0, fido: 5000);

        $pagamento = app(RegistrationFeeService::class)
            ->startPayment($utente, RegistrationFeePayment::METHOD_BANK_TRANSFER);
        app(RegistrationFeeService::class)->completeEuroPayment($pagamento);

        // Ha comprato KY, non ha preso un debito: il fido resta quello concesso.
        $this->assertSame(0, (int) $utente->fresh()->registration_fee_ky_allowance_cents);
        $this->assertSame(5000, $conto->fresh()->massimale());
    }

    public function test_l_accredito_in_euro_chiamato_due_volte_accredita_una_volta_sola(): void
    {
        $sistema = $this->makeSystemAccount(100000);
        [$utente, $conto] = $this->makePrivateConQuota(0);

        $pagamento = app(RegistrationFeeService::class)
            ->startPayment($utente, RegistrationFeePayment::METHOD_BANK_TRANSFER);

        // La corsa vera: webhook Stripe e pagina di successo insieme.
        app(RegistrationFeeService::class)->completeEuroPayment($pagamento);
        app(RegistrationFeeService::class)->completeEuroPayment($pagamento->fresh());

        $this->assertSame(self::QUOTA, (int) $conto->fresh()->available_balance);
        $this->assertSame(100000 - self::QUOTA, (int) $sistema->fresh()->available_balance);
        $this->assertSame(1, Transfer::where('kind', 'registration_fee_credit')->count());
    }

    /**
     * SMASCHERATO DA UNA MUTAZIONE. Il test qui sopra ("chiamato due volte")
     * restava verde anche rendendo casuale la idempotency_key del transfer:
     * a fermare il secondo accredito bastava la guardia sullo stato, e
     * l'unica difesa che regge la corsa vera — due richieste che leggono
     * "non ancora completato" nello stesso istante — non era provata da
     * nessuno. E' lo stesso tranello del cashback il 31/08: due difese
     * ridondanti si nascondono a vicenda dal mutation testing.
     *
     * Qui lo stato viene riportato a mano a 'pending', come lo lascerebbe un
     * retry finito male, cosi' la prima difesa non puo' scattare e a reggere
     * resta solo la chiave.
     */
    public function test_con_lo_stato_tornato_indietro_i_ky_non_si_accreditano_due_volte(): void
    {
        $sistema = $this->makeSystemAccount(100000);
        [$utente, $conto] = $this->makePrivateConQuota(0);

        $pagamento = app(RegistrationFeeService::class)
            ->startPayment($utente, RegistrationFeePayment::METHOD_BANK_TRANSFER);

        app(RegistrationFeeService::class)->completeEuroPayment($pagamento);

        $pagamento->fresh()->forceFill([
            'status'       => RegistrationFeePayment::STATUS_PENDING,
            'completed_at' => null,
        ])->save();

        app(RegistrationFeeService::class)->completeEuroPayment($pagamento->fresh());

        $this->assertSame(self::QUOTA, (int) $conto->fresh()->available_balance);
        $this->assertSame(100000 - self::QUOTA, (int) $sistema->fresh()->available_balance);
        $this->assertSame(1, Transfer::where('kind', 'registration_fee_credit')->count());
    }

    public function test_l_admin_conferma_il_bonifico_e_i_ky_arrivano(): void
    {
        $this->makeSystemAccount(100000);
        [$utente, $conto] = $this->makePrivateConQuota(0);

        $pagamento = app(RegistrationFeeService::class)
            ->startPayment($utente, RegistrationFeePayment::METHOD_BANK_TRANSFER);

        $this->actingAs($this->superAdmin)
            ->post("/admin/quote-iscrizione/{$pagamento->id}/conferma")
            ->assertRedirect(route('admin.registration-fees.index'));

        $this->assertSame(self::QUOTA, (int) $conto->fresh()->available_balance);
        $this->assertSame($this->superAdmin->id, (int) $pagamento->fresh()->confirmed_by);
    }

    // ─── 5. Il blocco delle funzioni ────────────────────────────────────────

    public function test_con_la_quota_da_saldare_il_conto_resta_visibile(): void
    {
        [$utente] = $this->makePrivateConQuota(0);

        $this->actingAs($utente)->get('/dashboard')->assertOk();
        $this->actingAs($utente)->get('/movimenti')->assertOk();
    }

    public function test_con_la_quota_da_saldare_non_si_puo_inviare_ky(): void
    {
        [$utente] = $this->makePrivateConQuota(0);

        $this->actingAs($utente)->get('/invia')
            ->assertRedirect(route('portal.registration-fee.show'));
    }

    public function test_con_la_quota_da_saldare_non_si_puo_incassare(): void
    {
        [$utente] = $this->makePrivateConQuota(0);

        $this->actingAs($utente)->get('/incassa/qr')
            ->assertRedirect(route('portal.registration-fee.show'));
    }

    public function test_la_ricarica_resta_aperta_perche_e_la_strada_per_pagare(): void
    {
        [$utente] = $this->makePrivateConQuota(0);

        $this->actingAs($utente)->get('/ricarica')->assertOk();
    }

    public function test_la_pagina_della_quota_non_si_blocca_da_sola(): void
    {
        [$utente] = $this->makePrivateConQuota(0);

        $this->actingAs($utente)->get('/quota-iscrizione')->assertOk();
    }

    public function test_chi_non_deve_la_quota_non_viene_bloccato(): void
    {
        [$utente] = $this->makePrivate(0);

        $this->actingAs($utente)->get('/invia')->assertOk();
    }

    public function test_l_elenco_delle_rotte_bloccate_copre_pagare_incassare_e_comprare(): void
    {
        $radici = EnsureRegistrationFeePaid::radiciBloccate();

        // Un elenco accorciato per distrazione riapre una porta sui soldi:
        // queste quattro sono il minimo indispensabile e devono restarci.
        $this->assertContains('portal.invia', $radici);
        $this->assertContains('portal.pay', $radici);
        $this->assertContains('portal.incasso-qr', $radici);
        $this->assertContains('portal.cart.checkout', $radici);

        // 01/09/2026: autorizzare un'app a pagare al posto tuo mentre il conto
        // e' fermo. L'ADDEBITO vero passa da routes/api.php e lo ferma
        // Api\V1\MandateController::charge() — sono due porte diverse, e
        // servono tutte e due.
        $this->assertContains('oauth.mandate.grant', $radici);

        // E queste non devono MAI finirci: sono le vie d'uscita dal blocco.
        foreach (['portal.dashboard', 'portal.ky-cards', 'portal.registration-fee'] as $aperta) {
            $this->assertNotContains($aperta, $radici);
        }
    }

    // ─── 6. Impostazioni admin ──────────────────────────────────────────────

    public function test_l_admin_non_puo_attivare_la_quota_senza_metodi_di_pagamento(): void
    {
        $this->actingAs($this->superAdmin)
            ->post('/admin/quote-iscrizione/impostazioni', [
                'registration_fee_enabled' => '1',
                'registration_fee_amount'  => '30',
            ])
            ->assertSessionHasErrors('registration_fee_enabled');

        $this->assertFalse((bool) SystemSetting::userLimitDefaults()->registration_fee_enabled);
    }

    public function test_l_admin_non_puo_attivare_la_quota_a_importo_zero(): void
    {
        $this->actingAs($this->superAdmin)
            ->post('/admin/quote-iscrizione/impostazioni', [
                'registration_fee_enabled'    => '1',
                'registration_fee_amount'     => '0',
                'registration_fee_ky_enabled' => '1',
            ])
            ->assertSessionHasErrors('registration_fee_amount');
    }

    public function test_l_admin_cambia_importo_e_metodi(): void
    {
        $this->actingAs($this->superAdmin)
            ->post('/admin/quote-iscrizione/impostazioni', [
                'registration_fee_enabled'    => '1',
                'registration_fee_amount'     => '45,50',
                'registration_fee_ky_enabled' => '1',
            ])
            ->assertSessionHasNoErrors();

        $settings = SystemSetting::userLimitDefaults()->fresh();

        $this->assertSame(4550, $settings->registrationFeeAmount());
        $this->assertTrue($settings->registrationFeeEnabled());
        $this->assertSame(['ky'], array_keys($settings->registrationFeeMethods()));
    }

    public function test_un_metodo_spento_non_e_pagabile_neanche_forzando_la_rotta(): void
    {
        $this->makeSystemAccount(0);
        [$utente] = $this->makePrivateConQuota(0);

        // KY spento dall'admin.
        SystemSetting::userLimitDefaults()->forceFill([
            'registration_fee_ky_enabled'            => false,
            'registration_fee_bank_transfer_enabled' => true,
        ])->save();

        $this->actingAs($utente)->post('/quota-iscrizione/ky')
            ->assertRedirect(route('portal.registration-fee.show'));

        $this->assertSame(0, RegistrationFeePayment::where('status', 'completed')->count());
    }

    // ─── 7. Annullamento di una quota saldata (01/09/2026) ──────────────────

    public function test_annullando_una_quota_pagata_in_ky_il_saldo_torna_e_la_quota_torna_dovuta(): void
    {
        $sistema = $this->makeSystemAccount(0);
        [$utente, $conto] = $this->makePrivateConQuota(0);

        app(RegistrationFeeService::class)->payWithKy($utente);
        $pagamento = RegistrationFeePayment::where('user_id', $utente->id)->firstOrFail();

        app(RegistrationFeeService::class)->cancel($pagamento, $this->superAdmin, 'test');

        $this->assertSame(0, (int) $conto->fresh()->available_balance);
        $this->assertSame(0, (int) $sistema->fresh()->available_balance);
        $this->assertTrue($pagamento->fresh()->isCancelled());

        // Le altre due cose che l'eliminazione del movimento non faceva.
        $utente = $utente->fresh();
        $this->assertNull($utente->registration_fee_paid_at);
        $this->assertSame(self::QUOTA, (int) $utente->registration_fee_due_cents);
        $this->assertTrue(app(RegistrationFeeService::class)->isDueFor($utente));
        $this->assertSame(0, (int) $utente->registration_fee_ky_allowance_cents);
    }

    public function test_annullando_la_quota_il_fido_aggiuntivo_sparisce_anche_nel_massimale(): void
    {
        $this->makeSystemAccount(0);
        [$utente, $conto] = $this->makePrivateConQuota(0, fido: 5000);

        app(RegistrationFeeService::class)->payWithKy($utente);
        $this->assertSame(8000, $conto->fresh()->massimale());

        $pagamento = RegistrationFeePayment::where('user_id', $utente->id)->firstOrFail();
        app(RegistrationFeeService::class)->cancel($pagamento, $this->superAdmin);

        // Se qui restasse 8000, l'utente si terrebbe per sempre 30 KY di
        // scoperto in piu' senza avere piu' nessuna quota che li giustifica.
        $this->assertSame(5000, $conto->fresh()->massimale());
    }

    public function test_annullando_una_quota_pagata_in_euro_i_ky_tornano_al_conto_di_sistema(): void
    {
        $sistema = $this->makeSystemAccount(100000);
        [$utente, $conto] = $this->makePrivateConQuota(0);

        $pagamento = app(RegistrationFeeService::class)
            ->startPayment($utente, RegistrationFeePayment::METHOD_BANK_TRANSFER);
        app(RegistrationFeeService::class)->completeEuroPayment($pagamento);
        $this->assertSame(self::QUOTA, (int) $conto->fresh()->available_balance);

        app(RegistrationFeeService::class)->cancel($pagamento->fresh(), $this->superAdmin);

        $this->assertSame(0, (int) $conto->fresh()->available_balance);
        $this->assertSame(100000, (int) $sistema->fresh()->available_balance);
        $this->assertTrue(app(RegistrationFeeService::class)->isDueFor($utente->fresh()));
    }

    /**
     * IL CASO DI LAURA DEL 01/09. Il movimento era gia' stato cancellato a
     * mano da /admin/movimenti: i 30 KY erano gia' tornati sul conto, ma la
     * quota risultava ancora pagata. Annullare adesso deve rimettere a posto
     * la quota SENZA restituire i KY una seconda volta.
     */
    public function test_se_il_movimento_e_gia_sparito_l_annullamento_non_regala_i_ky_una_seconda_volta(): void
    {
        $this->makeSystemAccount(0);
        [$utente, $conto] = $this->makePrivateConQuota(0);

        app(RegistrationFeeService::class)->payWithKy($utente);
        $pagamento = RegistrationFeePayment::where('user_id', $utente->id)->firstOrFail();

        // Quello che faceva la vecchia cancellazione: saldi ripristinati e
        // movimento sparito, quota e fido intatti.
        $movimento = Transfer::findOrFail($pagamento->transfer_id);
        // update() diretto e non forceFill sul modello in mano: quello e'
        // stale a 0 e Eloquent non scriverebbe niente.
        Account::whereKey($conto->id)->update(['available_balance' => 0]);
        $movimento->ledgerEntries()->delete();
        $movimento->delete();

        app(RegistrationFeeService::class)->cancel($pagamento->fresh(), $this->superAdmin);

        $this->assertSame(0, (int) $conto->fresh()->available_balance);
        $this->assertSame(0, Transfer::where('kind', 'registration_fee_reversal')->count());
        $this->assertTrue(app(RegistrationFeeService::class)->isDueFor($utente->fresh()));
        $this->assertSame(0, (int) $utente->fresh()->registration_fee_ky_allowance_cents);

        // E l'audit log deve dirlo: quota annullata SENZA storno.
        $log = AuditLog::where('event', 'registration_fee.cancelled')->firstOrFail();
        $this->assertFalse($log->context['reversal_booked']);
        $this->assertNull($log->context['reversal_transfer_id']);
    }

    /**
     * L'audit log e' l'unico posto in cui, fra sei mesi, si potra' capire se
     * a un utente i KY sono tornati indietro davvero o se erano gia' tornati
     * per altra strada. Senza questa riga, "annullata" e "annullata ma i
     * soldi li aveva gia' avuti" sono indistinguibili.
     */
    public function test_l_annullamento_lascia_traccia_dello_storno_nell_audit_log(): void
    {
        $this->makeSystemAccount(0);
        [$utente] = $this->makePrivateConQuota(0);

        app(RegistrationFeeService::class)->payWithKy($utente);
        $pagamento = RegistrationFeePayment::where('user_id', $utente->id)->firstOrFail();

        app(RegistrationFeeService::class)->cancel($pagamento, $this->superAdmin, 'movimento di prova');

        $log = AuditLog::where('event', 'registration_fee.cancelled')->firstOrFail();
        $storno = Transfer::where('kind', 'registration_fee_reversal')->firstOrFail();

        $this->assertSame($this->superAdmin->id, (int) $log->actor_user_id);
        $this->assertSame($storno->id, (int) $log->context['reversal_transfer_id']);
        $this->assertTrue($log->context['reversal_booked']);
        $this->assertSame('movimento di prova', $log->context['reason']);
    }

    public function test_annullare_due_volte_storna_una_volta_sola(): void
    {
        $this->makeSystemAccount(0);
        [$utente, $conto] = $this->makePrivateConQuota(0);

        app(RegistrationFeeService::class)->payWithKy($utente);
        $pagamento = RegistrationFeePayment::where('user_id', $utente->id)->firstOrFail();

        app(RegistrationFeeService::class)->cancel($pagamento, $this->superAdmin);

        try {
            app(RegistrationFeeService::class)->cancel($pagamento->fresh(), $this->superAdmin);
            $this->fail('Il secondo annullamento doveva essere rifiutato.');
        } catch (\RuntimeException $e) {
            // atteso
        }

        $this->assertSame(0, (int) $conto->fresh()->available_balance);
        $this->assertSame(1, Transfer::where('kind', 'registration_fee_reversal')->count());
    }

    /**
     * LA STESSA TRAPPOLA DI SEMPRE (quarta volta). Il test qui sopra resta
     * verde anche rendendo casuale la idempotency_key dello storno, perche' a
     * fermare il secondo storno basta la guardia sullo stato. Qui lo stato
     * viene riportato a mano a 'completed', come lo lascerebbe un retry
     * finito male o un annullamento andato a meta', cosi' l'unica difesa che
     * resta e' la chiave.
     */
    public function test_con_lo_stato_tornato_indietro_lo_storno_non_si_ripete(): void
    {
        $this->makeSystemAccount(0);
        [$utente, $conto] = $this->makePrivateConQuota(0);

        app(RegistrationFeeService::class)->payWithKy($utente);
        $pagamento = RegistrationFeePayment::where('user_id', $utente->id)->firstOrFail();

        app(RegistrationFeeService::class)->cancel($pagamento, $this->superAdmin);

        $pagamento->fresh()->forceFill(['status' => RegistrationFeePayment::STATUS_COMPLETED])->save();

        app(RegistrationFeeService::class)->cancel($pagamento->fresh(), $this->superAdmin);

        // Se qui uscisse 3000, il conto avrebbe ricevuto due volte lo storno
        // di un addebito solo.
        $this->assertSame(0, (int) $conto->fresh()->available_balance);
        $this->assertSame(1, Transfer::where('kind', 'registration_fee_reversal')->count());
    }

    public function test_una_quota_non_saldata_non_si_puo_annullare(): void
    {
        [$utente] = $this->makePrivateConQuota(0);

        $pagamento = app(RegistrationFeeService::class)
            ->startPayment($utente, RegistrationFeePayment::METHOD_BANK_TRANSFER);

        $this->expectException(\RuntimeException::class);

        app(RegistrationFeeService::class)->cancel($pagamento, $this->superAdmin);
    }

    public function test_l_admin_annulla_la_quota_dalla_pagina_delle_quote(): void
    {
        $this->makeSystemAccount(0);
        [$utente, $conto] = $this->makePrivateConQuota(0);

        app(RegistrationFeeService::class)->payWithKy($utente);
        $pagamento = RegistrationFeePayment::where('user_id', $utente->id)->firstOrFail();

        $this->actingAs($this->superAdmin)
            ->post("/admin/quote-iscrizione/{$pagamento->id}/annulla", ['admin_notes' => 'pagamento di prova'])
            ->assertRedirect(route('admin.registration-fees.index'));

        $this->assertSame(0, (int) $conto->fresh()->available_balance);
        $this->assertTrue($pagamento->fresh()->isCancelled());
        $this->assertSame('pagamento di prova', $pagamento->fresh()->admin_notes);
    }

    public function test_un_utente_qualunque_non_puo_annullare_una_quota(): void
    {
        $this->makeSystemAccount(0);
        [$utente] = $this->makePrivateConQuota(0);

        app(RegistrationFeeService::class)->payWithKy($utente);
        $pagamento = RegistrationFeePayment::where('user_id', $utente->id)->firstOrFail();

        $this->actingAs($utente)
            ->post("/admin/quote-iscrizione/{$pagamento->id}/annulla")
            ->assertForbidden();

        $this->assertTrue($pagamento->fresh()->isCompleted());
    }

    public function test_l_utente_viene_avvisato_dell_annullamento(): void
    {
        $this->makeSystemAccount(0);
        [$utente] = $this->makePrivateConQuota(0);

        app(RegistrationFeeService::class)->payWithKy($utente);
        $pagamento = RegistrationFeePayment::where('user_id', $utente->id)->firstOrFail();

        app(RegistrationFeeService::class)->cancel($pagamento, $this->superAdmin);

        Notification::assertSentTo($utente, RegistrationFeeCancelledNotification::class);
    }

    // ─── 8. I movimenti di quota non si eliminano da Movimenti ──────────────

    public function test_il_movimento_della_quota_non_e_eliminabile_dalla_pagina_movimenti(): void
    {
        $this->makeSystemAccount(0);
        [$utente, $conto] = $this->makePrivateConQuota(0);

        app(RegistrationFeeService::class)->payWithKy($utente);
        $movimento = Transfer::where('kind', 'registration_fee')->firstOrFail();

        $this->actingAs($this->superAdmin)
            ->post("/admin/transfers/{$movimento->id}/delete")
            ->assertStatus(422);

        // Nulla di nulla: ne' il movimento, ne' il saldo, ne' la quota.
        $this->assertNotNull(Transfer::find($movimento->id));
        $this->assertSame(-self::QUOTA, (int) $conto->fresh()->available_balance);
        $this->assertNotNull($utente->fresh()->registration_fee_paid_at);
    }

    public function test_anche_il_movimento_di_accredito_in_euro_non_e_eliminabile(): void
    {
        $this->makeSystemAccount(100000);
        [$utente] = $this->makePrivateConQuota(0);

        $pagamento = app(RegistrationFeeService::class)
            ->startPayment($utente, RegistrationFeePayment::METHOD_BANK_TRANSFER);
        app(RegistrationFeeService::class)->completeEuroPayment($pagamento);

        $movimento = Transfer::where('kind', 'registration_fee_credit')->firstOrFail();

        $this->actingAs($this->superAdmin)
            ->post("/admin/transfers/{$movimento->id}/delete")
            ->assertStatus(422);

        $this->assertNotNull(Transfer::find($movimento->id));
    }

    public function test_la_cancellazione_multipla_salta_il_movimento_di_quota_e_fa_gli_altri(): void
    {
        $this->makeSystemAccount(0);
        [$utente, $conto] = $this->makePrivateConQuota(0);
        [$altro, $contoAltro] = $this->makePrivate(10000);

        app(RegistrationFeeService::class)->payWithKy($utente);
        $quota = Transfer::where('kind', 'registration_fee')->firstOrFail();

        $normale = app(TransferBookingService::class)->book([
            'initiated_by'    => $altro->id,
            'from_account_id' => $contoAltro->id,
            'to_account_id'   => $conto->id,
            'amount'          => 1000,
            'kind'            => 'portal_transfer',
            'idempotency_key' => 'test_' . Str::random(8),
        ]);

        $this->actingAs($this->superAdmin)
            ->post('/admin/transfers/bulk-delete', ['transfer_ids' => [$quota->id, $normale->id]])
            ->assertRedirect();

        $this->assertNotNull(Transfer::find($quota->id));
        $this->assertNull(Transfer::find($normale->id));
    }

    // ─── 9. L'admin chiede la quota a chi non l'ha pagata ───────────────────

    public function test_l_admin_mette_la_quota_in_carico_a_un_vecchio_iscritto(): void
    {
        $this->attivaQuota();
        [$vecchio] = $this->makePrivate(0);

        $this->assertNull($vecchio->fresh()->registration_fee_due_cents);

        $this->actingAs($this->superAdmin)
            ->post("/admin/users/{$vecchio->id}/quota-iscrizione/richiedi")
            ->assertRedirect(route('admin.users.show', $vecchio));

        $this->assertSame(self::QUOTA, (int) $vecchio->fresh()->registration_fee_due_cents);
        $this->assertTrue(app(RegistrationFeeService::class)->isDueFor($vecchio->fresh()));
        Notification::assertSentTo($vecchio, RegistrationFeeRequestedNotification::class);

        // Mettere qualcuno in debito e' un atto: deve avere un nome sopra.
        $log = AuditLog::where('event', 'registration_fee.requested_by_admin')->firstOrFail();
        $this->assertSame($this->superAdmin->id, (int) $log->actor_user_id);
        $this->assertSame($vecchio->id, (int) $log->auditable_id);
        $this->assertSame(self::QUOTA, (int) $log->context['amount']);
    }

    public function test_chi_riceve_la_quota_dall_admin_viene_bloccato_subito(): void
    {
        $this->attivaQuota();
        [$vecchio] = $this->makePrivate(0);

        $this->actingAs($vecchio)->get('/invia')->assertOk();

        app(RegistrationFeeService::class)->requestFrom($vecchio, $this->superAdmin);

        $this->actingAs($vecchio->fresh())->get('/invia')
            ->assertRedirect(route('portal.registration-fee.show'));
    }

    public function test_l_admin_non_puo_chiedere_la_quota_a_chi_ce_l_ha_gia_aperta(): void
    {
        [$utente] = $this->makePrivateConQuota(0);

        // L'admin nel frattempo ha alzato l'importo: se questa chiamata
        // passasse, all'utente cambierebbe il debito sotto i piedi.
        $this->attivaQuota(importo: 9900);

        $this->actingAs($this->superAdmin)
            ->post("/admin/users/{$utente->id}/quota-iscrizione/richiedi")
            ->assertRedirect();

        $this->assertSame(self::QUOTA, (int) $utente->fresh()->registration_fee_due_cents);
    }

    public function test_l_admin_non_puo_chiedere_la_quota_a_chi_l_ha_gia_pagata(): void
    {
        $this->makeSystemAccount(0);
        [$utente] = $this->makePrivateConQuota(0);
        app(RegistrationFeeService::class)->payWithKy($utente);

        $this->expectException(\RuntimeException::class);

        app(RegistrationFeeService::class)->requestFrom($utente->fresh(), $this->superAdmin);
    }

    public function test_l_admin_non_puo_chiedere_la_quota_dei_privati_a_un_azienda(): void
    {
        $this->attivaQuota();
        $azienda = $this->makeCircuitCompany();

        $utenteAzienda = User::create([
            'name'                => 'Titolare Azienda',
            'email'               => 'az-' . Str::random(6) . '@test.test',
            'password'            => 'secret123',
            'account_holder_type' => 'company',
            'company_id'          => $azienda->id,
            'role'                => 'company-owner',
            'is_active'           => true,
            'email_verified_at'   => now(),
        ]);

        $this->expectException(\RuntimeException::class);

        app(RegistrationFeeService::class)->requestFrom($utenteAzienda, $this->superAdmin);
    }

    public function test_senza_nessun_metodo_di_pagamento_la_quota_non_si_puo_chiedere(): void
    {
        [$vecchio] = $this->makePrivate(0);

        SystemSetting::userLimitDefaults()->forceFill([
            'registration_fee_amount_cents'          => self::QUOTA,
            'registration_fee_stripe_enabled'        => false,
            'registration_fee_paypal_enabled'        => false,
            'registration_fee_bank_transfer_enabled' => false,
            'registration_fee_ky_enabled'            => false,
        ])->save();

        $this->actingAs($this->superAdmin)
            ->post("/admin/users/{$vecchio->id}/quota-iscrizione/richiedi")
            ->assertRedirect();

        // Meglio non chiedere niente che bloccare un conto su una pagina
        // senza bottoni.
        $this->assertNull($vecchio->fresh()->registration_fee_due_cents);
    }

    public function test_un_utente_qualunque_non_puo_mettere_la_quota_in_carico_a_un_altro(): void
    {
        $this->attivaQuota();
        [$tizio] = $this->makePrivate(0);
        [$caio]  = $this->makePrivate(0);

        $this->actingAs($tizio)
            ->post("/admin/users/{$caio->id}/quota-iscrizione/richiedi")
            ->assertForbidden();

        $this->assertNull($caio->fresh()->registration_fee_due_cents);
    }

    // ─── 10. Stripe ─────────────────────────────────────────────────────────

    public function test_senza_stripe_configurato_il_bottone_carta_rimanda_indietro_con_un_messaggio(): void
    {
        [$utente] = $this->makePrivateConQuota(0);

        config(['services.stripe.secret' => null]);

        $this->actingAs($utente)->post('/quota-iscrizione/stripe')
            ->assertRedirect(route('portal.registration-fee.show'))
            ->assertSessionHas('portal_error');

        // E soprattutto: nessuna riga di pagamento lasciata li' in sospeso.
        $this->assertSame(0, RegistrationFeePayment::count());
    }

    // ─── 11. Il bonifico gia' chiesto (01/09/2026) ──────────────────────────

    /**
     * Segnalato da Laura: chi ha gia' chiesto il bonifico ritrovava i quattro
     * bottoni identici alla prima volta, senza nessun segno che la sua
     * richiesta fosse arrivata. Da li' o ne apriva un'altra (con una causale
     * diversa da quella scritta sul bonifico vero) o restava fermo a chiedersi
     * se avesse combinato qualcosa.
     */
    public function test_chi_ha_gia_chiesto_il_bonifico_vede_il_bonifico_in_corso(): void
    {
        [$utente] = $this->makePrivateConQuota(0);

        $this->actingAs($utente)->post('/quota-iscrizione/bonifico')->assertOk();

        $pagina = $this->actingAs($utente->fresh())->get('/quota-iscrizione');

        $pagina->assertOk()
            ->assertSee('Hai scelto il bonifico bancario')
            ->assertSee('Cambia metodo di pagamento')
            // La causale che ha in mano dev'essere sotto gli occhi.
            ->assertSee(RegistrationFeePayment::where('user_id', $utente->id)->firstOrFail()->bank_transfer_reference);

        // E i bottoni degli altri metodi NON ci sono: sceglierne un altro
        // adesso, senza chiudere il bonifico, vorrebbe dire pagare due volte.
        $pagina->assertDontSee('Paga con il saldo KY', false);
    }

    public function test_riaprire_il_bonifico_non_apre_una_seconda_richiesta_ne_cambia_la_causale(): void
    {
        [$utente] = $this->makePrivateConQuota(0);

        $this->actingAs($utente)->post('/quota-iscrizione/bonifico')->assertOk();
        $primo = RegistrationFeePayment::where('user_id', $utente->id)->firstOrFail();

        $this->actingAs($utente->fresh())->post('/quota-iscrizione/bonifico')->assertOk();

        $this->assertSame(1, RegistrationFeePayment::where('user_id', $utente->id)->count());
        $this->assertSame(
            $primo->bank_transfer_reference,
            RegistrationFeePayment::where('user_id', $utente->id)->firstOrFail()->bank_transfer_reference
        );
    }

    public function test_cambiando_metodo_il_bonifico_si_chiude_e_tornano_i_bottoni(): void
    {
        [$utente] = $this->makePrivateConQuota(0);

        $this->actingAs($utente)->post('/quota-iscrizione/bonifico')->assertOk();

        $this->actingAs($utente->fresh())
            ->post('/quota-iscrizione/bonifico/annulla')
            ->assertRedirect(route('portal.registration-fee.show'));

        $pagamento = RegistrationFeePayment::where('user_id', $utente->id)->firstOrFail();
        $this->assertSame(RegistrationFeePayment::STATUS_FAILED, $pagamento->status);

        $this->actingAs($utente->fresh())->get('/quota-iscrizione')
            ->assertOk()
            ->assertDontSee('Hai scelto il bonifico bancario')
            ->assertSee('Paga con il saldo KY', false);

        // E la quota resta dovuta: rinunciare al bonifico non salda niente.
        $this->assertTrue(app(RegistrationFeeService::class)->isDueFor($utente->fresh()));
    }

    public function test_un_bonifico_gia_confermato_non_blocca_piu_la_pagina(): void
    {
        $this->makeSystemAccount(100000);
        [$utente] = $this->makePrivateConQuota(0);

        $this->actingAs($utente)->post('/quota-iscrizione/bonifico')->assertOk();
        $pagamento = RegistrationFeePayment::where('user_id', $utente->id)->firstOrFail();

        app(RegistrationFeeService::class)->completeEuroPayment($pagamento, $this->superAdmin->id);

        // Quota saldata: la pagina non e' piu' raggiungibile, e non deve
        // restare appesa al bonifico.
        $this->actingAs($utente->fresh())->get('/quota-iscrizione')
            ->assertRedirect(route('portal.dashboard'));
    }

    // ─── 12. Diagnosi Stripe ────────────────────────────────────────────────

    public function test_la_diagnosi_stripe_e_riservata_al_backoffice(): void
    {
        [$utente] = $this->makePrivate(0);

        $this->actingAs($utente)->get('/admin/diagnosi-stripe')->assertForbidden();
        $this->actingAs($this->superAdmin)->get('/admin/diagnosi-stripe')->assertOk();
    }

    public function test_la_diagnosi_non_mostra_mai_una_chiave_intera(): void
    {
        config(['services.stripe.secret' => 'sk_test_CHIAVESEGRETISSIMA1234567890']);

        $this->actingAs($this->superAdmin)->get('/admin/diagnosi-stripe')
            ->assertOk()
            ->assertDontSee('sk_test_CHIAVESEGRETISSIMA1234567890')
            ->assertSee('modalità TEST');
    }

    // ─── 13. La porta dell'agente (01/09/2026) ──────────────────────────────
    //
    // Decisione di Laura: chi entra dal portale di un agente paga i 480 del
    // codice, non anche i 30 dei privati — ma se agente non lo diventa, i 30
    // tornano dovuti. Altrimenti quella porta e' il modo di entrare nel
    // circuito senza pagare niente, e non e' una possibilita' teorica: e'
    // proprio la porta da cui entra quasi tutta la gente nuova.

    public function test_chi_e_registrato_dal_portale_di_un_agente_non_deve_subito_i_trenta(): void
    {
        $this->attivaQuota();
        $this->attivaQuotaCodiceAgente();

        $nuovo = $this->registraDalPortaleAgente();

        // Zero e non NULL: la differenza e' tutto. NULL vorrebbe dire "non la
        // dovra' mai", come i milletrecento iscritti da prima.
        $this->assertSame(0, (int) $nuovo->registration_fee_due_cents);
        $this->assertFalse(app(RegistrationFeeService::class)->isDueFor($nuovo));

        // La quota che deve davvero e' quella del codice agente.
        $this->assertSame(48000, (int) $nuovo->agent_code_fee_due_cents);
    }

    public function test_rinunciando_al_codice_agente_i_trenta_diventano_dovuti(): void
    {
        $this->attivaQuota();
        $this->attivaQuotaCodiceAgente();

        $nuovo = $this->registraDalPortaleAgente();

        app(\App\Services\AgentCodeFeeService::class)->giveUp($nuovo);

        $nuovo->refresh();

        $this->assertSame(self::QUOTA, (int) $nuovo->registration_fee_due_cents);
        $this->assertTrue(app(RegistrationFeeService::class)->isDueFor($nuovo));
        $this->assertNull($nuovo->agent_code_fee_due_cents);

        Notification::assertSentTo($nuovo, RegistrationFeeRequestedNotification::class);
    }

    public function test_il_rifiuto_dell_admin_accende_i_trenta_e_toglie_la_quota_del_codice(): void
    {
        $this->attivaQuota();
        $this->attivaQuotaCodiceAgente();

        $nuovo = $this->registraDalPortaleAgente();

        $this->actingAsWithSession($this->superAdmin)
            ->post(route('admin.mlm.requests.reject', $nuovo), ['reason' => 'Documenti non conformi.'])
            ->assertRedirect();

        $nuovo->refresh();

        $this->assertSame(self::QUOTA, (int) $nuovo->registration_fee_due_cents);
        $this->assertNull($nuovo->agent_code_fee_due_cents, 'Un codice agente rifiutato non si paga.');
        $this->assertTrue(app(RegistrationFeeService::class)->isDueFor($nuovo));
    }

    public function test_un_vecchio_iscritto_rifiutato_come_agente_non_si_ritrova_una_quota(): void
    {
        [$vecchio] = $this->makePrivate(0);
        $this->attivaQuota();
        $this->attivaQuotaCodiceAgente();

        // Non e' mai passato dalla porta dell'agente: la sua colonna e' NULL,
        // e NULL deve restare NULL qualunque cosa succeda al suo percorso MLM.
        $vecchio->forceFill([
            'mlm_agent_request_status' => 'approved',
            'agent_code_fee_due_cents' => 48000,
        ])->save();

        $this->actingAsWithSession($this->superAdmin)
            ->post(route('admin.mlm.requests.reject', $vecchio), ['reason' => 'Ripensamento.'])
            ->assertRedirect();

        $this->assertNull($vecchio->fresh()->registration_fee_due_cents);
        $this->assertFalse(app(RegistrationFeeService::class)->isDueFor($vecchio->fresh()));
    }

    public function test_con_la_quota_del_codice_spenta_chi_entra_dal_portale_deve_i_trenta_subito(): void
    {
        $this->attivaQuota();
        $this->attivaQuotaCodiceAgente(attiva: false);

        $nuovo = $this->registraDalPortaleAgente();

        // Nessuna quota lo copre: allora la paga come chiunque altro,
        // altrimenti questa sarebbe la porta di servizio del circuito.
        $this->assertSame(self::QUOTA, (int) $nuovo->registration_fee_due_cents);
        $this->assertTrue(app(RegistrationFeeService::class)->isDueFor($nuovo));
    }

    public function test_segnare_la_quota_alla_registrazione_non_sveglia_una_quota_sospesa(): void
    {
        [$utente] = $this->makePrivate(0);
        $this->attivaQuota();
        $utente->forceFill(['registration_fee_due_cents' => 0])->save();

        // Difesa in profondita': oggi nessuno chiama markDueOnRegistration()
        // su un utente gia' segnato, ma il giorno che nascesse una quarta
        // porta di registrazione, riscrivere quella colonna accenderebbe una
        // quota che qualcuno ha deciso di sospendere.
        app(RegistrationFeeService::class)->markDueOnRegistration($utente->fresh());

        $this->assertSame(0, (int) $utente->fresh()->registration_fee_due_cents);
    }

    public function test_una_quota_gia_pagata_non_si_riaccende_lasciando_il_percorso_agente(): void
    {
        [$utente] = $this->makePrivate(0);
        $this->attivaQuota();

        // Stato che oggi dal sito non si raggiunge — una quota sospesa non la
        // si puo' pagare — ma che una riparazione a mano nel database puo'
        // benissimo creare. Se succede, riaccendere la quota vorrebbe dire
        // farla pagare due volte alla stessa persona.
        $utente->forceFill([
            'registration_fee_due_cents' => 0,
            'registration_fee_paid_at'   => now(),
        ])->save();

        app(RegistrationFeeService::class)->resumeAfterAgentPath($utente->fresh());

        $this->assertSame(0, (int) $utente->fresh()->registration_fee_due_cents);
        $this->assertNotNull($utente->fresh()->registration_fee_paid_at);
    }

    public function test_la_sospensione_non_cancella_una_quota_gia_dovuta(): void
    {
        [$utente] = $this->makePrivateConQuota(0);

        app(RegistrationFeeService::class)->suspendForAgentPath($utente);

        $this->assertSame(self::QUOTA, (int) $utente->fresh()->registration_fee_due_cents);
    }

    // ─── 14. Ripescaggio di un incasso in euro (01/09/2026) ─────────────────

    public function test_un_incasso_stripe_finito_failed_si_ripesca_e_accredita(): void
    {
        $this->makeSystemAccount(1000000);
        [$utente, $conto] = $this->makePrivateConQuota(0);
        $pagamento = $this->pagamentoFallito($utente, $conto);

        $this->fingiStripe(pagata: true);

        $this->actingAsWithSession($this->superAdmin)
            ->post(route('admin.registration-fees.retry-credit', $pagamento))
            ->assertRedirect();

        $this->assertTrue($pagamento->fresh()->isCompleted());
        $this->assertSame(self::QUOTA, (int) $conto->fresh()->available_balance);
        $this->assertNotNull($utente->fresh()->registration_fee_paid_at);
    }

    public function test_senza_la_conferma_di_stripe_il_ripescaggio_non_accredita_niente(): void
    {
        $this->makeSystemAccount(1000000);
        [$utente, $conto] = $this->makePrivateConQuota(0);
        $pagamento = $this->pagamentoFallito($utente, $conto);

        $this->fingiStripe(pagata: false);

        $this->actingAsWithSession($this->superAdmin)
            ->post(route('admin.registration-fees.retry-credit', $pagamento))
            ->assertRedirect();

        $this->assertFalse($pagamento->fresh()->isCompleted());
        $this->assertSame(0, (int) $conto->fresh()->available_balance);
        $this->assertNull($utente->fresh()->registration_fee_paid_at);
    }

    public function test_il_bonifico_rifiutato_si_accredita_solo_con_la_conferma_esplicita(): void
    {
        $this->makeSystemAccount(1000000);
        [$utente, $conto] = $this->makePrivateConQuota(0);
        $pagamento = $this->pagamentoFallito($utente, $conto, RegistrationFeePayment::METHOD_BANK_TRANSFER);

        // Senza la conferma non succede niente: non c'e' nessuna banca da
        // interrogare, quindi la prova e' l'admin e deve essere esplicita.
        $this->actingAsWithSession($this->superAdmin)
            ->post(route('admin.registration-fees.retry-credit', $pagamento))
            ->assertRedirect();

        $this->assertFalse($pagamento->fresh()->isCompleted());
        $this->assertSame(0, (int) $conto->fresh()->available_balance);

        $this->actingAsWithSession($this->superAdmin)
            ->post(route('admin.registration-fees.retry-credit', $pagamento), ['bonifico_ricevuto' => '1'])
            ->assertRedirect();

        $this->assertTrue($pagamento->fresh()->isCompleted());
        $this->assertSame(self::QUOTA, (int) $conto->fresh()->available_balance);
    }

    public function test_una_quota_pagata_in_ky_non_si_ripesca(): void
    {
        $this->makeSystemAccount(1000000);
        [$utente, $conto] = $this->makePrivateConQuota(0);
        $pagamento = $this->pagamentoFallito($utente, $conto, RegistrationFeePayment::METHOD_KY);

        $this->actingAsWithSession($this->superAdmin)
            ->post(route('admin.registration-fees.retry-credit', $pagamento))
            ->assertRedirect();

        $this->assertFalse($pagamento->fresh()->isCompleted());
        $this->assertSame(0, (int) $conto->fresh()->available_balance);
    }

    public function test_non_si_ripesca_una_quota_gia_saldata(): void
    {
        $this->makeSystemAccount(1000000);
        [$utente, $conto] = $this->makePrivateConQuota(0);
        $pagamento = $this->pagamentoFallito($utente, $conto);

        app(RegistrationFeeService::class)->completeEuroPayment($pagamento);

        // Il ripescaggio si ferma prima di rimettere in moto l'accredito: chi
        // preme il bottone due volte deve sentirselo dire, non ritrovarsi a
        // guardare un saldo per capire se e' successo qualcosa.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('già saldata');

        app(RegistrationFeeService::class)->retryEuroCredit($pagamento->fresh(), $this->superAdmin);
    }

    public function test_il_servizio_rifiuta_di_ripescare_un_pagamento_in_ky(): void
    {
        [$utente, $conto] = $this->makePrivateConQuota(0);
        $pagamento = $this->pagamentoFallito($utente, $conto, RegistrationFeePayment::METHOD_KY);

        // Il controllo che conta sta nel SERVIZIO e non nella rotta: in KY non
        // c'e' nessun euro incassato da recuperare, e accreditare KY a chi ha
        // pagato in KY vorrebbe dire regalarglieli.
        $this->expectException(\RuntimeException::class);

        app(RegistrationFeeService::class)->retryEuroCredit($pagamento, $this->superAdmin);
    }

    public function test_un_utente_qualunque_non_puo_ripescare_un_pagamento(): void
    {
        [$utente, $conto] = $this->makePrivateConQuota(0);
        $pagamento = $this->pagamentoFallito($utente, $conto);

        $this->actingAsWithSession($utente)
            ->post(route('admin.registration-fees.retry-credit', $pagamento))
            ->assertForbidden();

        $this->assertFalse($pagamento->fresh()->isCompleted());
    }

    public function test_la_pagina_di_esito_accredita_anche_una_riga_gia_finita_failed(): void
    {
        $this->makeSystemAccount(1000000);
        [$utente, $conto] = $this->makePrivateConQuota(0);
        $pagamento = $this->pagamentoFallito($utente, $conto);

        $this->fingiStripe(pagata: true);

        $this->actingAsWithSession($utente)
            ->get(route('portal.registration-fee.success', ['payment' => $pagamento->uuid]))
            ->assertOk();

        $this->assertTrue($pagamento->fresh()->isCompleted());
        $this->assertSame(self::QUOTA, (int) $conto->fresh()->available_balance);
    }

    // ─── 15. La stessa quota incassata due volte ────────────────────────────

    public function test_la_seconda_quota_incassata_lascia_traccia_nell_audit_log(): void
    {
        $this->makeSystemAccount(1000000);
        [$utente, $conto] = $this->makePrivateConQuota(0);

        $primo   = $this->pagamentoFallito($utente, $conto);
        $secondo = $this->pagamentoFallito($utente, $conto);

        $fees = app(RegistrationFeeService::class);
        $fees->completeEuroPayment($primo);
        $fees->completeEuroPayment($secondo);

        $this->assertTrue($secondo->fresh()->isCompleted());

        $tracce = AuditLog::where('event', 'registration_fee.paid_in_eur')
            ->get()
            ->map(fn ($l) => (bool) ($l->context['quota_gia_saldata'] ?? false))
            ->all();

        $this->assertSame([false, true], $tracce, 'Il secondo incasso deve risultare come tale.');
    }

    // ─── 16. Tentativi abbandonati ──────────────────────────────────────────

    public function test_i_tentativi_abbandonati_si_chiudono_dopo_ventiquattro_ore(): void
    {
        [$utente, $conto] = $this->makePrivateConQuota(0);

        $vecchio = $this->pagamentoInAttesa($utente, $conto, giorniFa: 2);
        $recente = $this->pagamentoInAttesa($utente, $conto, giorniFa: 0);

        $this->artisan('quote:scadi-tentativi')->assertSuccessful();

        $this->assertSame(RegistrationFeePayment::STATUS_FAILED, $vecchio->fresh()->status);
        $this->assertSame(RegistrationFeePayment::STATUS_PENDING, $recente->fresh()->status);
    }

    public function test_la_scadenza_non_tocca_mai_i_bonifici_in_attesa(): void
    {
        [$utente, $conto] = $this->makePrivateConQuota(0);

        $bonifico = RegistrationFeePayment::create([
            'user_id'          => $utente->id,
            'account_id'       => $conto->id,
            'amount_eur_cents' => self::QUOTA,
            'ky_amount'        => self::QUOTA,
            'status'           => RegistrationFeePayment::STATUS_PENDING_BANK_TRANSFER,
            'payment_method'   => RegistrationFeePayment::METHOD_BANK_TRANSFER,
        ]);
        $bonifico->forceFill(['created_at' => now()->subMonth()])->save();

        $this->artisan('quote:scadi-tentativi')->assertSuccessful();

        $this->assertSame(
            RegistrationFeePayment::STATUS_PENDING_BANK_TRANSFER,
            $bonifico->fresh()->status,
            'Chi ha in mano una causale puo\' andare in banca la settimana dopo.'
        );
    }

    public function test_un_tentativo_scaduto_si_puo_ancora_accreditare(): void
    {
        $this->makeSystemAccount(1000000);
        [$utente, $conto] = $this->makePrivateConQuota(0);

        $tentativo = $this->pagamentoInAttesa($utente, $conto, giorniFa: 2);

        $this->artisan('quote:scadi-tentativi')->assertSuccessful();
        $this->assertSame(RegistrationFeePayment::STATUS_FAILED, $tentativo->fresh()->status);

        // Ed e' proprio questo che rende la scadenza innocua: se il pagamento
        // arriva lo stesso, la riga chiusa si riapre.
        $this->fingiStripe(pagata: true);

        $this->actingAsWithSession($utente)
            ->get(route('portal.registration-fee.success', ['payment' => $tentativo->uuid]))
            ->assertOk();

        $this->assertTrue($tentativo->fresh()->isCompleted());
    }

    // ─── 17. Solleciti e ricevuta ───────────────────────────────────────────

    public function test_il_sollecito_parte_una_volta_sola(): void
    {
        [$utente] = $this->makePrivateConQuota(0);
        $utente->forceFill(['created_at' => now()->subDays(10)])->save();

        $this->artisan('quote:solleciti-iscrizione')->assertSuccessful();
        $this->artisan('quote:solleciti-iscrizione')->assertSuccessful();

        Notification::assertSentToTimes($utente, \App\Notifications\RegistrationFeeReminderNotification::class, 1);
    }

    public function test_non_si_sollecita_chi_ha_la_quota_sospesa_o_gia_saldata(): void
    {
        [$sospeso] = $this->makePrivate(0);
        $sospeso->forceFill([
            'registration_fee_due_cents' => 0,
            'created_at'                 => now()->subDays(10),
        ])->save();

        [$saldato] = $this->makePrivateConQuota(0);
        $saldato->forceFill([
            'registration_fee_paid_at' => now(),
            'created_at'               => now()->subDays(10),
        ])->save();

        $this->artisan('quote:solleciti-iscrizione')->assertSuccessful();

        Notification::assertNothingSentTo($sospeso);
        Notification::assertNothingSentTo($saldato);
    }

    public function test_chi_paga_in_ky_riceve_la_ricevuta(): void
    {
        $this->makeSystemAccount(1000000);
        [$utente] = $this->makePrivateConQuota(10000, fido: 5000);

        app(RegistrationFeeService::class)->payWithKy($utente);

        Notification::assertSentTo($utente, \App\Notifications\RegistrationFeePaidNotification::class);
    }

    public function test_chi_paga_in_euro_riceve_la_ricevuta_una_volta_sola(): void
    {
        $this->makeSystemAccount(1000000);
        [$utente, $conto] = $this->makePrivateConQuota(0);
        $pagamento = $this->pagamentoFallito($utente, $conto);

        $fees = app(RegistrationFeeService::class);
        $fees->completeEuroPayment($pagamento);
        $fees->completeEuroPayment($pagamento->fresh());

        Notification::assertSentToTimes($utente, \App\Notifications\RegistrationFeePaidNotification::class, 1);
    }

    public function test_la_ricevuta_non_parte_due_volte_quando_due_richieste_si_accavallano(): void
    {
        $this->makeSystemAccount(1000000);
        [$utente, $conto] = $this->makePrivateConQuota(0);
        $pagamento = $this->pagamentoFallito($utente, $conto);

        // La corsa vera: webhook Stripe e pagina di successo leggono la stessa
        // riga nello stesso istante. Questa e' la copia caricata PRIMA che
        // l'altra richiesta accreditasse — per lei il pagamento e' ancora da
        // fare, e la guardia in cima al metodo non scatta. A fermarla resta
        // solo il controllo dentro il lock, che e' anche quello che decide se
        // la ricevuta va spedita.
        $copiaVecchia = RegistrationFeePayment::find($pagamento->id);

        $fees = app(RegistrationFeeService::class);
        $fees->completeEuroPayment($pagamento);
        $fees->completeEuroPayment($copiaVecchia);

        Notification::assertSentToTimes($utente, \App\Notifications\RegistrationFeePaidNotification::class, 1);
        $this->assertSame(self::QUOTA, (int) $conto->fresh()->available_balance);
    }

    // ─── 18. Il service worker ──────────────────────────────────────────────

    public function test_le_pagine_delle_quote_non_finiscono_nella_cache_del_service_worker(): void
    {
        // Una pagina di pagamento servita da una cache vecchia porta dentro un
        // CSRF token morto, e il risultato e' un 419 "sessione scaduta" a chi
        // non ha nessuna colpa. E' lo stesso motivo per cui /ricarica e' li'
        // dentro da mesi, con il suo commento accanto.
        $sw = file_get_contents(public_path('sw.js'));

        $this->assertStringContainsString('/\\/quota-/', $sw);
        $this->assertStringContainsString('/\\/ricarica/', $sw);
    }

    // ─── 19. Chi diventa agente paga UNA quota sola (02/09/2026) ────────────
    //
    // Decisione di Laura, e ribalta quella del 31/08: l'agente paga i 480 del
    // codice e nient'altro per l'apertura del conto. Prima chi si registrava
    // dal form pubblico e POI chiedeva il codice si ritrovava addosso tutte e
    // due le quote — ed e' il caso normale, non un angolo: e' come entra chi
    // scopre il circuito da solo e solo dopo decide di fare l'agente.
    //
    // La riga che conta e' in MlmAgentRequestController::quoteAllApprovazione(),
    // e questi test passano dalle ROTTE dell'admin: il buco del 01/09 era
    // proprio un percorso che segnava una quota e si dimenticava l'altra, e un
    // test scritto sul servizio sarebbe stato verde lo stesso.

    public function test_l_approvazione_della_richiesta_sospende_i_trenta(): void
    {
        [$aspirante] = $this->makePrivateConQuota(0);
        $this->attivaQuotaCodiceAgente();
        $this->chiediDiDiventareAgente($aspirante);

        $this->actingAsWithSession($this->superAdmin)
            ->post(route('admin.mlm.requests.approve', $aspirante))
            ->assertRedirect();

        $aspirante->refresh();

        // Zero e non NULL: se poi agente non lo diventa, i 30 si riaccendono.
        $this->assertSame(0, (int) $aspirante->registration_fee_due_cents);
        $this->assertFalse(app(RegistrationFeeService::class)->isDueFor($aspirante));
        $this->assertSame(48000, (int) $aspirante->agent_code_fee_due_cents);
    }

    public function test_la_promozione_diretta_dell_admin_sospende_i_trenta(): void
    {
        [$utente] = $this->makePrivateConQuota(0);
        $this->attivaQuotaCodiceAgente();

        // Nessuna richiesta dell'interessato: e' l'admin che lo mette sul
        // percorso. E' una porta diversa dalla precedente e si dimentica le
        // cose per conto suo.
        $this->actingAsWithSession($this->superAdmin)
            ->post(route('admin.mlm.requests.promote', $utente))
            ->assertRedirect();

        $this->assertSame(0, (int) $utente->fresh()->registration_fee_due_cents);
        $this->assertSame(48000, (int) $utente->fresh()->agent_code_fee_due_cents);
    }

    public function test_la_sospensione_all_approvazione_lascia_scritto_quanto_doveva(): void
    {
        [$aspirante] = $this->makePrivateConQuota(0);
        $this->attivaQuotaCodiceAgente();
        $this->chiediDiDiventareAgente($aspirante);

        $this->actingAsWithSession($this->superAdmin)
            ->post(route('admin.mlm.requests.approve', $aspirante))
            ->assertRedirect();

        // La colonna adesso dice zero: l'audit log e' l'unico posto in cui
        // resta scritto che il suo scatto era 30.
        $log = AuditLog::where('event', 'registration_fee.suspended_on_agent_approval')
            ->where('auditable_id', $aspirante->id)
            ->first();

        $this->assertNotNull($log, 'La sospensione dei 30 deve lasciare traccia.');
        $this->assertSame(self::QUOTA, (int) ($log->context['amount'] ?? 0));
    }

    public function test_con_la_quota_del_codice_spenta_l_approvazione_lascia_i_trenta_dovuti(): void
    {
        [$aspirante] = $this->makePrivateConQuota(0);
        $this->attivaQuotaCodiceAgente(attiva: false);
        $this->chiediDiDiventareAgente($aspirante);

        $this->actingAsWithSession($this->superAdmin)
            ->post(route('admin.mlm.requests.approve', $aspirante))
            ->assertRedirect();

        $aspirante->refresh();

        // Nessun 480 lo copre: allora i 30 restano suoi, altrimenti diventare
        // agente sarebbe il modo di entrare nel circuito senza pagare niente.
        $this->assertSame(self::QUOTA, (int) $aspirante->registration_fee_due_cents);
        $this->assertTrue(app(RegistrationFeeService::class)->isDueFor($aspirante));
        $this->assertNull($aspirante->agent_code_fee_due_cents);
    }

    public function test_i_trenta_gia_pagati_non_si_toccano_e_l_admin_lo_legge_subito(): void
    {
        [$aspirante] = $this->makePrivateConQuota(0);
        $aspirante->forceFill(['registration_fee_paid_at' => now()])->save();
        $this->attivaQuotaCodiceAgente();
        $this->chiediDiDiventareAgente($aspirante);

        $this->actingAsWithSession($this->superAdmin)
            ->post(route('admin.mlm.requests.approve', $aspirante))
            ->assertRedirect()
            // Il denaro non si muove per effetto collaterale di un click su
            // «Approva» — ma chi approva lo deve sapere adesso.
            ->assertSessionHas('portal_success', fn (string $m): bool => str_contains($m, 'ATTENZIONE'));

        $aspirante->refresh();

        $this->assertSame(self::QUOTA, (int) $aspirante->registration_fee_due_cents);
        $this->assertNotNull($aspirante->registration_fee_paid_at);
    }

    public function test_un_vecchio_iscritto_promosso_agente_non_si_ritrova_una_quota(): void
    {
        [$vecchio] = $this->makePrivate(0);
        $this->attivaQuota();
        $this->attivaQuotaCodiceAgente();

        // NULL = non la deve e non la dovra' mai (i milletrecento iscritti da
        // prima). All'approvazione NULL deve restare NULL: scriverci zero
        // vorrebbe dire che al primo rifiuto si ritrova un debito mai avuto.
        $this->actingAsWithSession($this->superAdmin)
            ->post(route('admin.mlm.requests.promote', $vecchio))
            ->assertRedirect();

        $this->assertNull($vecchio->fresh()->registration_fee_due_cents);

        $this->actingAsWithSession($this->superAdmin)
            ->post(route('admin.mlm.requests.reject', $vecchio), ['reason' => 'Ripensamento.'])
            ->assertRedirect();

        $this->assertNull($vecchio->fresh()->registration_fee_due_cents);
    }

    public function test_i_trenta_sospesi_all_approvazione_tornano_dovuti_col_rifiuto(): void
    {
        [$aspirante] = $this->makePrivateConQuota(0);
        $this->attivaQuotaCodiceAgente();
        $this->chiediDiDiventareAgente($aspirante);

        $this->actingAsWithSession($this->superAdmin)
            ->post(route('admin.mlm.requests.approve', $aspirante))
            ->assertRedirect();

        $this->actingAsWithSession($this->superAdmin)
            ->post(route('admin.mlm.requests.reject', $aspirante), ['reason' => 'Documenti non conformi.'])
            ->assertRedirect();

        $aspirante->refresh();

        $this->assertSame(self::QUOTA, (int) $aspirante->registration_fee_due_cents);
        $this->assertTrue(app(RegistrationFeeService::class)->isDueFor($aspirante));
        $this->assertNull($aspirante->agent_code_fee_due_cents);

        Notification::assertSentTo($aspirante, RegistrationFeeRequestedNotification::class);
    }

    public function test_i_trenta_sospesi_all_approvazione_tornano_dovuti_con_la_rinuncia(): void
    {
        [$aspirante] = $this->makePrivateConQuota(0);
        $this->attivaQuotaCodiceAgente();
        $this->chiediDiDiventareAgente($aspirante);

        $this->actingAsWithSession($this->superAdmin)
            ->post(route('admin.mlm.requests.approve', $aspirante))
            ->assertRedirect();

        app(\App\Services\AgentCodeFeeService::class)->giveUp($aspirante->fresh());

        $this->assertSame(self::QUOTA, (int) $aspirante->fresh()->registration_fee_due_cents);
        $this->assertTrue(app(RegistrationFeeService::class)->isDueFor($aspirante->fresh()));
    }

    // ─── 19-bis. Chi i 480 li ha PAGATI non deve anche i 30 (02/09/2026) ───

    /**
     * IL BUCO CHIUSO IL 02/09/2026. La quota del codice agente e' un ingresso
     * nel circuito, e costa sedici volte quello dei privati. Chi l'ha saldata
     * e poi esce dal percorso ha gia' pagato per entrare: fino a oggi i 30 gli
     * si riaccendevano lo stesso, e riceveva un «devi 30 €» senza nessuna
     * spiegazione dopo averne versati 480 per un codice che non avra' mai.
     *
     * La colonna va a NULL e non resta a zero, ed e' la parte che conta:
     * isSuspendedFor() vuol dire «nel circuito non ha ancora pagato NESSUN
     * ingresso» ed e' cio' che il middleware legge per decidere quanto
     * stringere. Lasciarla a zero direbbe il falso proprio su chi ha pagato di
     * piu'.
     */
    public function test_chi_ha_pagato_i_quattrocentottanta_non_si_ritrova_a_dovere_i_trenta(): void
    {
        [$aspirante] = $this->makePrivateConQuota(0);
        $this->attivaQuotaCodiceAgente();
        $this->chiediDiDiventareAgente($aspirante);

        $this->actingAsWithSession($this->superAdmin)
            ->post(route('admin.mlm.requests.approve', $aspirante))
            ->assertRedirect();

        // I 30 sono sospesi, i 480 dovuti: lo stato normale di chi sta per
        // pagare il codice.
        $this->assertSame(0, (int) $aspirante->fresh()->registration_fee_due_cents);

        $this->pagaLaQuotaCodiceInEuro($aspirante->fresh());

        app(\App\Services\AgentCodeFeeService::class)->giveUp($aspirante->fresh());

        $dopo = $aspirante->fresh();

        $this->assertNull($dopo->registration_fee_due_cents);
        $this->assertFalse(app(RegistrationFeeService::class)->isDueFor($dopo));
        $this->assertFalse(app(RegistrationFeeService::class)->isSuspendedFor($dopo));

        // E non gli si chiede niente: la notifica dei 30 non parte.
        Notification::assertNotSentTo($dopo, RegistrationFeeRequestedNotification::class);

        // Il conto e' operativo davvero, non solo sulla carta.
        $this->actingAs($dopo)->get('/invia')->assertOk();
    }

    public function test_anche_il_rifiuto_dell_admin_non_riaccende_i_trenta_a_chi_ha_pagato_i_quattrocentottanta(): void
    {
        [$aspirante] = $this->makePrivateConQuota(0);
        $this->attivaQuotaCodiceAgente();
        $this->chiediDiDiventareAgente($aspirante);

        $this->actingAsWithSession($this->superAdmin)
            ->post(route('admin.mlm.requests.approve', $aspirante))
            ->assertRedirect();

        $this->pagaLaQuotaCodiceInEuro($aspirante->fresh());

        $this->actingAsWithSession($this->superAdmin)
            ->post(route('admin.mlm.requests.reject', $aspirante), ['reason' => 'Documenti non conformi.'])
            ->assertRedirect();

        $this->assertNull($aspirante->fresh()->registration_fee_due_cents);
        Notification::assertNotSentTo($aspirante->fresh(), RegistrationFeeRequestedNotification::class);
    }

    /**
     * L'altra meta', e serve che resti verde: chi il codice NON lo ha pagato
     * i 30 li deve eccome. Senza questo, il portale dell'agente tornerebbe a
     * essere il modo di entrare nel circuito senza pagare niente — ci si fa
     * registrare, si rinuncia, e non si deve piu' nulla.
     */
    public function test_chi_i_quattrocentottanta_non_li_ha_pagati_i_trenta_li_deve_ancora(): void
    {
        [$aspirante] = $this->makePrivateConQuota(0);
        $this->attivaQuotaCodiceAgente();
        $this->chiediDiDiventareAgente($aspirante);

        $this->actingAsWithSession($this->superAdmin)
            ->post(route('admin.mlm.requests.approve', $aspirante))
            ->assertRedirect();

        app(\App\Services\AgentCodeFeeService::class)->giveUp($aspirante->fresh());

        $this->assertSame(self::QUOTA, (int) $aspirante->fresh()->registration_fee_due_cents);
        Notification::assertSentTo($aspirante->fresh(), RegistrationFeeRequestedNotification::class);
    }

    /**
     * L'ESONERATO NON HA PAGATO NIENTE, e la differenza va tenuta ferma: lo
     * zero della quota agente vuol dire «condonata», non «saldata». Chi e'
     * stato esonerato e poi rinuncia torna un privato come tutti e i 30 li
     * deve. Il rischio era di scrivere la guardia su isOnFeePath() o sulla
     * colonna dell'importo invece che su agent_code_fee_paid_at.
     */
    public function test_l_esonerato_che_rinuncia_i_trenta_li_deve(): void
    {
        [$aspirante] = $this->makePrivateConQuota(0);
        $this->attivaQuotaCodiceAgente();
        $this->chiediDiDiventareAgente($aspirante);

        $this->actingAsWithSession($this->superAdmin)
            ->post(route('admin.mlm.requests.approve', $aspirante))
            ->assertRedirect();

        app(\App\Services\AgentCodeFeeService::class)
            ->waive($aspirante->fresh(), $this->superAdmin, 'Accordo commerciale.');

        app(\App\Services\AgentCodeFeeService::class)->giveUp($aspirante->fresh());

        $this->assertSame(self::QUOTA, (int) $aspirante->fresh()->registration_fee_due_cents);
    }

    /**
     * LA DOMANDA E' «HA PAGATO?», NON «E' PASSATO DI QUI?».
     *
     * Test nato da una mutazione sopravvissuta: scrivendo la guardia su
     * `agent_code_fee_due_cents !== null` invece che su
     * `agent_code_fee_paid_at` la suite restava tutta verde, perche' oggi
     * rinuncia e rifiuto azzerano quella colonna PRIMA di arrivare qui e i
     * due controlli non si distinguono da nessun percorso del sito. Una
     * guardia che nessun test sa distinguere e' una guardia non provata: al
     * primo cambiamento in giveUp() la versione sbagliata condonerebbe i 30 a
     * chi non ha versato un euro.
     *
     * Lo stato qui sotto si costruisce a mano apposta — dal sito non si
     * raggiunge — ed e' l'unico che separa le due letture.
     */
    public function test_la_quota_sospesa_si_riaccende_a_chi_i_quattrocentottanta_li_deve_ancora(): void
    {
        [$utente] = $this->makePrivateConQuota(0);
        $this->attivaQuotaCodiceAgente();

        $utente->forceFill([
            'registration_fee_due_cents' => 0,
            'agent_code_fee_due_cents'   => 48000,
            'agent_code_fee_paid_at'     => null,
        ])->save();

        $riacceso = app(RegistrationFeeService::class)->resumeAfterAgentPath($utente->fresh());

        $this->assertSame(self::QUOTA, $riacceso);
        $this->assertSame(self::QUOTA, (int) $utente->fresh()->registration_fee_due_cents);
    }

    public function test_la_sospensione_all_approvazione_non_riguarda_le_aziende(): void
    {
        [$utente] = $this->makePrivateConQuota(0);

        // Stato che dal sito non si raggiunge (la quota e' solo dei privati),
        // ma una riparazione a mano nel database lo puo' creare. Le aziende
        // hanno i piani di abbonamento e questa quota non le tocca, nemmeno
        // per sospenderla.
        $utente->forceFill(['account_holder_type' => 'company'])->save();

        $sospesi = app(RegistrationFeeService::class)->suspendOnAgentApproval($utente->fresh());

        $this->assertSame(0, $sospesi);
        $this->assertSame(self::QUOTA, (int) $utente->fresh()->registration_fee_due_cents);
    }

    // ─── 20. Il banner rosso dice quale quota sta bloccando (02/09/2026) ────

    public function test_sulla_pagina_dei_trenta_non_compare_il_banner_dei_quattrocentottanta(): void
    {
        [$utente] = $this->makePrivateConQuota(0);
        $this->attivaQuotaCodiceAgente();

        // Le deve tutte e due: succede quando l'admin mette in carico i 30 a
        // chi e' gia' sul percorso agente (una scelta esplicita, con un nome
        // sopra). Prima del 02/09 questa pagina mostrava in rosso il testo
        // dell'ALTRA quota sopra l'importo di questa.
        $utente->forceFill([
            'mlm_agent_request_status' => 'approved',
            'agent_code_fee_due_cents' => 48000,
        ])->save();

        $this->actingAsWithSession($utente)
            ->get(route('portal.registration-fee.show'))
            ->assertOk()
            ->assertDontSee('Quota per il codice agente da saldare', escape: false);
    }

    public function test_sulla_pagina_dei_quattrocentottanta_il_banner_dice_che_prima_vengono_i_trenta(): void
    {
        [$utente] = $this->makePrivateConQuota(0);
        $this->attivaQuotaCodiceAgente();

        $utente->forceFill([
            'mlm_agent_request_status' => 'approved',
            'agent_code_fee_due_cents' => 48000,
        ])->save();

        // E' l'ordine in cui il middleware le fa pagare: il banner deve
        // indicare la quota che sta bloccando davvero, non l'altra.
        $this->actingAsWithSession($utente)
            ->get(route('portal.mlm.agent-code-fee.show'))
            ->assertOk()
            ->assertSee('Quota di iscrizione da saldare', escape: false);
    }

    // ─── 21. Quanto stringe la quota del codice agente (02/09/2026) ────────
    //
    // Decisione di Laura: la quota del codice ferma il conto SOLO a chi nel
    // circuito non e' ancora entrato pagando — quota di iscrizione sospesa,
    // cioe' i 480 sono il suo ingresso. Chi i 30 li aveva gia' pagati, o non
    // li ha mai dovuti, continua a usare il conto: gli manca solo la firma.
    // Altrimenti a un privato gia' operativo chiedere di diventare agente
    // costerebbe il congelamento del conto, che e' il contrario del senso.

    public function test_chi_non_ha_mai_pagato_l_ingresso_ha_il_conto_fermo_finche_non_salda_il_codice(): void
    {
        $this->attivaQuota();
        $this->attivaQuotaCodiceAgente();

        // La porta dell'agente: quota di iscrizione SOSPESA, i 480 dovuti.
        $nuovo = $this->registraDalPortaleAgente();

        $this->assertSame(0, (int) $nuovo->registration_fee_due_cents);

        $this->actingAs($nuovo)->get('/invia')
            ->assertRedirect(route('portal.mlm.agent-code-fee.show'));
    }

    public function test_chi_l_ingresso_l_aveva_gia_pagato_tiene_il_conto_operativo(): void
    {
        [$utente] = $this->makePrivateConQuota(0);
        $this->attivaQuotaCodiceAgente();

        $utente->forceFill([
            'registration_fee_paid_at'   => now(),
            'mlm_agent_request_status'   => 'approved',
            'agent_code_fee_due_cents'   => 48000,
        ])->save();

        // Deve i 480 e non ha ancora firmato, ma il conto e' suo e funziona.
        $this->actingAs($utente->fresh())->get('/invia')->assertOk();
        $this->actingAs($utente->fresh())->get('/incassa/qr')->assertOk();
    }

    public function test_un_vecchio_iscritto_che_diventa_agente_tiene_il_conto_operativo(): void
    {
        [$vecchio] = $this->makePrivate(0);
        $this->attivaQuota();
        $this->attivaQuotaCodiceAgente();

        // Colonna NULL: non ha mai dovuto la quota di iscrizione, e nel
        // circuito ci lavora da prima che esistesse.
        $vecchio->forceFill([
            'mlm_agent_request_status' => 'approved',
            'agent_code_fee_due_cents' => 48000,
        ])->save();

        $this->actingAs($vecchio->fresh())->get('/invia')->assertOk();
    }

    public function test_ma_la_firma_resta_sbarrata_a_chi_non_ha_saldato_il_codice(): void
    {
        [$utente] = $this->makePrivateConQuota(0);
        $this->attivaQuotaCodiceAgente();

        $utente->forceFill([
            'registration_fee_paid_at'  => now(),
            'mlm_agent_request_status'  => 'approved',
            'agent_code_fee_due_cents'  => 48000,
        ])->save();

        // Il conto e' libero, ma agente non lo diventa: la firma e' l'atto
        // che crea l'agente ed e' li' che la strada resta chiusa.
        $this->actingAs($utente->fresh())->get(route('portal.mlm.agent-contract.show'))
            ->assertRedirect(route('portal.mlm.agent-code-fee.show'));
    }

    public function test_il_banner_non_dice_che_il_conto_e_fermo_a_chi_fermo_non_e(): void
    {
        [$utente] = $this->makePrivateConQuota(0);
        $this->attivaQuotaCodiceAgente();

        $utente->forceFill([
            'registration_fee_paid_at'  => now(),
            'mlm_agent_request_status'  => 'approved',
            'agent_code_fee_due_cents'  => 48000,
        ])->save();

        $this->actingAsWithSession($utente->fresh())
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Ti manca un passo per diventare agente', escape: false)
            ->assertDontSee('fino ad allora non puoi inviare KY', escape: false);
    }

    // ─── 22. La porta unica del percorso agente (02/09/2026) ───────────────
    //
    // Un indirizzo solo — /mlm/diventa-agente — che manda sempre al passo
    // giusto. Mail, notifica e banner puntano li': cosi' nessun link puo'
    // atterrare sulla pagina sbagliata e rimbalzare.

    public function test_la_porta_unica_manda_alla_richiesta_chi_non_l_ha_ancora_fatta(): void
    {
        [$utente] = $this->makePrivate(0);

        $this->actingAs($utente)->get(route('portal.mlm.percorso'))
            ->assertRedirect(route('portal.mlm.agent-request.show'));
    }

    public function test_la_porta_unica_manda_alla_quota_chi_e_stato_approvato(): void
    {
        [$utente] = $this->makePrivate(0);
        $this->attivaQuotaCodiceAgente();

        $utente->forceFill([
            'mlm_agent_request_status' => 'approved',
            'agent_code_fee_due_cents' => 48000,
        ])->save();

        $this->actingAs($utente->fresh())->get(route('portal.mlm.percorso'))
            ->assertRedirect(route('portal.mlm.agent-code-fee.show'));
    }

    public function test_la_porta_unica_manda_alla_firma_chi_ha_gia_saldato(): void
    {
        [$utente] = $this->makePrivate(0);
        $this->attivaQuotaCodiceAgente();

        $utente->forceFill([
            'mlm_agent_request_status' => 'approved',
            'agent_code_fee_due_cents' => 48000,
            'agent_code_fee_paid_at'   => now(),
        ])->save();

        $this->actingAs($utente->fresh())->get(route('portal.mlm.percorso'))
            ->assertRedirect(route('portal.mlm.agent-contract.show'));
    }

    public function test_la_porta_unica_non_rimanda_un_agente_dentro_al_percorso(): void
    {
        [$utente] = $this->makePrivate(0);
        $utente->forceFill(['mlm_role' => 'agente'])->save();

        $this->actingAs($utente->fresh())->get(route('portal.mlm.percorso'))
            ->assertRedirect(route('portal.dashboard'));
    }

    // ─── 23. L'arretrato: il bottone dell'admin (02/09/2026) ───────────────

    public function test_l_admin_sospende_a_mano_la_quota_di_chi_era_gia_stato_approvato(): void
    {
        [$utente] = $this->makePrivateConQuota(0);
        $this->attivaQuotaCodiceAgente();

        // Lo stato di chi e' stato approvato PRIMA che la sospensione fosse
        // automatica: tutte e due le quote addosso, e nessun bottone.
        $utente->forceFill([
            'mlm_agent_request_status' => 'approved',
            'agent_code_fee_due_cents' => 48000,
        ])->save();

        $this->actingAsWithSession($this->superAdmin)
            ->post(route('admin.registration-fees.suspend', $utente))
            ->assertRedirect(route('admin.users.show', $utente));

        $this->assertSame(0, (int) $utente->fresh()->registration_fee_due_cents);
        $this->assertFalse(app(RegistrationFeeService::class)->isDueFor($utente->fresh()));
    }

    public function test_il_bottone_non_condona_la_quota_a_chi_non_e_sul_percorso_agente(): void
    {
        [$utente] = $this->makePrivateConQuota(0);

        // Fuori dal percorso agente «sospendere» vorrebbe dire cancellare la
        // quota in silenzio: nessun altro pagamento la copre.
        $this->actingAsWithSession($this->superAdmin)
            ->post(route('admin.registration-fees.suspend', $utente))
            ->assertRedirect(route('admin.users.show', $utente))
            ->assertSessionHas('portal_error');

        $this->assertSame(self::QUOTA, (int) $utente->fresh()->registration_fee_due_cents);
    }

    public function test_il_bottone_non_lo_puo_premere_un_utente_qualunque(): void
    {
        [$utente] = $this->makePrivateConQuota(0);
        [$altro]  = $this->makePrivate(0);

        $utente->forceFill([
            'mlm_agent_request_status' => 'approved',
            'agent_code_fee_due_cents' => 48000,
        ])->save();

        $this->actingAsWithSession($altro)
            ->post(route('admin.registration-fees.suspend', $utente))
            ->assertForbidden();

        $this->assertSame(self::QUOTA, (int) $utente->fresh()->registration_fee_due_cents);
    }

    // ─── Aiutanti ───────────────────────────────────────────────────────────

    private User $superAdmin;

    /**
     * Salda i 480 per la via piu' corta: in euro non si muove nessun KY, non
     * serve ne' un conto di sistema ne' un fido, e la colonna
     * agent_code_fee_paid_at si valorizza esattamente come dal sito.
     */
    private function pagaLaQuotaCodiceInEuro(User $utente): void
    {
        $pagamento = \App\Models\AgentCodeFeePayment::create([
            'user_id'          => $utente->id,
            'amount_eur_cents' => 48000,
            'ky_amount'        => 48000,
            'status'           => \App\Models\AgentCodeFeePayment::STATUS_PENDING,
            'payment_method'   => \App\Models\AgentCodeFeePayment::METHOD_STRIPE,
        ]);

        app(\App\Services\AgentCodeFeeService::class)->completeEuroPayment($pagamento);

        $this->assertNotNull($utente->fresh()->agent_code_fee_paid_at);
    }

    private function attivaQuotaCodiceAgente(bool $attiva = true, int $importo = 48000): void
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

    /**
     * La porta vera: un agente registra un nuovo privato dal suo portale.
     *
     * Passa dalla ROTTA e non dal servizio di proposito — il buco del
     * 01/09/2026 era esattamente questo, un percorso che segnava la quota del
     * codice agente e si dimenticava quella dei privati. Un test scritto sul
     * servizio sarebbe stato verde lo stesso.
     */
    private function registraDalPortaleAgente(): User
    {
        $agente = User::create([
            'name'                => 'Agente Sponsor',
            'email'               => 'agente-' . Str::random(10) . '@test.test',
            'password'            => 'secret123',
            'account_holder_type' => 'private',
            'is_active'           => true,
            'mlm_role'            => 'agente',
            'mlm_activated_at'    => now(),
        ]);
        $agente->forceFill(['email_verified_at' => now()])->save();
        $agente->agentCode();

        // Minuscolo: il controller normalizza l'indirizzo con
        // mb_strtolower() prima di salvarlo, e Str::random() sputa anche
        // maiuscole — cercare l'utente con la stringa di partenza non lo
        // troverebbe mai.
        $email = mb_strtolower('nuovo-agente-' . Str::random(8) . '@test.test');

        $this->actingAsWithSession($agente)->post(route('portal.mlm.agent-create.store'), [
            'name'               => 'Luigi Nuovo Agente',
            'email'              => $email,
            'phone'              => '333 1234567',
            'fiscal_code'        => 'RSSMRA85M01H501Z',
            'birth_date'         => '1985-08-01',
            'birth_place'        => 'Roma',
            'residence_address'  => 'Via Roma 10',
            'residence_zip'      => '00100',
            'residence_city'     => 'Roma',
            'residence_province' => 'rm',
        ]);

        return User::where('email', $email)->firstOrFail();
    }

    /**
     * Sostituisce il verificatore di Stripe con uno che risponde quello che
     * serve al test. Non e' una scorciatoia: e' l'unico modo di provare che
     * il ripescaggio accredita SOLO quando la prova c'e', senza chiamare
     * davvero i server di Stripe.
     */
    /**
     * L'utente chiede di diventare agente: e' lo stato in cui la richiesta
     * arriva sul tavolo dell'admin.
     */
    private function chiediDiDiventareAgente(User $utente): void
    {
        $utente->forceFill([
            'mlm_agent_request_status' => 'pending',
            'mlm_agent_requested_at'   => now(),
        ])->save();
    }

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
            }
        );
    }

    /** Un pagamento in euro finito male: la riga che il ripescaggio deve poter riaprire. */
    private function pagamentoFallito(User $utente, Account $conto, string $metodo = RegistrationFeePayment::METHOD_STRIPE): RegistrationFeePayment
    {
        return RegistrationFeePayment::create([
            'user_id'                    => $utente->id,
            'account_id'                 => $conto->id,
            'amount_eur_cents'           => self::QUOTA,
            'ky_amount'                  => self::QUOTA,
            'status'                     => RegistrationFeePayment::STATUS_FAILED,
            'payment_method'             => $metodo,
            'stripe_checkout_session_id' => $metodo === RegistrationFeePayment::METHOD_STRIPE ? 'cs_test_' . Str::random(12) : null,
            'admin_notes'                => 'Accredito non riuscito.',
        ]);
    }

    private function pagamentoInAttesa(User $utente, Account $conto, int $giorniFa): RegistrationFeePayment
    {
        $pagamento = RegistrationFeePayment::create([
            'user_id'                    => $utente->id,
            'account_id'                 => $conto->id,
            'amount_eur_cents'           => self::QUOTA,
            'ky_amount'                  => self::QUOTA,
            'status'                     => RegistrationFeePayment::STATUS_PENDING,
            'payment_method'             => RegistrationFeePayment::METHOD_STRIPE,
            'stripe_checkout_session_id' => 'cs_test_' . Str::random(12),
        ]);

        $pagamento->forceFill(['created_at' => now()->subDays($giorniFa)])->save();

        return $pagamento->fresh();
    }

    private function attivaQuota(bool $attiva = true, int $importo = self::QUOTA): void
    {
        SystemSetting::userLimitDefaults()->forceFill([
            'registration_fee_enabled'               => $attiva,
            'registration_fee_amount_cents'          => $importo,
            'registration_fee_stripe_enabled'        => false,
            'registration_fee_paypal_enabled'        => false,
            'registration_fee_bank_transfer_enabled' => true,
            'registration_fee_ky_enabled'            => true,
        ])->save();
    }

    /** @return array<string, string> */
    private function datiRegistrazione(array $override = []): array
    {
        return array_merge([
            'account_holder_type'   => 'private',
            'name'                  => 'Nuovo Iscritto',
            'email'                 => 'nuovo@test.test',
            'password'              => 'secret123',
            'password_confirmation' => 'secret123',
        ], $override);
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
        ]);

        // Senza ruolo l'utente non ha payments.send e il motore lo fermerebbe
        // prima ancora di guardare il fido.
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

    /** @return array{0: User, 1: Account} */
    private function makePrivateConQuota(int $saldo, int $fido = 0): array
    {
        $this->attivaQuota(importo: self::QUOTA);

        [$user, $account] = $this->makePrivate($saldo, $fido);

        $user->forceFill(['registration_fee_due_cents' => self::QUOTA])->save();

        return [$user->fresh(), $account];
    }

    /**
     * IL CONTO DI SISTEMA ESISTE GIA'. Una migration del circuito ne crea uno
     * (la "Cassa Circuito KMoney"), e in un database di test le migration
     * girano tutte: creandone un secondo, Account::systemAccount() avrebbe
     * continuato a restituire il PRIMO e questi test avrebbero guardato il
     * saldo di un conto che nessuno tocca — verdi o rossi per il motivo
     * sbagliato. Ce ne siamo accorti solo perche' un test controllava
     * entrambi i lati del movimento: il conto dell'utente scendeva a -30 e
     * quello di sistema restava a zero.
     */
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
