<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\CompanyAccountFeePayment;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\Transfer;
use App\Models\TransactionFee;
use App\Models\User;
use App\Notifications\CompanyAccountFeePaidNotification;
use App\Notifications\CompanyAccountFeeReminderNotification;
use App\Notifications\CompanyAccountFeeRequestedNotification;
use App\Services\CompanyAccountFeeService;
use App\Services\RegistrationFeeService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Quota di apertura conto delle aziende (03/09/2026).
 *
 * Le cose che questi test devono dimostrare, perche' sono quelle su cui si
 * perdono soldi o si chiede il conto a chi non lo deve:
 *
 *  1. la deve l'AZIENDA che si registra da ora, e nessun altro — non i
 *     privati, non gli admin, non i collaboratori invitati, non le ~1.200
 *     anagrafiche importate;
 *  2. in euro NON si accredita un solo KY, e nessun movimento tocca il conto;
 *  3. il conto NON si blocca (decisione di Laura del 03/09): e' la differenza
 *     vera dalle altre due quote, e senza un test che la sorvegli la prima
 *     copia-incolla dal middleware la fa sparire;
 *  4. il pagamento in KY esiste solo se l'admin lo accende.
 */
class CompanyAccountFeeTest extends TestCase
{
    use RefreshDatabase;

    private const QUOTA = 60000; // 600,00

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

    // ─── 1. Chi la deve ─────────────────────────────────────────────────────

    public function test_un_azienda_che_si_registra_con_la_quota_attiva_la_deve(): void
    {
        $this->attivaQuota();

        $this->post('/register', $this->datiRegistrazioneAzienda());

        $utente = User::where('email', 'nuova@test.test')->firstOrFail();

        $this->assertSame(self::QUOTA, (int) $utente->company_account_fee_due_cents);
        $this->assertNull($utente->company_account_fee_paid_at);
        $this->assertTrue(app(CompanyAccountFeeService::class)->isDueFor($utente));
    }

    public function test_un_privato_che_si_registra_non_deve_la_quota_delle_aziende(): void
    {
        $this->attivaQuota();

        $this->post('/register', $this->datiRegistrazioneAzienda([
            'account_holder_type' => 'private',
            'company_name'        => null,
        ]));

        $utente = User::where('email', 'nuova@test.test')->firstOrFail();

        $this->assertNull($utente->company_account_fee_due_cents);
    }

    public function test_con_la_quota_spenta_nessuna_azienda_deve_niente(): void
    {
        $this->attivaQuota(attiva: false);

        $this->post('/register', $this->datiRegistrazioneAzienda());

        $utente = User::where('email', 'nuova@test.test')->firstOrFail();

        $this->assertNull($utente->company_account_fee_due_cents);
        $this->assertFalse(app(CompanyAccountFeeService::class)->isDueFor($utente));
    }

    public function test_le_aziende_gia_registrate_non_devono_niente_neanche_dopo_l_attivazione(): void
    {
        [$vecchia] = $this->makeAzienda();
        $this->attivaQuota();

        $this->assertNull($vecchia->fresh()->company_account_fee_due_cents);
        $this->assertFalse(app(CompanyAccountFeeService::class)->isDueFor($vecchia->fresh()));
    }

    public function test_l_importo_e_uno_scatto_e_non_segue_l_admin(): void
    {
        $this->attivaQuota();
        $this->post('/register', $this->datiRegistrazioneAzienda());

        // L'admin cambia la quota il giorno dopo.
        $this->attivaQuota(importo: 90000);

        $utente = User::where('email', 'nuova@test.test')->firstOrFail();
        app(CompanyAccountFeeService::class)->markDueOnRegistration($utente);

        $this->assertSame(self::QUOTA, (int) $utente->fresh()->company_account_fee_due_cents);
    }

    /**
     * Il caso che, senza la seconda condizione di riguarda(), farebbe chiedere
     * 600 euro agli admin: nascono con account_holder_type 'company' e
     * company_id NULL. Vale identico per i collaboratori invitati come
     * sottoconto, che il campo non lo passano affatto e cadono sul default del
     * database, che e' 'company'.
     */
    public function test_chi_e_company_ma_senza_azienda_dietro_non_deve_la_quota(): void
    {
        $this->attivaQuota();

        $senzaAzienda = User::create([
            'name'                => 'Admin del circuito',
            'email'               => 'adm-' . Str::random(6) . '@test.test',
            'password'            => 'secret123',
            'account_holder_type' => 'company',
            'company_id'          => null,
            'role'                => 'private-owner',
            'is_active'           => true,
            'email_verified_at'   => now(),
        ]);

        app(CompanyAccountFeeService::class)->markDueOnRegistration($senzaAzienda);

        $this->assertNull($senzaAzienda->fresh()->company_account_fee_due_cents);
        $this->assertFalse(app(CompanyAccountFeeService::class)->riguarda($senzaAzienda->fresh()));
    }

