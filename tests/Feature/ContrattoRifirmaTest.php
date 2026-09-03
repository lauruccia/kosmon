<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\ContractSignature;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * RIFIRMA DEL CONTRATTO DOPO UNA REVISIONE (03/09/2026, decisione di Laura).
 *
 * La domanda che ha aperto il lavoro: "se devo fare delle modifiche al
 * contratto, per errori o revisione delle condizioni, le aziende
 * continueranno a vedere sempre il contratto vecchio errato?". La risposta
 * era sì: `contract_version` saliva ma nessuno confrontava mai la versione
 * firmata con quella in vigore, e nel middleware `if
 * ($user->contract_signed_at) return $next()` lasciava passare chiunque
 * avesse firmato una volta.
 *
 * La regola scelta: lo decide l'admin revisione per revisione, con la spunta
 * "questa revisione richiede una nuova firma". Un refuso non trascina
 * nessuno; una revisione delle condizioni sì.
 *
 * Quello che questi test difendono, in ordine di importanza:
 *   1. senza la spunta NESSUNO viene interpellato (il caso che, sbagliato,
 *      manderebbe 1300 aziende a fare un OTP per una virgola);
 *   2. con la spunta viene interpellato solo chi è rimasto sotto la soglia;
 *   3. la firma vecchia non si perde mai.
 */
class ContrattoRifirmaTest extends TestCase
{
    use RefreshDatabase;

