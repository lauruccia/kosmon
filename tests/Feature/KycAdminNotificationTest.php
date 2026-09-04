<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\KycDocument;
use App\Models\User;
use App\Notifications\KycSubmittedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Il buco trovato il 04/09/2026: un'azienda caricava i documenti, passava in
 * "under_review" e restava ferma sulla schermata di attesa senza che nessun
 * admin ricevesse niente. Il commento nel codice prometteva la notifica, la
 * riga non c'era. Questi test sorvegliano i due percorsi di upload (wizard
 * /benvenuto e pagina /kyc del portale), il fatto che la notifica NON si
 * ripeta a ogni documento, e il contatore sulla dashboard admin.
 */
class KycAdminNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function makeAdmin(bool $active = true): User
    {
        $user = User::create([
            'name'                => 'Admin KYC',
            'email'               => 'admin-kycnotif-' . Str::random(6) . '@test.test',
            'password'            => 'secret123',
            'account_holder_type' => 'private',
            'company_id'          => null,
            'is_active'           => $active,
            'is_super_admin'      => true,
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();

        return $user;
    }

    /** @return array{0: User, 1: Company} */
    private function makeCompanyWithUser(string $kycStatus = 'pending'): array
    {
        $slug = 'kycnotif-' . Str::random(4);

        $company = Company::create([
            'name'          => 'Azienda Da Verificare',
            'slug'          => $slug,
            'email'         => $slug . '@test.test',
            'vat_number'    => (string) random_int(10000000000, 99999999999),
            'status'        => 'active',
            'kyc_status'    => $kycStatus,
            'currency_code' => 'KY',
            'sector'        => 'informatica',
            'description'   => 'Azienda di prova per la verifica KYC',
        ]);

        $user = User::create([
            'company_id'          => $company->id,
            'account_holder_type' => 'company',
            'name'                => 'Titolare Azienda',
            'email'               => 'kycnotif-user-' . Str::random(6) . '@test.test',
            'password'            => 'secret123',
            'role'                => 'company-manager',
            'is_active'           => true,
            'is_super_admin'      => false,
        ]);
        $user->forceFill([
            'email_verified_at'  => now(),
            'contract_signed_at' => now(),
        ])->save();

        Account::create([
            'company_id'        => $company->id,
            'owner_user_id'     => $user->id,
            'owner_type'        => 'company',
            'type'              => 'primary',
            'account_name'      => 'Conto ' . $company->name,
            'currency_code'     => 'KY',
            'status'            => 'active',
            'available_balance' => 0,
        ]);

        return [$user, $company];
    }

    private function fakeDocument(): UploadedFile
    {
        return UploadedFile::fake()->create('visura.pdf', 120, 'application/pdf');
    }

    // ── Wizard /benvenuto ─────────────────────────────────────────────────────

    public function test_upload_dal_wizard_avvisa_gli_admin(): void
    {
        Storage::fake('local');
        Notification::fake();

        $admin = $this->makeAdmin();
        [$user, $company] = $this->makeCompanyWithUser('pending');

        $this->actingAs($user)
            ->post(route('onboarding.step2.upload'), [
                'type'     => 'visura_camerale',
                'document' => $this->fakeDocument(),
            ])
            ->assertRedirect(route('onboarding.step2'));

        $this->assertSame('under_review', $company->fresh()->kyc_status);

        Notification::assertSentTo(
            $admin,
            KycSubmittedNotification::class,
            fn (KycSubmittedNotification $n) => $n->company->is($company)
        );
    }

    public function test_secondo_documento_dal_wizard_non_riavvisa_gli_admin(): void
    {
        Storage::fake('local');

        $admin = $this->makeAdmin();
        // Azienda gia' in revisione: un documento e' gia' passato di qui.
        [$user, $company] = $this->makeCompanyWithUser('under_review');

        KycDocument::create([
            'company_id'          => $company->id,
            'uploaded_by_user_id' => $user->id,
            'type'                => 'visura_camerale',
            'file_path'           => 'kyc/fake/primo.pdf',
            'original_name'       => 'primo.pdf',
            'mime_type'           => 'application/pdf',
            'file_size'           => 1000,
            'status'              => 'pending',
        ]);

        Notification::fake();

        $this->actingAs($user)
            ->post(route('onboarding.step2.upload'), [
                'type'     => 'statuto',
                'document' => $this->fakeDocument(),
            ])
            ->assertRedirect(route('onboarding.step2'));

        Notification::assertNothingSentTo($admin);
    }

    // ── Pagina /kyc del portale ───────────────────────────────────────────────

    /**
     * Nota trovata scrivendo questi test: per un'azienda ancora da verificare
     * la rotta /kyc/upload NON e' raggiungibile. Sta nel gruppo del portale,
     * che ha il middleware `onboarding`, e quel middleware rimanda al wizard
     * chiunque non sia gia' approvato. Quindi il ramo pending -> under_review
     * dentro KycController::upload() e' di fatto irraggiungibile dal portale:
     * l'aggancio agli admin sta anche li' per simmetria e per il giorno in cui
     * la catena middleware cambia, ma il percorso vero e' il wizard.
     */
    public function test_upload_dal_portale_e_intercettato_dal_wizard(): void
    {
        Storage::fake('local');
        Notification::fake();

        $this->makeAdmin();
        [$user, $company] = $this->makeCompanyWithUser('pending');

        $this->actingAs($user)
            ->post(route('portal.kyc.upload'), [
                'type'     => 'visura_camerale',
                'document' => $this->fakeDocument(),
            ])
            ->assertRedirect();

        $this->assertSame('pending', $company->fresh()->kyc_status);
        $this->assertSame(0, $company->kycDocuments()->count());
    }

    /**
     * L'helper condiviso, provato da solo: e' il punto in cui entrambi i
     * controller confluiscono.
     */
    public function test_helper_notifica_tutti_gli_admin_attivi(): void
    {
        Notification::fake();

        $primo   = $this->makeAdmin();
        $secondo = $this->makeAdmin();
        [, $company] = $this->makeCompanyWithUser('under_review');

        \App\Notifications\Concerns\NotifiesAdmins::notifyAdminsOfKycSubmission($company);

        Notification::assertSentTo([$primo, $secondo], KycSubmittedNotification::class);
        Notification::assertSentTimes(KycSubmittedNotification::class, 2);
    }

    // ── Destinatari ───────────────────────────────────────────────────────────

    public function test_solo_gli_admin_attivi_ricevono_la_notifica(): void
    {
        Storage::fake('local');
        Notification::fake();

        $adminAttivo      = $this->makeAdmin();
        $adminDisattivato = $this->makeAdmin(active: false);

        [$user] = $this->makeCompanyWithUser('pending');
        [$altroTitolare] = $this->makeCompanyWithUser('pending');

        $this->actingAs($user)
            ->post(route('onboarding.step2.upload'), [
                'type'     => 'visura_camerale',
                'document' => $this->fakeDocument(),
            ]);

        Notification::assertSentTo($adminAttivo, KycSubmittedNotification::class);
        Notification::assertNotSentTo($adminDisattivato, KycSubmittedNotification::class);
        Notification::assertNotSentTo($altroTitolare, KycSubmittedNotification::class);
    }

    // ── Non bloccante ─────────────────────────────────────────────────────────

    public function test_se_la_notifica_esplode_il_documento_resta_caricato(): void
    {
        Storage::fake('local');

        $this->makeAdmin();
        [$user, $company] = $this->makeCompanyWithUser('pending');

        // Posta irraggiungibile: il dispatcher solleva un'eccezione.
        $dispatcher = \Mockery::mock(\Illuminate\Contracts\Notifications\Dispatcher::class);
        $dispatcher->shouldReceive('send')->andThrow(new \RuntimeException('SMTP giu'));
        $dispatcher->shouldReceive('sendNow')->andThrow(new \RuntimeException('SMTP giu'));
        $this->app->instance(\Illuminate\Contracts\Notifications\Dispatcher::class, $dispatcher);

        $this->actingAs($user)
            ->post(route('onboarding.step2.upload'), [
                'type'     => 'visura_camerale',
                'document' => $this->fakeDocument(),
            ])
            ->assertRedirect(route('onboarding.step2'));

        $this->assertSame('under_review', $company->fresh()->kyc_status);
        $this->assertSame(1, $company->kycDocuments()->count());
    }

    // ── Contatore sulla dashboard admin ───────────────────────────────────────

    public function test_la_dashboard_admin_mostra_le_aziende_in_attesa(): void
    {
        $admin = $this->makeAdmin();
        $this->makeCompanyWithUser('under_review');
        $this->makeCompanyWithUser('under_review');
        $this->makeCompanyWithUser('approved');

        $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('2 in attesa');
    }

    public function test_la_dashboard_admin_non_mostra_il_badge_a_coda_vuota(): void
    {
        $admin = $this->makeAdmin();
        $this->makeCompanyWithUser('approved');

        $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertDontSee('in attesa</span>', false);
    }
}