    // ─── 2. La quota NON blocca il conto ────────────────────────────────────

    /**
     * LA DECISIONE DEL 03/09 IN FORMA DI TEST. L'azienda che non ha saldato
     * continua a operare: qui si prova su una rotta che per le ALTRE quote e'
     * sbarrata, e il test gemello qui sotto dimostra che quella rotta e'
     * davvero sbarrata — senza, questo non proverebbe niente.
     */
    public function test_l_azienda_che_non_ha_saldato_puo_ancora_operare(): void
    {
        $this->attivaQuota();
        [$azienda] = $this->makeAzienda();
        $this->mettiInCarico($azienda);

        $risposta = $this->actingAs($azienda->fresh())->get('/invia');

        $risposta->assertOk();
    }

    public function test_quella_stessa_rotta_e_invece_sbarrata_a_un_privato_che_non_ha_saldato(): void
    {
        SystemSetting::userLimitDefaults()->forceFill([
            'registration_fee_enabled'               => true,
            'registration_fee_amount_cents'          => 3000,
            'registration_fee_bank_transfer_enabled' => true,
        ])->save();

        [$privato] = $this->makePrivate(0);
        $privato->forceFill(['registration_fee_due_cents' => 3000])->save();

        $this->assertTrue(app(RegistrationFeeService::class)->isDueFor($privato->fresh()));

        $this->actingAs($privato->fresh())->get('/invia')
            ->assertRedirect(route('portal.registration-fee.show'));
    }

    /**
     * SENTINELLA DELLA DECISIONE. Il middleware delle quote non deve
     * conoscere questa quota: il giorno in cui qualcuno ce la aggiunge per
     * simmetria con le altre due, milleduecento conti aziendali si fermano
     * senza che nessuno lo abbia chiesto. Se la decisione cambia, il posto
     * dove cambiarla e' questo test, non il middleware di nascosto.
     */
    public function test_il_middleware_delle_quote_non_conosce_la_quota_di_apertura_conto(): void
    {
        $middleware = file_get_contents(app_path('Http/Middleware/EnsureRegistrationFeePaid.php'));

        $this->assertStringNotContainsString(
            'CompanyAccountFee',
            $middleware,
            "La quota di apertura conto non deve bloccare niente (decisione di Laura del 03/09/2026): "
            . "se la regola e' cambiata, cambiala qui e nel docblock del servizio."
        );
    }

    // ─── 3. Pagamento in euro: nessun KY, nessun movimento ──────────────────

    public function test_il_bonifico_confermato_salda_la_quota_senza_muovere_un_solo_ky(): void
    {
        $this->attivaQuota();
        [$azienda, $conto] = $this->makeAzienda(saldo: 5000);
        $this->mettiInCarico($azienda);

        $this->actingAs($azienda->fresh())->post('/quota-apertura-conto/bonifico')->assertOk();

        $pagamento = CompanyAccountFeePayment::where('user_id', $azienda->id)->firstOrFail();
        $this->assertTrue($pagamento->isPendingBankTransfer());
        $this->assertStringStartsWith('APERTURA-', $pagamento->bank_transfer_reference);

        $this->actingAs($this->superAdmin)
            ->post("/admin/quote-apertura-conto/{$pagamento->id}/conferma")
            ->assertRedirect(route('admin.company-account-fees.index'));

        $azienda->refresh();
        $this->assertNotNull($azienda->company_account_fee_paid_at);
        $this->assertFalse(app(CompanyAccountFeeService::class)->isDueFor($azienda));

        // Il punto che vale i soldi: nessun KY emesso, saldo intatto.
        $this->assertSame(5000, (int) $conto->fresh()->available_balance);
        $this->assertSame(0, Transfer::where('kind', 'like', 'company_account_fee%')->count());
        $this->assertNull($pagamento->fresh()->transfer_id);
        $this->assertSame(0, (int) $azienda->company_account_fee_ky_allowance_cents);

        Notification::assertSentTo($azienda, CompanyAccountFeePaidNotification::class);
    }

