<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\ContractSignature;
use App\Models\User;
use App\Notifications\ContractOtpNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Le vie d'uscita dalla pagina di firma del contratto (08/09/2026).
 *
 * Il problema, segnalato da Laura: chi si registra e non riceve la mail con
 * l'OTP della firma resta chiuso dentro. `EnsureContractSigned` gli rimbalza
 * sopra la pagina di firma ogni rotta del portale; quella pagina non aveva un
 * logout; e la pagina che corregge l'indirizzo email stava dietro allo stesso
 * cancello — quindi chi aveva sbagliato a digitare l'email non poteva
 * correggerla proprio perche' l'aveva sbagliata.
 *
 * Questi test sorvegliano le uscite. Se qualcuno domani rimette il cambio
 * email dentro il gruppo del portale, o toglie il logout dalla topbar, o fa
 * tornare il campo dell'OTP a dipendere da un flash di sessione, qui diventa
 * rosso.
 */
class ContractSignatureEscapeHatchTest extends TestCase
{
    use RefreshDatabase;

    /** Utente aziendale con KYC approvato ed email verificata, ma contratto da firmare. */
    private function utenteDaFirmare(): User
    {
        $slug = 'escape-' . Str::random(6);

        $company = Company::create([
            'name'          => 'Escape Co',
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
            'name'                => 'Utente Bloccato',
            'email'               => 'bloccato-' . Str::random(8) . '@test.test',
            'password'            => 'secret123',
            'role'                => 'company-manager',
            'is_active'           => true,
            'is_super_admin'      => false,
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();

        Account::create([
            'company_id'        => $company->id,
            'owner_user_id'     => $user->id,
            'owner_type'        => 'company',
            'type'              => 'primary',
            'account_name'      => 'Conto Escape',
            'currency_code'     => 'KY',
            'status'            => 'active',
            'available_balance' => 0,
        ]);

        return $user->fresh();
    }

    private function admin(): User
    {
        return User::create([
            'name'                => 'Admin Soccorso',
            'email'               => 'admin-' . Str::random(8) . '@test.test',
            'password'            => 'secret123',
            'account_holder_type' => 'private',
            'company_id'          => null,
            'is_active'           => true,
            'is_super_admin'      => true,
            'email_verified_at'   => now(),
        ]);
    }

    // ── Le due uscite dalla pagina di firma ─────────────────────────────────

    public function test_la_pagina_di_firma_offre_il_logout_e_il_cambio_email(): void
    {
        $user = $this->utenteDaFirmare();

        $this->actingAs($user)
            ->get(route('portal.contract.sign'))
            ->assertOk()
            ->assertSee(route('logout'), false)
            ->assertSee(route('portal.email-change'), false);
    }

    public function test_utente_fermo_alla_firma_raggiunge_il_cambio_email(): void
    {
        $user = $this->utenteDaFirmare();

        // Prima di oggi questo era un redirect su portal.contract.sign.
        $this->actingAs($user)
            ->get(route('portal.email-change'))
            ->assertOk()
            ->assertSee('Cambia email');
    }

    public function test_utente_con_email_non_verificata_raggiunge_il_cambio_email(): void
    {
        $user = $this->utenteDaFirmare();
        $user->forceFill(['email_verified_at' => null])->save();

        // E' il caso che conta davvero: l'indirizzo e' sbagliato, quindi il
        // link di verifica non arrivera' mai. Se questa pagina restasse dietro
        // `verified` non ci sarebbe nessun modo di uscirne.
        $this->actingAs($user->fresh())
            ->get(route('portal.email-change'))
            ->assertOk()
            ->assertSee(route('logout'), false);
    }

    public function test_la_pagina_di_verifica_email_offre_il_link_di_correzione(): void
    {
        $user = $this->utenteDaFirmare();
        $user->forceFill(['email_verified_at' => null])->save();

        $this->actingAs($user->fresh())
            ->get(route('verification.notice'))
            ->assertOk()
            ->assertSee(route('portal.email-change'), false);
    }

    // ── L'OTP non deve sparire al primo ricarica ────────────────────────────

    public function test_il_campo_otp_sopravvive_al_ricarica_della_pagina(): void
    {
        Notification::fake();

        $user = $this->utenteDaFirmare();

        $this->actingAs($user)
            ->post(route('portal.contract.send-otp'))
            ->assertRedirect(route('portal.contract.sign'));

        Notification::assertSentTo($user, ContractOtpNotification::class);
        $this->assertNotNull($user->fresh()->contract_otp);

        // Primo GET: consuma il flash 'otp_sent'.
        $this->actingAs($user)->get(route('portal.contract.sign'))->assertOk();

        // Secondo GET: e' il ricarica. Il campo del codice deve esserci ancora,
        // perche' in banca dati c'e' un OTP valido per un altro quarto d'ora.
        $this->actingAs($user)
            ->get(route('portal.contract.sign'))
            ->assertOk()
            ->assertSee('name="otp"', false)
            ->assertDontSee('Invia codice OTP e firma');
    }

    public function test_dopo_un_ricarica_il_codice_gia_ricevuto_firma_ancora(): void
    {
        Notification::fake();

        $user = $this->utenteDaFirmare();
        $this->actingAs($user)->post(route('portal.contract.send-otp'));

        $otp = $user->fresh()->contract_otp;

        $this->actingAs($user)->get(route('portal.contract.sign'));
        $this->actingAs($user)->get(route('portal.contract.sign'));

        $this->actingAs($user)
            ->post(route('portal.contract.sign.post'), ['otp' => $otp])
            ->assertRedirect(route('portal.dashboard'));

        $this->assertNotNull($user->fresh()->contract_signed_at);
    }

    public function test_otp_scaduto_riporta_la_pagina_alla_richiesta(): void
    {
        $user = $this->utenteDaFirmare();
        $user->forceFill([
            'contract_otp'            => '123456',
            'contract_otp_expires_at' => now()->subMinute(),
        ])->save();

        $this->actingAs($user->fresh())
            ->get(route('portal.contract.sign'))
            ->assertOk()
            ->assertSee('Invia codice OTP e firma');
    }

    // ── Il soccorso lato back-office ────────────────────────────────────────

    public function test_admin_puo_rimandare_il_codice_di_firma(): void
    {
        Notification::fake();

        $user = $this->utenteDaFirmare();

        $this->actingAs($this->admin())
            ->post(route('admin.users.contract-otp', $user))
            ->assertRedirect();

        Notification::assertSentTo($user, ContractOtpNotification::class);

        $fresh = $user->fresh();
        $this->assertNotNull($fresh->contract_otp);
        $this->assertTrue($fresh->contract_otp_expires_at->isFuture());
    }

    public function test_la_firma_assistita_pretende_un_motivo(): void
    {
        $user = $this->utenteDaFirmare();

        $this->actingAs($this->admin())
            ->post(route('admin.users.contract-assisted', $user), ['motivo' => 'corto'])
            ->assertSessionHasErrors('motivo');

        $this->assertNull($user->fresh()->contract_signed_at);
    }

    public function test_la_firma_assistita_resta_dichiarata_nel_registro(): void
    {
        $admin = $this->admin();
        $user  = $this->utenteDaFirmare();

        $this->actingAs($admin)
            ->post(route('admin.users.contract-assisted', $user), [
                'motivo' => 'Firma raccolta per telefono: la casella dell\'utente rifiuta le nostre mail.',
            ])
            ->assertRedirect();

        $this->assertNotNull($user->fresh()->contract_signed_at);

        $firma = ContractSignature::where('user_id', $user->id)->latest('signed_at')->first();
        $this->assertNotNull($firma);

        // Il punto: chi legge il registro fra un anno deve capire da solo che
        // questa firma non l'ha messa l'utente.
        $this->assertStringContainsString('Firma assistita', (string) $firma->user_agent);
        $this->assertStringContainsString($admin->email, (string) $firma->user_agent);
    }
}
