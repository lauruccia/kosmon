<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\OAuthAuthorizationCode;
use App\Models\User;
use App\Services\OAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * "Accedi con KMoney" — la schermata di consenso (fase 1 di PIANO_SHOP_ESTERNO.md).
 *
 * Qui si verifica la metà "davanti" del flusso: quella con il browser e la
 * persona che decide. La metà "dietro" — scambio del codice, token, rinnovi —
 * sta in OAuthTokenEndpointTest.
 *
 * Le regole che questi test tengono ferme, e che sono il motivo per cui questo
 * codice è scritto in casa invece che preso da una libreria:
 *
 *  - finché client_id e redirect_uri non sono verificati NON si rimanda niente
 *    a nessuno (altrimenti la pagina diventa un trampolino per gli attacchi);
 *  - il confronto della redirect_uri è per stringa intera, mai per prefisso;
 *  - PKCE S256 è obbligatorio già in questa fase;
 *  - il consenso non si può saltare, e sta dietro alla stessa catena di
 *    controlli del portale: azienda sospesa o contratto non firmato = niente
 *    accesso all'applicazione collegata.
 */
class OAuthAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private const CLIENT_ID     = 'kshop-test-client';
    private const CLIENT_SECRET = 'segreto-di-prova-molto-lungo';
    private const REDIRECT      = 'https://kosmoshop.test/oauth/callback';
    private const VERIFIER      = 'verificatore-di-prova-lungo-abbastanza-per-pkce';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('oauth.clients.kshop', [
            'name'          => 'Kosmoshop',
            'client_id'     => self::CLIENT_ID,
            'secret'        => self::CLIENT_SECRET,
            'redirect_uris' => [self::REDIRECT],
            'scopes'        => ['profile', 'account.read', 'orders.write', 'mandate'],
        ]);
    }

    // =========================================================================
    // 1. Prima di ogni cosa: chi chiede, e dove vuole tornare
    // =========================================================================

    public function test_utente_non_autenticato_viene_mandato_al_login(): void
    {
        $this->get($this->authorizeUrl())->assertRedirect(route('login'));
    }

    public function test_client_sconosciuto_non_riceve_nessun_redirect(): void
    {
        $this->actingAs($this->privateUser())
            ->get($this->authorizeUrl(['client_id' => 'applicazione-mai-vista']))
            ->assertStatus(400)
            ->assertSee('Applicazione sconosciuta');
    }

    public function test_redirect_uri_fuori_lista_non_riceve_nessun_redirect(): void
    {
        $this->actingAs($this->privateUser())
            ->get($this->authorizeUrl(['redirect_uri' => 'https://sito-dell-attaccante.test/callback']))
            ->assertStatus(400)
            ->assertSee('Indirizzo di ritorno non autorizzato');
    }

    /**
     * Il confronto è per stringa INTERA: un indirizzo che comincia come quello
     * autorizzato non è quello autorizzato.
     */
    public function test_redirect_uri_che_estende_quella_autorizzata_viene_respinta(): void
    {
        $this->actingAs($this->privateUser())
            ->get($this->authorizeUrl(['redirect_uri' => self::REDIRECT . '.attaccante.test']))
            ->assertStatus(400)
            ->assertSee('Indirizzo di ritorno non autorizzato');

        $this->actingAs($this->privateUser())
            ->get($this->authorizeUrl(['redirect_uri' => self::REDIRECT . '/../../altrove']))
            ->assertStatus(400);
    }

    // =========================================================================
    // 2. Parametri del flusso
    // =========================================================================

    public function test_senza_state_la_richiesta_torna_indietro_con_un_errore(): void
    {
        $response = $this->actingAs($this->privateUser())
            ->get($this->authorizeUrl(['state' => '']));

        $response->assertRedirectContains('error=invalid_request');
    }

    public function test_response_type_diverso_da_code_non_e_supportato(): void
    {
        $this->actingAs($this->privateUser())
            ->get($this->authorizeUrl(['response_type' => 'token']))
            ->assertRedirectContains('error=unsupported_response_type');
    }

    public function test_pkce_e_obbligatorio(): void
    {
        $this->actingAs($this->privateUser())
            ->get($this->authorizeUrl(['code_challenge' => '']))
            ->assertRedirectContains('error=invalid_request');
    }

    public function test_pkce_in_chiaro_non_e_accettato(): void
    {
        $this->actingAs($this->privateUser())
            ->get($this->authorizeUrl(['code_challenge_method' => 'plain']))
            ->assertRedirectContains('error=invalid_request');
    }

    public function test_scope_inesistente_viene_rifiutato(): void
    {
        $this->actingAs($this->privateUser())
            ->get($this->authorizeUrl(['scope' => 'profile scrivi.ovunque']))
            ->assertRedirectContains('error=invalid_scope');
    }

    public function test_scope_non_concesso_al_client_viene_rifiutato(): void
    {
        config()->set('oauth.clients.kshop.scopes', ['profile']);

        $this->actingAs($this->privateUser())
            ->get($this->authorizeUrl(['scope' => 'profile mandate']))
            ->assertRedirectContains('error=invalid_scope');
    }

    // =========================================================================
    // 3. La schermata
    // =========================================================================

    public function test_la_pagina_di_consenso_dice_chi_chiede_e_cosa_chiede(): void
    {
        $this->actingAs($this->privateUser())
            ->get($this->authorizeUrl())
            ->assertOk()
            ->assertSee('Kosmoshop')
            ->assertSee('Sapere chi sei: nome, cognome ed email')
            ->assertSee('Vedere il tuo numero di conto KY e se puoi comprare o vendere');
    }

    public function test_visitare_la_pagina_non_emette_nessun_codice(): void
    {
        $this->actingAs($this->privateUser())->get($this->authorizeUrl())->assertOk();

        // Il codice nasce solo quando l'utente preme "Consenti": guardare la
        // schermata non autorizza niente.
        $this->assertSame(0, OAuthAuthorizationCode::count());
    }

    // =========================================================================
    // 4. Il consenso
    // =========================================================================

    public function test_consenso_riporta_al_client_con_codice_e_state(): void
    {
        $user = $this->privateUser();

        $this->actingAs($user)->get($this->authorizeUrl());

        $response = $this->actingAs($user)->post(route('oauth.authorize.approve'));

        $location = $response->headers->get('Location');

        $this->assertStringStartsWith(self::REDIRECT . '?', (string) $location);
        $this->assertStringContainsString('state=stato-di-prova', (string) $location);

        parse_str((string) parse_url((string) $location, PHP_URL_QUERY), $query);
        $this->assertArrayHasKey('code', $query);
        $this->assertNotEmpty($query['code']);
    }

    public function test_del_codice_resta_in_tabella_solo_l_impronta(): void
    {
        $user = $this->privateUser();

        $this->actingAs($user)->get($this->authorizeUrl());
        $response = $this->actingAs($user)->post(route('oauth.authorize.approve'));

        parse_str((string) parse_url((string) $response->headers->get('Location'), PHP_URL_QUERY), $query);

        $record = OAuthAuthorizationCode::sole();

        $this->assertSame(hash('sha256', $query['code']), $record->code_hash);

        // Il codice in chiaro non deve comparire da nessuna parte nella riga.
        $this->assertStringNotContainsString($query['code'], json_encode($record->toArray()));
    }

    public function test_il_codice_scade_dopo_sessanta_secondi(): void
    {
        $user = $this->privateUser();

        $this->actingAs($user)->get($this->authorizeUrl());
        $this->actingAs($user)->post(route('oauth.authorize.approve'));

        $record = OAuthAuthorizationCode::sole();

        $this->assertEqualsWithDelta(60, $record->created_at->diffInSeconds($record->expires_at), 2);
    }

    public function test_il_consenso_lascia_traccia_in_audit_log(): void
    {
        $user = $this->privateUser();

        $this->actingAs($user)->get($this->authorizeUrl());
        $this->actingAs($user)->post(route('oauth.authorize.approve'));

        $this->assertTrue(
            AuditLog::query()
                ->where('event', 'oauth.consent_granted')
                ->where('actor_user_id', $user->id)
                ->exists(),
            'Ogni consenso deve lasciare una traccia in AuditLog.'
        );
    }

    public function test_rifiuto_torna_al_client_senza_emettere_codici(): void
    {
        $user = $this->privateUser();

        $this->actingAs($user)->get($this->authorizeUrl());

        $response = $this->actingAs($user)->delete(route('oauth.authorize.deny'));

        $location = (string) $response->headers->get('Location');

        $this->assertStringContainsString('error=access_denied', $location);
        $this->assertStringContainsString('state=stato-di-prova', $location);
        $this->assertSame(0, OAuthAuthorizationCode::count());

        $this->assertTrue(
            AuditLog::query()->where('event', 'oauth.consent_denied')->exists()
        );
    }

    public function test_approvare_senza_una_richiesta_in_corso_non_emette_niente(): void
    {
        // Nessun GET /oauth/authorize prima: non c'è nulla da approvare.
        $this->actingAs($this->privateUser())
            ->post(route('oauth.authorize.approve'))
            ->assertRedirect(route('portal.dashboard'));

        $this->assertSame(0, OAuthAuthorizationCode::count());
    }

    public function test_la_richiesta_in_sessione_e_di_chi_l_ha_iniziata(): void
    {
        $primo    = $this->privateUser();
        $secondo  = $this->privateUser();

        // Il primo utente apre la richiesta...
        $this->actingAs($primo)->get($this->authorizeUrl());

        // ...e il secondo prova a confermarla con la stessa sessione.
        $this->actingAs($secondo)
            ->post(route('oauth.authorize.approve'))
            ->assertRedirect(route('portal.dashboard'));

        $this->assertSame(0, OAuthAuthorizationCode::count());
    }

    public function test_la_richiesta_in_attesa_scade(): void
    {
        $user = $this->privateUser();

        $this->actingAs($user)->get($this->authorizeUrl());

        $this->travel(11)->minutes();

        $this->actingAs($user)
            ->post(route('oauth.authorize.approve'))
            ->assertRedirect(route('portal.dashboard'));

        $this->assertSame(0, OAuthAuthorizationCode::count());
    }

    // =========================================================================
    // 5. La catena di controlli del portale vale anche qui
    // =========================================================================

    public function test_azienda_sospesa_non_arriva_al_consenso(): void
    {
        [$user] = $this->companyUser(sospesa: true);

        $this->actingAs($user)
            ->get($this->authorizeUrl())
            ->assertRedirect(route('login'));

        $this->assertSame(0, OAuthAuthorizationCode::count());
    }

    public function test_contratto_non_firmato_non_arriva_al_consenso(): void
    {
        // Il contratto di adesione riguarda gli utenti aziendali.
        [$user] = $this->companyUser();
        $user->forceFill(['contract_signed_at' => null])->save();

        $this->actingAs($user)
            ->get($this->authorizeUrl())
            ->assertRedirect(route('portal.contract.sign'));

        $this->assertSame(0, OAuthAuthorizationCode::count());
    }

    public function test_email_non_verificata_non_arriva_al_consenso(): void
    {
        $user = $this->privateUser();
        $user->forceFill(['email_verified_at' => null])->save();

        $this->actingAs($user)
            ->get($this->authorizeUrl())
            ->assertRedirect(route('verification.notice'));
    }

    // =========================================================================
    // Helper
    // =========================================================================

    /**
     * @param array<string, string> $overrides
     */
    private function authorizeUrl(array $overrides = []): string
    {
        $params = array_merge([
            'response_type'         => 'code',
            'client_id'             => self::CLIENT_ID,
            'redirect_uri'          => self::REDIRECT,
            'scope'                 => 'profile account.read',
            'state'                 => 'stato-di-prova',
            'code_challenge'        => app(OAuthService::class)->pkceChallengeFor(self::VERIFIER),
            'code_challenge_method' => 'S256',
        ], $overrides);

        return route('oauth.authorize', array_filter($params, static fn ($v) => $v !== ''));
    }

    private function privateUser(): User
    {
        $user = User::create([
            'name'                => 'Mario Rossi',
            'email'               => 'utente-' . Str::random(8) . '@test.test',
            'password'            => 'secret123',
            'account_holder_type' => 'private',
            'company_id'          => null,
            'role'                => 'private-owner',
            'is_active'           => true,
            'is_super_admin'      => false,
            'email_verified_at'   => now(),
            'contract_signed_at'  => now(),
        ]);

        Account::create([
            'owner_user_id'     => $user->id,
            'owner_type'        => 'private',
            'type'              => 'member',
            'status'            => 'active',
            'available_balance' => 50000,
        ]);

        return $user->fresh();
    }

    /**
     * @return array{0: User, 1: Company, 2: Account}
     */
    private function companyUser(bool $sospesa = false): array
    {
        $slug = 'azienda-' . Str::random(6);

        $company = Company::create([
            'name'          => 'Azienda ' . Str::random(4),
            'slug'          => $slug,
            'email'         => $slug . '@test.test',
            'status'        => 'active',
            'kyc_status'    => 'approved',
            'currency_code' => 'KY',
            'sector'        => 'informatica',
            'description'   => 'Azienda di test',
            'suspended_at'  => $sospesa ? now() : null,
        ]);

        $account = Account::create([
            'company_id'        => $company->id,
            'owner_type'        => 'company',
            'type'              => 'member',
            'status'            => 'active',
            'available_balance' => 100000,
        ]);

        $user = User::create([
            'name'                => 'Titolare ' . $company->name,
            'email'               => 'titolare-' . Str::random(8) . '@test.test',
            'password'            => 'secret123',
            'account_holder_type' => 'company',
            'company_id'          => $company->id,
            'role'                => 'owner',
            'is_active'           => true,
            'is_super_admin'      => false,
            'email_verified_at'   => now(),
            'contract_signed_at'  => now(),
        ]);

        $account->forceFill(['owner_user_id' => $user->id])->save();

        return [$user->fresh(), $company->fresh(), $account->fresh()];
    }
}