    public function test_il_webhook_stripe_salda_la_quota(): void
    {
        $this->attivaQuota(stripe: true);
        [$azienda] = $this->makeAzienda();
        $this->mettiInCarico($azienda);

        $pagamento = app(CompanyAccountFeeService::class)
            ->startPayment($azienda->fresh(), CompanyAccountFeePayment::METHOD_STRIPE);
        $pagamento->update(['stripe_checkout_session_id' => 'cs_test_' . Str::random(10)]);

        $this->fingiStripePagata($pagamento);
        $this->postWebhookStripe($pagamento->stripe_checkout_session_id)->assertOk();

        $this->assertTrue($pagamento->fresh()->isCompleted());
        $this->assertNotNull($azienda->fresh()->company_account_fee_paid_at);
    }

    // ─── 4. Pagamento in KY: solo se l'admin lo accende ─────────────────────

    public function test_senza_l_interruttore_il_saldo_ky_non_e_un_metodo_disponibile(): void
    {
        $this->attivaQuota(); // ky spento, come nasce
        [$azienda] = $this->makeAzienda(saldo: 100000);
        $this->mettiInCarico($azienda);

        $this->assertArrayNotHasKey('ky', app(CompanyAccountFeeService::class)->availableMethods());

        $this->actingAs($azienda->fresh())->post('/quota-apertura-conto/ky')
            ->assertRedirect(route('portal.company-account-fee.show'));

        $this->assertNull($azienda->fresh()->company_account_fee_paid_at);
    }

    public function test_con_l_interruttore_acceso_il_conto_va_sotto_e_il_fido_resta_intero(): void
    {
        $this->attivaQuota(ky: true);
        $this->makeSystemAccount(0);
        [$azienda, $conto] = $this->makeAzienda(saldo: 0, fido: 20000);
        $this->mettiInCarico($azienda);

        $this->actingAs($azienda->fresh())->post('/quota-apertura-conto/ky')->assertRedirect();

        $azienda->refresh();
        $this->assertNotNull($azienda->company_account_fee_paid_at);
        $this->assertSame(self::QUOTA, (int) $azienda->company_account_fee_ky_allowance_cents);
        $this->assertSame(-self::QUOTA, (int) $conto->fresh()->available_balance);

        // Il fido dell'admin non viene mangiato dalla quota: si sommano.
        $this->assertSame(20000 + self::QUOTA, $conto->fresh()->massimale());
    }

    /**
     * DA SAPERE PRIMA DI ACCENDERE IL PAGAMENTO IN KY. La quota in KY e'
     * un'uscita come tutte le altre e passa dai limiti del conto: un'azienda
     * con un limite giornaliero personalizzato piu' basso della quota non
     * riesce a saldarla, e vede il perche'. Non e' un guasto — e' il motore
     * che fa il suo mestiere — ma se capita, la strada e' alzare il limite di
     * quel conto, non insistere sul bottone.
     */
    public function test_un_limite_giornaliero_piu_basso_della_quota_impedisce_di_pagarla_in_ky(): void
    {
        $this->attivaQuota(ky: true);
        $this->makeSystemAccount(0);
        [$azienda] = $this->makeAzienda();
        $azienda->forceFill([
            'transfer_limits_use_defaults' => false,
            'daily_transaction_limit'      => 50000, // 500,00 KY, meno dei 600
        ])->save();
        $this->mettiInCarico($azienda);

        $this->actingAs($azienda->fresh())->post('/quota-apertura-conto/ky')
            ->assertRedirect(route('portal.company-account-fee.show'))
            ->assertSessionHas('portal_error');

        $this->assertNull($azienda->fresh()->company_account_fee_paid_at);
        $this->assertSame(
            CompanyAccountFeePayment::STATUS_FAILED,
            CompanyAccountFeePayment::where('user_id', $azienda->id)->firstOrFail()->status,
        );
    }

    // ─── 4bis. Le due leve: cosa riceve l'azienda in cambio ────────────────

    public function test_in_euro_con_un_accredito_impostato_i_ky_arrivano_sul_conto(): void
    {
        $this->attivaQuota(accredito: 40000); // 400,00 KY su 600 € di quota
        $this->makeSystemAccount(500000);
        [$azienda, $conto] = $this->makeAzienda(saldo: 1000);
        $this->mettiInCarico($azienda);

        $pagamento = $this->saldaConBonifico($azienda);

        $this->assertTrue($pagamento->fresh()->isCompleted());
        $this->assertSame(1000 + 40000, (int) $conto->fresh()->available_balance);
        $this->assertSame(1, Transfer::where('kind', 'company_account_fee_credit')->count());
        $this->assertSame(40000, (int) $pagamento->fresh()->transfer->amount);
        Notification::assertSentTo($azienda, CompanyAccountFeePaidNotification::class);
    }

