<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureRegistrationFeePaid;
use App\Models\Account;
use App\Models\Company;
use App\Models\RegistrationFeePayment;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\Transfer;
use App\Models\User;
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