    private function aziendaConUtente(): User
    {
        $company = Company::create([
            'name'       => 'Azienda ' . Str::random(6),
            'slug'       => 'azienda-' . Str::random(6),
            'status'     => 'active',
            // approvata: EnsureOnboardingComplete lascia passare senza
            // chiedere settore e descrizione.
            'kyc_status' => 'approved',
        ]);

        Account::create([
            'company_id'             => $company->id,
            'owner_type'             => 'company',
            'type'                   => 'primary',
            'account_name'           => 'Conto principale ' . $company->name,
            'currency_code'          => 'KY',
            'status'                 => 'active',
            'available_balance'      => 0,
            'pending_balance'        => 0,
            'allow_negative_balance' => false,
        ]);

        $user = User::create([
            'name'                => 'Legale Rappresentante',
            'email'               => 'azienda-' . Str::random(8) . '@test.test',
            'password'            => 'secret123',
            'account_holder_type' => 'company',
            'company_id'          => $company->id,
            'is_active'           => true,
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();

        return $user;
    }

    private function makeAdmin(): User
    {
        $user = User::create([
            'name'                => 'Admin',
            'email'               => 'admin-rifirma-' . Str::random(6) . '@test.test',
            'password'            => 'secret123',
            'account_holder_type' => 'private',
            'company_id'          => null,
            'is_active'           => true,
            'is_super_admin'      => true,
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();

        return $user;
    }

    /** Firma vera, passando dall'OTP come un utente reale. */
    private function firma(User $user): void
    {
        $user->forceFill([
            'contract_otp'            => '123456',
            'contract_otp_expires_at' => now()->addMinutes(10),
        ])->save();

        $this->actingAs($user)
            ->post(route('portal.contract.sign.post'), ['otp' => '123456'])
            ->assertRedirect(route('portal.dashboard'));
    }

    /** L'admin salva il testo scegliendo il tipo di modifica. */
    private function salvaTesto(User $admin, string $modo, ?string $testo = null): void
    {
        $this->actingAs($admin)
            ->post(route('admin.contract-text.update'), [
                'contract_text' => $testo ?? '<p>Condizioni ' . Str::random(6) . '</p>',
                'save_mode'     => $modo,
            ])
            ->assertRedirect();
    }

    private function pubblicaRevisione(User $admin, bool $richiedeFirma): void
    {
        $this->salvaTesto($admin, $richiedeFirma ? 'revisione_rifirma' : 'revisione');
    }

    // ── 1. Il caso pericoloso: la spunta NON messa ───────────────────────

    public function test_una_revisione_senza_spunta_non_interpella_nessuno(): void
    {
        $admin = $this->makeAdmin();
        $user  = $this->aziendaConUtente();
        $this->firma($user);

        $this->pubblicaRevisione($admin, richiedeFirma: false);

        $s = SystemSetting::contractSettings()->fresh();
        $this->assertSame(0, (int) $s->contract_resign_from_version);
        $this->assertFalse($s->resignRequiredFor($user->fresh()));

        // E soprattutto: continua a lavorare senza intoppi.
        $this->actingAs($user->fresh())->get('/dashboard')->assertOk();
    }

    // ── 2. Con la spunta ─────────────────────────────────────────────────

    public function test_una_revisione_con_spunta_riporta_alla_firma_chi_e_rimasto_indietro(): void
    {
        $admin = $this->makeAdmin();
        $user  = $this->aziendaConUtente();
        $this->firma($user);

        $versioneFirmata = (int) $user->fresh()->contract_signed_version;
        $this->assertGreaterThan(0, $versioneFirmata, 'la firma deve registrare la sua versione');

        $this->pubblicaRevisione($admin, richiedeFirma: true);

        $s = SystemSetting::contractSettings()->fresh();
        $this->assertSame($versioneFirmata + 1, (int) $s->contract_resign_from_version);

        $this->actingAs($user->fresh())->get('/dashboard')
            ->assertRedirect(route('portal.contract.sign'));
    }

    public function test_la_pagina_di_firma_spiega_che_le_condizioni_sono_cambiate(): void
    {
        $admin = $this->makeAdmin();
        $user  = $this->aziendaConUtente();
        $this->firma($user);
        $this->pubblicaRevisione($admin, richiedeFirma: true);

        $this->actingAs($user->fresh())->get(route('portal.contract.sign'))
            ->assertOk()
            ->assertSee('condizioni del contratto sono state aggiornate', false)
            ->assertSee('La tua firma precedente resta valida e archiviata', false);
    }

    public function test_chi_ha_gia_firmato_la_versione_nuova_non_viene_ritirato_dentro(): void
    {
        $admin = $this->makeAdmin();
        $this->pubblicaRevisione($admin, richiedeFirma: true);

        // Firma DOPO la revisione: è già in pari, la soglia non lo riguarda.
        $user = $this->aziendaConUtente();
        $this->firma($user);

        $this->assertFalse(
            SystemSetting::contractSettings()->fresh()->resignRequiredFor($user->fresh())
        );
        $this->actingAs($user->fresh())->get('/dashboard')->assertOk();
    }

    // ── 3. La firma vecchia non si perde ────────────────────────────────

    public function test_la_rifirma_si_aggiunge_e_non_sostituisce_la_firma_vecchia(): void
    {
        $admin = $this->makeAdmin();
        $user  = $this->aziendaConUtente();
        $this->firma($user);

        $primaFirma = ContractSignature::where('user_id', $user->id)->firstOrFail();

        $this->pubblicaRevisione($admin, richiedeFirma: true);
        $this->firma($user->fresh());

        $firme = ContractSignature::where('user_id', $user->id)->orderBy('id')->get();
        $this->assertCount(2, $firme, 'la firma vecchia deve restare in archivio');

        // La vecchia è intatta, snapshot compreso.
        $this->assertSame(
            $primaFirma->contract_html_snapshot,
            $firme->first()->contract_html_snapshot
        );
        $this->assertLessThan(
            (int) $firme->last()->contract_version,
            (int) $firme->first()->contract_version
        );

        // E l'utente è tornato operativo.
        $user = $user->fresh();
        $this->assertSame((int) $firme->last()->contract_version, (int) $user->contract_signed_version);
        $this->assertFalse(SystemSetting::contractSettings()->fresh()->resignRequiredFor($user));
        $this->actingAs($user)->get('/dashboard')->assertOk();
    }

    // ── 4. La via d'uscita se la spunta è partita per errore ─────────────

    public function test_l_admin_puo_annullare_una_rifirma_partita_per_errore(): void
    {
        $admin = $this->makeAdmin();
        $user  = $this->aziendaConUtente();
        $this->firma($user);
        $this->pubblicaRevisione($admin, richiedeFirma: true);

        $this->actingAs($user->fresh())->get('/dashboard')
            ->assertRedirect(route('portal.contract.sign'));

        $this->actingAs($admin)
            ->post(route('admin.contract-resign.cancel'))
            ->assertRedirect();

        $this->assertSame(0, (int) SystemSetting::contractSettings()->fresh()->contract_resign_from_version);
        $this->actingAs($user->fresh())->get('/dashboard')->assertOk();
    }

    // ── 5. La CORREZIONE: si vede anche su chi ha già firmato ────────────

    public function test_una_correzione_non_alza_la_versione_e_non_chiede_firme(): void
    {
        $admin = $this->makeAdmin();
        $user  = $this->aziendaConUtente();
        $this->firma($user);

        $versionePrima = (int) SystemSetting::contractSettings()->fresh()->contract_version;

        $this->salvaTesto($admin, 'correzione', '<p>Testo senza refusi</p>');

        $s = SystemSetting::contractSettings()->fresh();
        $this->assertSame($versionePrima, (int) $s->contract_version, 'una correzione non alza la versione');
        $this->assertNotNull($s->contract_text_corrected_at);
        $this->assertFalse($s->resignRequiredFor($user->fresh()));

        // Nessun intoppo per l'azienda.
        $this->actingAs($user->fresh())->get('/dashboard')->assertOk();
    }

    public function test_dopo_una_correzione_l_azienda_vede_il_testo_corretto_non_il_refuso(): void
    {
        $admin = $this->makeAdmin();

        $this->salvaTesto($admin, 'revisione', '<p>Condizione con REFUSOSBAGLIATO dentro</p>');

        $user = $this->aziendaConUtente();
        $this->firma($user);

        // Firmato col refuso: nello snapshot c'è, ed è giusto che ci resti.
        $sig = ContractSignature::where('user_id', $user->id)->firstOrFail();
        $this->assertStringContainsString('REFUSOSBAGLIATO', $sig->contract_html_snapshot);

        $this->salvaTesto($admin, 'correzione', '<p>Condizione con TESTOCORRETTO dentro</p>');

        // La pagina mostra il corretto, non il refuso, e spiega perché.
        $this->actingAs($user->fresh())->get(route('portal.contract.view'))
            ->assertOk()
            ->assertSee('TESTOCORRETTO', false)
            ->assertDontSee('REFUSOSBAGLIATO', false)
            ->assertSee('è stato corretto il', false);

        // Lo snapshot in banca dati NON è stato riscritto: la prova resta.
        $this->assertStringContainsString(
            'REFUSOSBAGLIATO',
            ContractSignature::where('user_id', $user->id)->firstOrFail()->contract_html_snapshot
        );
    }

    public function test_la_correzione_non_tocca_chi_aveva_firmato_una_versione_precedente(): void
    {
        $admin = $this->makeAdmin();

        // Firma la v1.
        $this->salvaTesto($admin, 'revisione', '<p>Vecchie condizioni VERSIONEVECCHIA</p>');
        $user = $this->aziendaConUtente();
        $this->firma($user);

        // Escono condizioni nuove (v2) e poi una correzione sulla v2.
        $this->salvaTesto($admin, 'revisione', '<p>Nuove condizioni</p>');
        $this->salvaTesto($admin, 'correzione', '<p>Nuove condizioni corrette</p>');

        // Lui ha firmato la vecchia: deve continuare a vedere QUELLA.
        $this->actingAs($user->fresh())->get(route('portal.contract.view'))
            ->assertOk()
            ->assertSee('VERSIONEVECCHIA', false)
            ->assertDontSee('Nuove condizioni', false);
    }

    /**
     * Il PDF e' l'unica copia del contratto che esce dal portale, quindi
     * dopo una correzione deve (a) generarsi ancora, e (b) portare scritto
     * che il testo non e' piu' identico allo snapshot firmato. Senza la
     * seconda cosa in giro finirebbe un documento che non combacia con la
     * prova e non spiega perche'.
     */
    public function test_il_pdf_si_genera_anche_dopo_una_correzione(): void
    {
        $admin = $this->makeAdmin();

        $this->salvaTesto($admin, 'revisione', '<p>Condizione con REFUSOSBAGLIATO dentro</p>');
        $user = $this->aziendaConUtente();
        $this->firma($user);

        $this->salvaTesto($admin, 'correzione', '<p>Condizione con TESTOCORRETTO dentro</p>');

        $risposta = $this->actingAs($user->fresh())->get(route('portal.contract.download'));
        $risposta->assertOk();
        $this->assertStringContainsString('pdf', (string) $risposta->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF', (string) $risposta->getContent());
    }

    // ── 6. La pagina admin regge ─────────────────────────────────────────

    public function test_la_pagina_admin_mostra_la_rifirma_in_corso(): void
    {
        $admin = $this->makeAdmin();
        $user  = $this->aziendaConUtente();
        $this->firma($user);
        $this->pubblicaRevisione($admin, richiedeFirma: true);

        $this->actingAs($admin)->get(route('admin.contract-settings'))
            ->assertOk()
            ->assertSee('Rifirma richiesta dalla versione', false)
            ->assertSee('Annulla la rifirma', false);
    }
}