    /**
     * Il ripiego della singola azienda vince sul pannello. È la ragione per
     * cui le due colonne su `users` esistono.
     */
    public function test_l_accredito_della_singola_azienda_scavalca_il_pannello(): void
    {
        $this->attivaQuota(accredito: 0);
        $this->makeSystemAccount(500000);
        [$azienda, $conto] = $this->makeAzienda();
        $this->mettiInCarico($azienda);

        $this->actingAs($this->superAdmin)
            ->post("/admin/users/{$azienda->id}/quota-apertura-conto/trattamento", ['ky_credit' => '250'])
            ->assertRedirect(route('admin.users.show', $azienda));

        $this->assertSame(25000, app(CompanyAccountFeeService::class)->kyCreditFor($azienda->fresh()));

        $this->saldaConBonifico($azienda->fresh());

        $this->assertSame(25000, (int) $conto->fresh()->available_balance);
    }

    /**
     * ZERO SCRITTO E CAMPO VUOTO NON SONO LA STESSA COSA, ed è tutta la
     * differenza fra «per questa azienda ho deciso di non dare niente» e «per
     * questa azienda vale quel che dice il pannello». Se un giorno qualcuno
     * semplificasse le due colonne in una, questo test cadrebbe.
     */
    public function test_zero_scritto_sull_azienda_non_e_come_lasciare_vuoto(): void
    {
        $this->attivaQuota(accredito: 50000);
        $this->makeSystemAccount(500000);
        [$azienda, $conto] = $this->makeAzienda();
        $this->mettiInCarico($azienda);

        $this->actingAs($this->superAdmin)
            ->post("/admin/users/{$azienda->id}/quota-apertura-conto/trattamento", ['ky_credit' => '0'])
            ->assertRedirect();

        $azienda->refresh();
        $this->assertSame(0, (int) $azienda->company_account_fee_ky_credit_override_cents);
        $this->assertSame(0, app(CompanyAccountFeeService::class)->kyCreditFor($azienda));

        $this->saldaConBonifico($azienda);

        $this->assertSame(0, (int) $conto->fresh()->available_balance);
        $this->assertSame(0, Transfer::where('kind', 'company_account_fee_credit')->count());

        // E svuotando il campo si torna a seguire il pannello.
        $this->actingAs($this->superAdmin)
            ->post("/admin/users/{$azienda->id}/quota-apertura-conto/trattamento", ['ky_credit' => ''])
            ->assertRedirect();

        $this->assertNull($azienda->fresh()->company_account_fee_ky_credit_override_cents);
        $this->assertSame(50000, app(CompanyAccountFeeService::class)->kyCreditFor($azienda->fresh()));
    }

    public function test_senza_fido_aggiuntivo_chi_non_ha_fido_suo_non_riesce_a_pagare_in_ky(): void
    {
        $this->attivaQuota(ky: true, fido: false);
        $this->makeSystemAccount(0);
        [$azienda, $conto] = $this->makeAzienda(saldo: 0, fido: 0);
        $this->mettiInCarico($azienda);

        $this->actingAs($azienda->fresh())->post('/quota-apertura-conto/ky')
            ->assertRedirect(route('portal.company-account-fee.show'))
            ->assertSessionHas('portal_error');

        $azienda->refresh();
        $this->assertNull($azienda->company_account_fee_paid_at);
        $this->assertSame(0, (int) $azienda->company_account_fee_ky_allowance_cents);
        $this->assertSame(0, (int) $conto->fresh()->available_balance);
    }

    public function test_senza_fido_aggiuntivo_la_quota_si_mangia_il_fido_che_l_azienda_ha_gia(): void
    {
        $this->attivaQuota(ky: true, fido: false);
        $this->makeSystemAccount(0);
        [$azienda, $conto] = $this->makeAzienda(saldo: 0, fido: 100000);
        $this->mettiInCarico($azienda);

        $this->actingAs($azienda->fresh())->post('/quota-apertura-conto/ky')->assertRedirect();

        $azienda->refresh();
        $this->assertNotNull($azienda->company_account_fee_paid_at);
        $this->assertSame(-self::QUOTA, (int) $conto->fresh()->available_balance);
        // Nessun fido aggiuntivo: il massimale resta quello di prima, e la
        // capienza che le resta è 100.000 − 60.000.
        $this->assertSame(0, (int) $azienda->company_account_fee_ky_allowance_cents);
        $this->assertSame(100000, $conto->fresh()->massimale());
    }

