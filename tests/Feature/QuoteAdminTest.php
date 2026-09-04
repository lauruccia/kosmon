<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AgentCodeFeePayment;
use App\Models\Company;
use App\Models\CompanyAccountFeePayment;
use App\Models\RegistrationFeePayment;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\Transfer;
use App\Models\User;
use App\Services\AgentCodeFeeService;
use App\Services\CompanyAccountFeeService;
use App\Services\RegistrationFeeService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * LE TRE QUOTE IN UNA PAGINA SOLA, E LE DUE LEVE UGUALI PER TUTTE
 * (richiesta di Laura del 04/09/2026).
 *
 * Quello che questi test devono dimostrare, perche' sono i punti dove si
 * perdono soldi veri o si blocca qualcuno:
 *
 *  1. LA RESTITUZIONE NON E' PIU' L'IMPORTO. Fino al 03/09 il privato che
 *     pagava in euro riceveva sempre tanti KY quanti ne aveva pagati, e il
 *     numero era cablato nel codice. Adesso lo decide l'admin: se qualcuno
 *     rimettesse `ky_amount` al posto della cifra impostata, chi ha una
 *     restituzione diversa riceverebbe l'importo sbagliato senza che niente
 *     protesti.
 *  2. IL FIDO NON SI SPEGNE DA SOLO. Una casella non spuntata e una casella
 *     assente arrivano identiche, e boolean() risponde false a tutte e due:
 *     senza la guardia sul marcatore del form, una richiesta che non porta i
 *     due campi spegnerebbe il fido, e il prossimo che paga in KY si vedrebbe
 *     rifiutare l'addebito.
 *  3. NULL SEGUE IL PANNELLO, ZERO NO. E' l'unico motivo per cui i ripieghi
 *     sono colonne separate invece di scrivere direttamente il numero.
 *  4. SALVARE UNA QUOTA NON TOCCA LE ALTRE DUE. Sono tre form su una pagina
 *     sola, e un errore su uno non deve poter spostare gli altri.
 */
class QuoteAdminTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Mail::fake();
        $this->seed(RolesAndPermissionsSeeder::class);

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

        config(['kmoney.bank_iban' => 'IT60X0542811101000000123456']);
    }

    // ─── 1. La pagina ───────────────────────────────────────────────────────

    public function test_la_pagina_unica_mostra_tutte_e_tre_le_quote(): void
    {
        $this->actingAs($this->superAdmin)->get('/admin/quote')
            ->assertOk()
            ->assertSee('Quota di iscrizione', false)
            ->assertSee('Quota per il codice agente', false)
            ->assertSee('Quota di apertura conto', false)
            ->assertSee('KY restituiti a chi paga in euro', false)
            ->assertSee('Fido aggiuntivo a chi paga in KY', false);
    }

    public function test_un_utente_qualunque_non_entra_nella_pagina_delle_quote(): void
    {
        [$privato] = $this->privato();

        $this->actingAs($privato)->get('/admin/quote')->assertForbidden();
    }

    /**
     * I tre indirizzi vecchi non muoiono: ci puntano i link della scheda
     * utente, i rimandi dei controller dopo ogni azione e i segnalibri.
     */
    public function test_i_tre_indirizzi_vecchi_portano_alla_scheda_giusta(): void
    {
        $admin = $this->actingAs($this->superAdmin);

        $admin->get('/admin/quote-iscrizione')->assertRedirect('/admin/quote?tab=privati');
        $admin->get('/admin/quote-codice-agente')->assertRedirect('/admin/quote?tab=agenti');
        $admin->get('/admin/quote-apertura-conto')->assertRedirect('/admin/quote?tab=aziende');
    }

    public function test_ogni_scheda_mostra_i_pagamenti_della_sua_quota_e_non_quelli_delle_altre(): void
    {
        [$utente] = $this->privato();
        $utente->forceFill([
            'registration_fee_due_cents' => 3000,
            'agent_code_fee_due_cents'   => 48000,
        ])->save();

        RegistrationFeePayment::create([
            'user_id' => $utente->id, 'amount_eur_cents' => 3000, 'ky_amount' => 3000,
            'status'  => RegistrationFeePayment::STATUS_PENDING_BANK_TRANSFER,
            'payment_method' => RegistrationFeePayment::METHOD_BANK_TRANSFER,
        ]);
        AgentCodeFeePayment::create([
            'user_id' => $utente->id, 'amount_eur_cents' => 48000, 'ky_amount' => 48000,
            'status'  => AgentCodeFeePayment::STATUS_PENDING_BANK_TRANSFER,
            'payment_method' => AgentCodeFeePayment::METHOD_BANK_TRANSFER,
        ]);

        // Sulla scheda dei privati c'e' una riga sola, quella da 30,00; su
        // quella degli agenti una sola, quella da 480,00.
        $this->actingAs($this->superAdmin)->get('/admin/quote?tab=privati')
            ->assertOk()
            ->assertSee('30,00 &euro;', false)
            ->assertDontSee('480,00 &euro;', false);

        $this->actingAs($this->superAdmin)->get('/admin/quote?tab=agenti')
            ->assertOk()
            ->assertSee('480,00 &euro;', false)
            ->assertDontSee('30,00 &euro;', false);
    }

    // ─── 2. Il salvataggio ──────────────────────────────────────────────────

    public function test_salvare_una_quota_non_tocca_le_altre_due(): void
    {
        $this->impostazioni([
            'agent_code_fee_amount_cents'        => 48000,
            'agent_code_fee_ky_credit_cents'     => 1000,
            'company_account_fee_amount_cents'   => 60000,
            'company_account_fee_ky_credit_cents' => 2000,
        ]);

        $this->actingAs($this->superAdmin)->post('/admin/quote-iscrizione/impostazioni', [
            'registration_fee_form'                  => '1',
            'registration_fee_enabled'               => '1',
            'registration_fee_amount'                => '35',
            'registration_fee_ky_credit'             => '12',
            'registration_fee_ky_allowance'          => '1',
            'registration_fee_bank_transfer_enabled' => '1',
        ])->assertSessionHasNoErrors();

        $s = SystemSetting::userLimitDefaults()->fresh();

        $this->assertSame(3500, (int) $s->registration_fee_amount_cents);
        $this->assertSame(1200, (int) $s->registration_fee_ky_credit_cents);
        // Le altre due sono rimaste esattamente dov'erano.
        $this->assertSame(48000, (int) $s->agent_code_fee_amount_cents);
        $this->assertSame(1000, (int) $s->agent_code_fee_ky_credit_cents);
        $this->assertSame(60000, (int) $s->company_account_fee_amount_cents);
        $this->assertSame(2000, (int) $s->company_account_fee_ky_credit_cents);
    }

    /**
     * LA GUARDIA DEL MARCATORE. Una richiesta che non porta i due campi delle
     * leve non deve poterle spegnere: una casella assente e una casella non
     * spuntata arrivano identiche.
     */
    public function test_una_richiesta_senza_i_campi_delle_leve_non_spegne_il_fido(): void
    {
        $this->impostazioni([
            'registration_fee_ky_credit_cents' => 3000,
            'registration_fee_ky_allowance'    => true,
        ]);

        $this->actingAs($this->superAdmin)->post('/admin/quote-iscrizione/impostazioni', [
            'registration_fee_enabled'               => '1',
            'registration_fee_amount'                => '30',
            'registration_fee_bank_transfer_enabled' => '1',
        ])->assertSessionHasNoErrors();

        $s = SystemSetting::userLimitDefaults()->fresh();

        $this->assertTrue((bool) $s->registration_fee_ky_allowance);
        $this->assertSame(3000, (int) $s->registration_fee_ky_credit_cents);
    }

    public function test_dalla_pagina_unica_il_fido_si_puo_davvero_spegnere(): void
    {
        $this->impostazioni(['registration_fee_ky_allowance' => true]);

        $this->actingAs($this->superAdmin)->post('/admin/quote-iscrizione/impostazioni', [
            'registration_fee_form'                  => '1',
            'registration_fee_enabled'               => '1',
            'registration_fee_amount'                => '30',
            'registration_fee_ky_credit'             => '0',
            'registration_fee_bank_transfer_enabled' => '1',
            // 'registration_fee_ky_allowance' assente = casella tolta
        ])->assertSessionHasNoErrors();

        $this->assertFalse(SystemSetting::userLimitDefaults()->fresh()->registrationFeeKyAllowance());
    }

    // ─── 3. La restituzione a chi paga in euro ──────────────────────────────

    public function test_la_restituzione_dei_privati_e_quella_impostata_non_l_importo(): void
    {
        $sistema = $this->contoDiSistema(100000);
        [$utente, $conto] = $this->privatoConQuota(3000);

        // Quota 30,00, restituzione 10,00: non e' un resto, e' una scelta.
        $this->impostazioni(['registration_fee_ky_credit_cents' => 1000]);

        $pagamento = app(RegistrationFeeService::class)
            ->startPayment($utente, RegistrationFeePayment::METHOD_BANK_TRANSFER);
        app(RegistrationFeeService::class)->completeEuroPayment($pagamento);

        $this->assertSame(1000, (int) $conto->fresh()->available_balance);
        $this->assertSame(100000 - 1000, (int) $sistema->fresh()->available_balance);
        $this->assertTrue($pagamento->fresh()->isCompleted());
    }

    public function test_con_la_restituzione_a_zero_non_nasce_nessun_movimento(): void
    {
        $sistema = $this->contoDiSistema(100000);
        [$utente, $conto] = $this->privatoConQuota(3000);

        $this->impostazioni(['registration_fee_ky_credit_cents' => 0]);

        $pagamento = app(RegistrationFeeService::class)
            ->startPayment($utente, RegistrationFeePayment::METHOD_BANK_TRANSFER);
        app(RegistrationFeeService::class)->completeEuroPayment($pagamento);

        $this->assertSame(0, (int) $conto->fresh()->available_balance);
        $this->assertSame(100000, (int) $sistema->fresh()->available_balance);
        $this->assertTrue($pagamento->fresh()->isCompleted());
        $this->assertNull($pagamento->fresh()->transfer_id);
        $this->assertNotNull($utente->fresh()->registration_fee_paid_at);
        $this->assertSame(0, Transfer::where('kind', 'registration_fee_credit')->count());
    }

    /**
     * La leva vale anche dove prima non esisteva: i 480 del codice agente non
     * avevano mai emesso un KY, e adesso possono farlo se l'admin lo decide.
     */
    public function test_anche_la_quota_del_codice_agente_puo_restituire_ky(): void
    {
        $sistema = $this->contoDiSistema(100000);
        [$utente, $conto] = $this->privato();
        $utente->forceFill(['agent_code_fee_due_cents' => 48000])->save();

        $this->impostazioni(['agent_code_fee_ky_credit_cents' => 5000]);

        $pagamento = app(AgentCodeFeeService::class)
            ->startPayment($utente->fresh(), AgentCodeFeePayment::METHOD_BANK_TRANSFER);
        app(AgentCodeFeeService::class)->completeEuroPayment($pagamento);

        $this->assertSame(5000, (int) $conto->fresh()->available_balance);
        $this->assertSame(100000 - 5000, (int) $sistema->fresh()->available_balance);
    }

    // ─── 4. Il fido a chi paga in KY ────────────────────────────────────────

    public function test_col_fido_acceso_la_quota_in_ky_non_mangia_il_fido_che_l_utente_aveva(): void
    {
        $this->contoDiSistema(0);
        [$utente, $conto] = $this->privatoConQuota(3000, fido: 5000);

        $this->impostazioni(['registration_fee_ky_allowance' => true]);

        app(RegistrationFeeService::class)->payWithKy($utente->fresh());

        $this->assertSame(-3000, (int) $conto->fresh()->available_balance);
        $this->assertSame(3000, (int) $utente->fresh()->registration_fee_ky_allowance_cents);
    }

    public function test_col_fido_spento_chi_non_ha_fido_proprio_non_riesce_a_pagare_in_ky(): void
    {
        $this->contoDiSistema(0);
        [$utente, $conto] = $this->privatoConQuota(3000, fido: 0);

        $this->impostazioni(['registration_fee_ky_allowance' => false]);

        $this->expectException(RuntimeException::class);

        try {
            app(RegistrationFeeService::class)->payWithKy($utente->fresh());
        } finally {
            // Il conto non si e' mosso e la quota e' ancora da pagare: il
            // rifiuto e' il comportamento voluto, non un guasto a meta'.
            $this->assertSame(0, (int) $conto->fresh()->available_balance);
            $this->assertNull($utente->fresh()->registration_fee_paid_at);
        }
    }

    // ─── 5. Il trattamento della singola persona ────────────────────────────

    public function test_il_ripiego_del_singolo_utente_vince_sul_pannello(): void
    {
        [$utente] = $this->privato();
        $this->impostazioni(['registration_fee_ky_credit_cents' => 3000]);

        $servizio = app(RegistrationFeeService::class);

        // NULL segue il pannello.
        $this->assertSame(3000, $servizio->kyCreditFor($utente->fresh()));

        $utente->forceFill(['registration_fee_ky_credit_override_cents' => 500])->save();
        $this->assertSame(500, $servizio->kyCreditFor($utente->fresh()));

        // E lo ZERO non e' NULL: e' una decisione presa per questa persona, e
        // resta ferma anche se il pannello cambia.
        $utente->forceFill(['registration_fee_ky_credit_override_cents' => 0])->save();
        $this->impostazioni(['registration_fee_ky_credit_cents' => 9900]);
        $this->assertSame(0, $servizio->kyCreditFor($utente->fresh()));
    }

    public function test_il_trattamento_si_salva_dalla_scheda_utente_su_privati_e_agenti(): void
    {
        [$utente] = $this->privato();

        $this->actingAs($this->superAdmin)
            ->post('/admin/users/' . $utente->id . '/quota-iscrizione/trattamento', [
                'ky_credit'    => '7,50',
                'ky_allowance' => '0',
            ])->assertRedirect('/admin/users/' . $utente->id);

        $this->actingAs($this->superAdmin)
            ->post('/admin/users/' . $utente->id . '/quota-codice-agente/trattamento', [
                'ky_credit'    => '',
                'ky_allowance' => '1',
            ])->assertRedirect('/admin/users/' . $utente->id);

        $fresco = $utente->fresh();

        $this->assertSame(750, (int) $fresco->registration_fee_ky_credit_override_cents);
        $this->assertFalse((bool) $fresco->registration_fee_ky_allowance_override);
        // Campo vuoto = «segui il pannello», e resta NULL.
        $this->assertNull($fresco->agent_code_fee_ky_credit_override_cents);
        $this->assertTrue((bool) $fresco->agent_code_fee_ky_allowance_override);
    }

    /**
     * La quota delle aziende e' l'unica che restringe: «azienda» sono due
     * condizioni insieme, e su un privato il trattamento non ha senso.
     */
    public function test_il_trattamento_della_quota_aziende_non_si_da_a_un_privato(): void
    {
        [$privato] = $this->privato();

        $this->expectException(RuntimeException::class);

        app(CompanyAccountFeeService::class)->setTreatment($privato, $this->superAdmin, 100, true);
    }

    public function test_il_trattamento_di_una_quota_non_tocca_quello_delle_altre(): void
    {
        [$utente] = $this->privato();

        app(RegistrationFeeService::class)->setTreatment($utente, $this->superAdmin, 100, false);

        $fresco = $utente->fresh();

        $this->assertSame(100, (int) $fresco->registration_fee_ky_credit_override_cents);
        $this->assertNull($fresco->agent_code_fee_ky_credit_override_cents);
        $this->assertNull($fresco->agent_code_fee_ky_allowance_override);
        $this->assertNull($fresco->company_account_fee_ky_credit_override_cents);
    }

    // ─── 6. Quel che l'utente legge ─────────────────────────────────────────

    /**
     * La pagina della quota diceva «paghi in euro e ricevi 30 KY» dando per
     * scontato che la restituzione fosse pari all'importo. Adesso non lo e'
     * piu', e promettere KY che non arriveranno e' peggio che non prometterne.
     */
    public function test_la_pagina_della_quota_dice_la_cifra_davvero_impostata(): void
    {
        [$utente] = $this->privatoConQuota(3000);
        $this->impostazioni([
            'registration_fee_enabled'               => true,
            'registration_fee_amount_cents'          => 3000,
            'registration_fee_bank_transfer_enabled' => true,
            'registration_fee_ky_credit_cents'       => 1000,
        ]);

        $this->actingAs($utente->fresh())->get('/quota-iscrizione')
            ->assertOk()
            ->assertSee('10,00 KY', false);
    }

    public function test_con_la_restituzione_a_zero_la_pagina_non_promette_nessun_ky(): void
    {
        [$utente] = $this->privatoConQuota(3000);
        $this->impostazioni([
            'registration_fee_enabled'               => true,
            'registration_fee_amount_cents'          => 3000,
            'registration_fee_bank_transfer_enabled' => true,
            'registration_fee_ky_credit_cents'       => 0,
        ]);

        $this->actingAs($utente->fresh())->get('/quota-iscrizione')
            ->assertOk()
            ->assertDontSee('e in quel caso ricevi', false);
    }

    // ─── Aiutanti ───────────────────────────────────────────────────────────

    /** @param array<string, mixed> $valori */
    private function impostazioni(array $valori): void
    {
        SystemSetting::userLimitDefaults()->forceFill($valori)->save();
    }

    /** @return array{0: User, 1: Account} */
    private function privato(int $saldo = 0, int $fido = 0): array
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
    private function privatoConQuota(int $quota, int $saldo = 0, int $fido = 0): array
    {
        [$user, $account] = $this->privato($saldo, $fido);

        $user->forceFill(['registration_fee_due_cents' => $quota])->save();

        return [$user->fresh(), $account];
    }

    private function contoDiSistema(int $saldo): Account
    {
        $esistente = Account::systemAccount();

        if ($esistente !== null) {
            $esistente->forceFill(['available_balance' => $saldo, 'status' => 'active'])->save();

            return $esistente->fresh();
        }

        $company = Company::create([
            'name'       => 'Circuito KMoney',
            'vat_number' => 'IT' . random_int(10000000000, 99999999999),
            'email'      => 'circuito-' . Str::random(6) . '@test.test',
            'is_active'  => true,
        ]);

        return Account::create([
            'company_id'        => $company->id,
            'owner_type'        => 'company',
            'type'              => 'member',
            'status'            => 'active',
            'available_balance' => $saldo,
            'is_system_account' => true,
        ]);
    }
}
