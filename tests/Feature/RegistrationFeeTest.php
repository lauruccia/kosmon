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

    // ─── Aiutanti ───────────────────────────────────────────────────────────

    private User $superAdmin;

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