    public function test_il_fido_della_singola_azienda_scavalca_il_pannello(): void
    {
        $this->attivaQuota(ky: true, fido: false);
        $this->makeSystemAccount(0);
        [$azienda, $conto] = $this->makeAzienda(saldo: 0, fido: 0);
        $this->mettiInCarico($azienda);

        $this->actingAs($this->superAdmin)
            ->post("/admin/users/{$azienda->id}/quota-apertura-conto/trattamento", ['ky_allowance' => '1'])
            ->assertRedirect();

        $this->assertTrue(app(CompanyAccountFeeService::class)->kyAllowanceEnabledFor($azienda->fresh()));

        $this->actingAs($azienda->fresh())->post('/quota-apertura-conto/ky')->assertRedirect();

        $azienda->refresh();
        $this->assertNotNull($azienda->company_account_fee_paid_at);
        $this->assertSame(self::QUOTA, (int) $azienda->company_account_fee_ky_allowance_cents);
    }

    public function test_il_trattamento_impostato_finisce_nell_audit_log(): void
    {
        $this->attivaQuota();
        [$azienda] = $this->makeAzienda();

        $this->actingAs($this->superAdmin)
            ->post("/admin/users/{$azienda->id}/quota-apertura-conto/trattamento", [
                'ky_credit'    => '120,50',
                'ky_allowance' => '0',
            ])
            ->assertRedirect();

        $azienda->refresh();
        $this->assertSame(12050, (int) $azienda->company_account_fee_ky_credit_override_cents);
        $this->assertFalse((bool) $azienda->company_account_fee_ky_allowance_override);
        $this->assertSame(1, AuditLog::where('event', 'company_account_fee.treatment_set')->count());
    }

    public function test_il_trattamento_non_si_imposta_su_un_privato(): void
    {
        [$privato] = $this->makePrivate(0);

        $this->actingAs($this->superAdmin)
            ->post("/admin/users/{$privato->id}/quota-apertura-conto/trattamento", ['ky_credit' => '100'])
            ->assertRedirect(route('admin.users.show', $privato));

        $this->assertNull($privato->fresh()->company_account_fee_ky_credit_override_cents);
    }

    public function test_annullare_una_quota_pagata_in_euro_con_accredito_riprende_i_ky(): void
    {
        $this->attivaQuota(accredito: 30000);
        $this->makeSystemAccount(500000);
        [$azienda, $conto] = $this->makeAzienda(saldo: 0);
        $this->mettiInCarico($azienda);

        $pagamento = $this->saldaConBonifico($azienda);
        $this->assertSame(30000, (int) $conto->fresh()->available_balance);

        $this->actingAs($this->superAdmin)
            ->post("/admin/quote-apertura-conto/{$pagamento->id}/annulla", ['admin_notes' => 'Prova'])
            ->assertRedirect(route('admin.company-account-fees.index'));

        $azienda->refresh();
        $this->assertTrue($pagamento->fresh()->isCancelled());
        $this->assertSame(0, (int) $conto->fresh()->available_balance);
        $this->assertSame(self::QUOTA, (int) $azienda->company_account_fee_due_cents);
        $this->assertSame(1, Transfer::where('kind', 'company_account_fee_reversal')->count());
    }

    /**
     * LE PAGINE SI DEVONO APRIRE DAVVERO, e non e' una prova di cortesia: un
     * @ if attaccato a una parola (quell'importo@ if...) Blade lo lascia
     * letterale e sbilancia il blocco, e la pagina esplode solo quando
     * qualcuno la apre. E' successo il 04/09/2026 su due di queste tre.
     */
    public function test_le_tre_pagine_della_quota_si_aprono(): void
    {
        $this->attivaQuota(ky: true, accredito: 25000);
        $this->makeSystemAccount(0);
        [$azienda] = $this->makeAzienda(saldo: 0, fido: 100000);
        $this->mettiInCarico($azienda);

        $this->actingAs($azienda->fresh())->get('/quota-apertura-conto')
            ->assertOk()
            ->assertSee('250,00', false);

        $this->actingAs($azienda->fresh())->post('/quota-apertura-conto/bonifico')
            ->assertOk()
            ->assertSee('APERTURA-', false);

        $this->actingAs($azienda->fresh())->post('/quota-apertura-conto/ky')->assertRedirect();
        $pagamento = CompanyAccountFeePayment::where('user_id', $azienda->id)
            ->where('payment_method', CompanyAccountFeePayment::METHOD_KY)->firstOrFail();

        $this->actingAs($azienda->fresh())->get('/quota-apertura-conto/esito/' . $pagamento->uuid)
            ->assertOk()
            ->assertSee('saldata', false);
    }

    // ─── 5. Annullamento dal backoffice ─────────────────────────────────────

    public function test_annullare_una_quota_pagata_in_ky_storna_e_toglie_il_fido_aggiuntivo(): void
    {
        $this->attivaQuota(ky: true);
        $this->makeSystemAccount(0);
        [$azienda, $conto] = $this->makeAzienda(saldo: 0);
        $this->mettiInCarico($azienda);

        $this->actingAs($azienda->fresh())->post('/quota-apertura-conto/ky')->assertRedirect();
        $pagamento = CompanyAccountFeePayment::where('user_id', $azienda->id)->firstOrFail();

        $this->actingAs($this->superAdmin)
            ->post("/admin/quote-apertura-conto/{$pagamento->id}/annulla", ['admin_notes' => 'Prova'])
            ->assertRedirect(route('admin.company-account-fees.index'));

        $azienda->refresh();
        $this->assertTrue($pagamento->fresh()->isCancelled());
        $this->assertNull($azienda->company_account_fee_paid_at);
        $this->assertSame(self::QUOTA, (int) $azienda->company_account_fee_due_cents);
        $this->assertSame(0, (int) $azienda->company_account_fee_ky_allowance_cents);
        $this->assertSame(0, (int) $conto->fresh()->available_balance);
        $this->assertSame(1, Transfer::where('kind', 'company_account_fee_reversal')->count());
    }

    // ─── 6. La richiesta dal backoffice ─────────────────────────────────────

    public function test_l_admin_puo_mettere_la_quota_in_carico_a_un_azienda_che_non_la_deve(): void
    {
        $this->attivaQuota();
        [$azienda] = $this->makeAzienda();

        $this->actingAs($this->superAdmin)
            ->post("/admin/users/{$azienda->id}/quota-apertura-conto/richiedi")
            ->assertRedirect(route('admin.users.show', $azienda));

        $this->assertSame(self::QUOTA, (int) $azienda->fresh()->company_account_fee_due_cents);
        $this->assertSame(1, AuditLog::where('event', 'company_account_fee.requested_by_admin')->count());
        Notification::assertSentTo($azienda, CompanyAccountFeeRequestedNotification::class);
    }

    public function test_la_quota_non_si_puo_chiedere_a_un_privato(): void
    {
        $this->attivaQuota();
        [$privato] = $this->makePrivate(0);

        $this->actingAs($this->superAdmin)
            ->post("/admin/users/{$privato->id}/quota-apertura-conto/richiedi")
            ->assertRedirect(route('admin.users.show', $privato));

        $this->assertNull($privato->fresh()->company_account_fee_due_cents);
    }

    /** Stesso motivo del test sulle tre pagine utente: qui vive il form del trattamento. */
    public function test_le_due_pagine_di_backoffice_si_aprono(): void
    {
        $this->attivaQuota(accredito: 25000);
        [$azienda] = $this->makeAzienda();
        $this->mettiInCarico($azienda);

        // Dal 04/09/2026 le tre quote stanno in una pagina sola: il vecchio
        // indirizzo ci porta, sulla scheda giusta.
        $this->actingAs($this->superAdmin)->get('/admin/quote-apertura-conto')
            ->assertRedirect('/admin/quote?tab=aziende');

        $this->actingAs($this->superAdmin)->get('/admin/quote?tab=aziende')
            ->assertOk()
            ->assertSee('Cosa riceve chi paga', false);

        $this->actingAs($this->superAdmin)->get('/admin/users/' . $azienda->id)
            ->assertOk()
            ->assertSee('Cosa riceve in cambio', false);
    }

    public function test_un_utente_qualunque_non_entra_nel_backoffice_delle_quote(): void
    {
        [$privato] = $this->makePrivate(0);

        $this->actingAs($privato)->get('/admin/quote-apertura-conto')->assertForbidden();
    }

    // ─── 7. Impostazioni ────────────────────────────────────────────────────

    public function test_non_si_attiva_la_quota_senza_nessun_metodo_di_pagamento(): void
    {
        $this->actingAs($this->superAdmin)
            ->post('/admin/quote-apertura-conto/impostazioni', [
                'company_account_fee_enabled'   => '1',
                'company_account_fee_amount'    => '600',
                'company_account_fee_ky_credit' => '0',
            ])
            ->assertSessionHasErrors('company_account_fee_enabled');

        $this->assertFalse((bool) SystemSetting::userLimitDefaults()->fresh()->company_account_fee_enabled);
    }

    public function test_l_admin_imposta_importo_e_interruttore(): void
    {
        $this->actingAs($this->superAdmin)
            ->post('/admin/quote-apertura-conto/impostazioni', [
                'company_account_fee_enabled'               => '1',
                'company_account_fee_amount'                => '750,50',
                'company_account_fee_bank_transfer_enabled' => '1',
                'company_account_fee_ky_credit'             => '200',
                'company_account_fee_ky_allowance'          => '1',
            ])
            ->assertSessionHasNoErrors();

        $settings = SystemSetting::userLimitDefaults()->fresh();
        $this->assertTrue((bool) $settings->company_account_fee_enabled);
        $this->assertSame(75050, $settings->companyAccountFeeAmount());
        $this->assertTrue($settings->companyAccountFeeEnabled());
        $this->assertSame(20000, $settings->companyAccountFeeKyCredit());
        $this->assertTrue($settings->companyAccountFeeKyAllowance());

        // La checkbox non spuntata spegne il fido, come ogni altro flag del form.
        $this->actingAs($this->superAdmin)
            ->post('/admin/quote-apertura-conto/impostazioni', [
                'company_account_fee_amount'    => '750,50',
                'company_account_fee_ky_credit' => '200',
            ])
            ->assertSessionHasNoErrors();

        $this->assertFalse(SystemSetting::userLimitDefaults()->fresh()->companyAccountFeeKyAllowance());
    }

    // ─── 8. I due comandi notturni ──────────────────────────────────────────

    public function test_il_comando_di_scadenza_chiude_i_tentativi_appesi_della_nuova_quota(): void
    {
        $this->attivaQuota(stripe: true);
        [$azienda] = $this->makeAzienda();
        $this->mettiInCarico($azienda);

        $pagamento = app(CompanyAccountFeeService::class)
            ->startPayment($azienda->fresh(), CompanyAccountFeePayment::METHOD_STRIPE);
        $pagamento->forceFill(['created_at' => now()->subDays(3)])->save();

        Artisan::call('quote:scadi-tentativi');

        $this->assertSame(CompanyAccountFeePayment::STATUS_FAILED, $pagamento->fresh()->status);
    }

    public function test_il_sollecito_parte_una_volta_sola(): void
    {
        $this->attivaQuota();
        [$azienda] = $this->makeAzienda();
        $this->mettiInCarico($azienda);
        $azienda->forceFill(['created_at' => now()->subDays(10)])->save();

        Artisan::call('quote:solleciti-iscrizione');
        Artisan::call('quote:solleciti-iscrizione');

        Notification::assertSentToTimes($azienda, CompanyAccountFeeReminderNotification::class, 1);
        $this->assertSame(1, AuditLog::where('event', 'company_account_fee.reminded')->count());
    }

    // ─── 9. Contabilita' ────────────────────────────────────────────────────

    public function test_i_movimenti_della_quota_non_pagano_commissione(): void
    {
        TransactionFee::create([
            'operation_kind' => '*',
            'fee_type'       => 'percent',
            'fee_value'      => 10,
            'is_active'      => true,
        ]);

        $this->assertSame(0, TransactionFee::calculate('company_account_fee', self::QUOTA));
        $this->assertSame(0, TransactionFee::calculate('company_account_fee_reversal', self::QUOTA));
    }

    // ─── Aiutanti ───────────────────────────────────────────────────────────

    private function attivaQuota(
        bool $attiva = true,
        int $importo = self::QUOTA,
        bool $ky = false,
        bool $stripe = false,
        int $accredito = 0,
        bool $fido = true,
    ): void {
        SystemSetting::userLimitDefaults()->forceFill([
            'company_account_fee_enabled'               => $attiva,
            'company_account_fee_amount_cents'          => $importo,
            'company_account_fee_stripe_enabled'        => $stripe,
            'company_account_fee_paypal_enabled'        => false,
            'company_account_fee_bank_transfer_enabled' => true,
            'company_account_fee_ky_enabled'            => $ky,
            'company_account_fee_ky_credit_cents'       => $accredito,
            'company_account_fee_ky_allowance'          => $fido,
        ])->save();

        if ($stripe) {
            config(['services.stripe.secret' => 'sk_test_finta']);
        }
    }

    /** Il giro completo del bonifico: l'azienda lo chiede, l'admin lo conferma. */
    private function saldaConBonifico(User $azienda): CompanyAccountFeePayment
    {
        $this->actingAs($azienda->fresh())->post('/quota-apertura-conto/bonifico')->assertOk();

        $pagamento = CompanyAccountFeePayment::where('user_id', $azienda->id)
            ->where('status', CompanyAccountFeePayment::STATUS_PENDING_BANK_TRANSFER)
            ->firstOrFail();

        $this->actingAs($this->superAdmin)
            ->post("/admin/quote-apertura-conto/{$pagamento->id}/conferma")
            ->assertRedirect(route('admin.company-account-fees.index'));

        return $pagamento;
    }

    private function mettiInCarico(User $azienda, ?int $importo = null): void
    {
        $azienda->forceFill([
            'company_account_fee_due_cents' => $importo ?? self::QUOTA,
            'company_account_fee_paid_at'   => null,
        ])->save();
    }

    private function datiRegistrazioneAzienda(array $override = []): array
    {
        return array_merge([
            'account_holder_type'   => 'company',
            'name'                  => 'Titolare Azienda',
            'company_name'          => 'Azienda Nuova SRL',
            'email'                 => 'nuova@test.test',
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
    private function makeAzienda(int $saldo = 0, int $fido = 0): array
    {
        $slug = 'azienda-' . Str::random(6);

        $company = Company::create([
            'name'          => 'Azienda ' . Str::random(4),
            'slug'          => $slug,
            'email'         => $slug . '@test.test',
            'status'        => 'active',
            'kyc_status'    => 'approved',
            'currency_code' => 'KY',
            'sector'        => 'servizi',
        ]);

        $user = User::create([
            'name'                         => 'Titolare ' . Str::random(4),
            'email'                        => 'az-' . Str::random(8) . '@test.test',
            'password'                     => 'secret123',
            'account_holder_type'          => 'company',
            'company_id'                   => $company->id,
            'role'                         => 'registered-company',
            'is_active'                    => true,
            'is_super_admin'               => false,
            'email_verified_at'            => now(),
            'contract_signed_at'           => now(),
            'transfer_limits_use_defaults' => false,
            'negative_balance_limit'       => $fido,
            // Nessun limite di uscita personalizzato: e' il caso normale di un
            // conto nuovo. Il caso opposto — limite giornaliero piu' basso
            // della quota — ha un test suo, qui sotto, perche' e' una cosa che
            // capita davvero e va saputa prima di accendere il pagamento in KY.
            'daily_transaction_limit'      => null,
            'monthly_transaction_limit'    => null,
            'per_movement_limit'           => null,
        ]);

        $user->roles()->sync([Role::where('slug', 'company-manager')->firstOrFail()->id]);

        $account = Account::create([
            'company_id'        => $company->id,
            'owner_user_id'     => $user->id,
            'owner_type'        => 'company',
            'type'              => 'primary',
            'status'            => 'active',
            'available_balance' => $saldo,
            // ATTENZIONE, QUESTA RIGA NON E' ARREDAMENTO. Ogni conto nasce con
            // un limite giornaliero di uscita di 500 KY (Account::booted), e
            // una quota da 600 KY non ci passa: senza alzarlo, il pagamento in
            // KY viene rifiutato dal motore. E' esattamente cio' che succede in
            // produzione a un conto appena aperto — vedi il test dedicato piu'
            // sopra, che quel caso lo prova a parte.
            'daily_outgoing_limit' => 1000000,
        ]);

        return [$user->fresh(), $account->fresh()];
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

    /**
     * Stripe non viene interrogata davvero nei test: si finge che la sessione
     * salvata sul pagamento risulti incassata, dell'importo esatto.
     */
    private function fingiStripePagata(CompanyAccountFeePayment $pagamento): void
    {
        $this->mock(\App\Services\StripeCheckoutVerifier::class, function ($mock) {
            $mock->shouldReceive('sessionMatches')->andReturn(true);
            $mock->shouldReceive('isPaidFor')->andReturn(true);
        });
    }

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

        $t     = time();
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
}
