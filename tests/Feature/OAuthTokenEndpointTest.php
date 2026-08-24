<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\OAuthAccessToken;
use App\Models\OAuthAuthorizationCode;
use App\Models\User;
use App\Services\OAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * "Accedi con KMoney" — la metà "dietro" del flusso: scambio del codice,
 * token, rinnovi, revoche e l'endpoint /userinfo.
 *
 * Qui non c'è nessun browser: parla solo il server dell'applicazione collegata,
 * che si autentica col proprio segreto. Sono i test che tengono ferme le regole
 * di sicurezza scritte in cima a OAuthService: codice monouso, PKCE verificato,
 * redirect_uri identica, rotazione dei refresh e — la più importante — il
 * riuso di un codice o di un refresh già speso che spegne tutta la catena.
 */
class OAuthTokenEndpointTest extends TestCase
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
    // 1. Lo scambio che funziona
    // =========================================================================

    public function test_il_codice_diventa_una_coppia_di_token(): void
    {
        $code = $this->consentAndGetCode();

        $response = $this->postJson(route('api.oauth.token'), $this->exchangePayload($code));

        $response->assertOk()
            ->assertJsonStructure(['access_token', 'token_type', 'expires_in', 'refresh_token', 'scope'])
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('scope', 'profile account.read');

        $this->assertSame(3600, $response->json('expires_in'));
    }

    public function test_dei_token_resta_in_tabella_solo_l_impronta(): void
    {
        $tokens = $this->fullFlow();

        $record = OAuthAccessToken::sole();

        $this->assertSame(hash('sha256', $tokens['access_token']), $record->token_hash);
        $this->assertSame(hash('sha256', $tokens['refresh_token']), $record->refresh_hash);

        $riga = json_encode($record->toArray());
        $this->assertStringNotContainsString($tokens['access_token'], (string) $riga);
        $this->assertStringNotContainsString($tokens['refresh_token'], (string) $riga);
    }

    public function test_lo_scambio_lascia_traccia_in_audit_log(): void
    {
        $this->fullFlow();

        $this->assertTrue(
            AuditLog::query()->where('event', 'oauth.token_issued')->exists(),
            'Ogni token emesso deve lasciare una traccia in AuditLog.'
        );
    }

    public function test_le_credenziali_del_client_si_possono_passare_in_basic_auth(): void
    {
        $code = $this->consentAndGetCode();

        $payload = $this->exchangePayload($code);
        unset($payload['client_id'], $payload['client_secret']);

        $this->withHeaders([
            'Authorization' => 'Basic ' . base64_encode(self::CLIENT_ID . ':' . self::CLIENT_SECRET),
        ])->postJson(route('api.oauth.token'), $payload)->assertOk();
    }

    // =========================================================================
    // 2. Il client deve essere chi dice di essere
    // =========================================================================

    public function test_segreto_sbagliato_non_ottiene_token(): void
    {
        $code = $this->consentAndGetCode();

        $this->postJson(route('api.oauth.token'), array_merge($this->exchangePayload($code), [
            'client_secret' => 'segreto-sbagliato',
        ]))
            ->assertStatus(401)
            ->assertJsonPath('error', 'invalid_client');

        $this->assertSame(0, OAuthAccessToken::count());
    }

    public function test_segreto_mancante_non_ottiene_token(): void
    {
        $code    = $this->consentAndGetCode();
        $payload = $this->exchangePayload($code);
        unset($payload['client_secret']);

        $this->postJson(route('api.oauth.token'), $payload)
            ->assertStatus(401)
            ->assertJsonPath('error', 'invalid_client');
    }

    public function test_client_sconosciuto_non_ottiene_token(): void
    {
        $code = $this->consentAndGetCode();

        $this->postJson(route('api.oauth.token'), array_merge($this->exchangePayload($code), [
            'client_id' => 'un-altro-client',
        ]))
            ->assertStatus(401)
            ->assertJsonPath('error', 'invalid_client');
    }

    public function test_codice_di_un_altro_client_non_e_spendibile(): void
    {
        $code = $this->consentAndGetCode();

        // Un secondo client, con credenziali proprie e valide.
        config()->set('oauth.clients.altro', [
            'name'          => 'Altra App',
            'client_id'     => 'altro-client',
            'secret'        => 'altro-segreto-lungo',
            'redirect_uris' => [self::REDIRECT],
            'scopes'        => ['profile', 'account.read'],
        ]);

        $this->postJson(route('api.oauth.token'), array_merge($this->exchangePayload($code), [
            'client_id'     => 'altro-client',
            'client_secret' => 'altro-segreto-lungo',
        ]))
            ->assertStatus(400)
            ->assertJsonPath('error', 'invalid_grant');

        $this->assertSame(0, OAuthAccessToken::count());
    }

    // =========================================================================
    // 3. PKCE
    // =========================================================================

    public function test_verificatore_sbagliato_non_ottiene_token(): void
    {
        $code = $this->consentAndGetCode();

        $this->postJson(route('api.oauth.token'), array_merge($this->exchangePayload($code), [
            'code_verifier' => 'un-verificatore-diverso-ma-lungo-abbastanza-ok',
        ]))
            ->assertStatus(400)
            ->assertJsonPath('error', 'invalid_grant');

        $this->assertSame(0, OAuthAccessToken::count());
    }

    public function test_verificatore_troppo_corto_e_rifiutato(): void
    {
        $code = $this->consentAndGetCode();

        $this->postJson(route('api.oauth.token'), array_merge($this->exchangePayload($code), [
            'code_verifier' => 'corto',
        ]))
            ->assertStatus(400)
            ->assertJsonPath('error', 'invalid_request');
    }

    public function test_verificatore_mancante_e_rifiutato(): void
    {
        $code    = $this->consentAndGetCode();
        $payload = $this->exchangePayload($code);
        unset($payload['code_verifier']);

        $this->postJson(route('api.oauth.token'), $payload)
            ->assertStatus(400)
            ->assertJsonPath('error', 'invalid_request');
    }

    // =========================================================================
    // 4. Il codice: monouso, a scadenza, legato alla sua redirect_uri
    // =========================================================================

    public function test_il_codice_si_spende_una_volta_sola(): void
    {
        $code = $this->consentAndGetCode();

        $this->postJson(route('api.oauth.token'), $this->exchangePayload($code))->assertOk();

        $this->postJson(route('api.oauth.token'), $this->exchangePayload($code))
            ->assertStatus(400)
            ->assertJsonPath('error', 'invalid_grant');
    }

    /**
     * Il riuso non è un errore qualunque: è il sintomo di un codice rubato.
     * La sessione nata da quel consenso viene spenta.
     */
    public function test_riusare_il_codice_revoca_i_token_gia_emessi(): void
    {
        $code   = $this->consentAndGetCode();
        $tokens = $this->postJson(route('api.oauth.token'), $this->exchangePayload($code))->json();

        // Il token funziona...
        $this->userInfo($tokens['access_token'])->assertOk();

        // ...finché qualcun altro non prova a rispendere lo stesso codice.
        $this->postJson(route('api.oauth.token'), $this->exchangePayload($code))->assertStatus(400);

        $this->userInfo($tokens['access_token'])
            ->assertStatus(401)
            ->assertJsonPath('error', 'invalid_token');

        $this->assertTrue(
            AuditLog::query()->where('event', 'oauth.code_reuse_detected')->exists()
        );
    }

    public function test_il_codice_scaduto_non_si_spende(): void
    {
        $code = $this->consentAndGetCode();

        $this->travel(61)->seconds();

        $this->postJson(route('api.oauth.token'), $this->exchangePayload($code))
            ->assertStatus(400)
            ->assertJsonPath('error', 'invalid_grant');
    }

    public function test_redirect_uri_diversa_allo_scambio_non_e_accettata(): void
    {
        $code = $this->consentAndGetCode();

        $this->postJson(route('api.oauth.token'), array_merge($this->exchangePayload($code), [
            'redirect_uri' => 'https://kosmoshop.test/oauth/altro-callback',
        ]))
            ->assertStatus(400)
            ->assertJsonPath('error', 'invalid_grant');
    }

    public function test_codice_inventato_non_vale(): void
    {
        $this->postJson(route('api.oauth.token'), $this->exchangePayload('codice-mai-emesso'))
            ->assertStatus(400)
            ->assertJsonPath('error', 'invalid_grant');
    }

    public function test_grant_type_sconosciuto_e_rifiutato(): void
    {
        $this->postJson(route('api.oauth.token'), [
            'grant_type'    => 'password',
            'client_id'     => self::CLIENT_ID,
            'client_secret' => self::CLIENT_SECRET,
            'username'      => 'mario',
            'password'      => 'segreto',
        ])
            ->assertStatus(400)
            ->assertJsonPath('error', 'unsupported_grant_type');
    }

    // =========================================================================
    // 5. Rinnovo con rotazione
    // =========================================================================

    public function test_il_rinnovo_restituisce_una_coppia_nuova(): void
    {
        $tokens = $this->fullFlow();

        $rinnovati = $this->postJson(route('api.oauth.token'), [
            'grant_type'    => 'refresh_token',
            'client_id'     => self::CLIENT_ID,
            'client_secret' => self::CLIENT_SECRET,
            'refresh_token' => $tokens['refresh_token'],
        ])->assertOk()->json();

        $this->assertNotSame($tokens['access_token'], $rinnovati['access_token']);
        $this->assertNotSame($tokens['refresh_token'], $rinnovati['refresh_token']);

        $this->userInfo($rinnovati['access_token'])->assertOk();
    }

    public function test_il_refresh_vecchio_muore_appena_usato(): void
    {
        $tokens = $this->fullFlow();

        $this->refresh($tokens['refresh_token'])->assertOk();

        $this->refresh($tokens['refresh_token'])
            ->assertStatus(400)
            ->assertJsonPath('error', 'invalid_grant');
    }

    /**
     * Il caso che giustifica la rotazione: se un refresh già speso ricompare,
     * qualcuno ne ha una copia. Muore tutta la catena, compreso il token nuovo
     * appena consegnato all'utente legittimo — che rifarà il login.
     */
    public function test_riusare_un_refresh_gia_speso_spegne_tutta_la_catena(): void
    {
        $tokens    = $this->fullFlow();
        $rinnovati = $this->refresh($tokens['refresh_token'])->json();

        $this->userInfo($rinnovati['access_token'])->assertOk();

        // L'attaccante prova con la sua copia del refresh vecchio.
        $this->refresh($tokens['refresh_token'])->assertStatus(400);

        // Anche il token buono è stato spento.
        $this->userInfo($rinnovati['access_token'])->assertStatus(401);

        $this->assertTrue(
            AuditLog::query()->where('event', 'oauth.refresh_reuse_detected')->exists()
        );
    }

    public function test_refresh_scaduto_non_rinnova(): void
    {
        $tokens = $this->fullFlow();

        $this->travel(31)->days();

        $this->refresh($tokens['refresh_token'])
            ->assertStatus(400)
            ->assertJsonPath('error', 'invalid_grant');
    }

    public function test_refresh_di_un_altro_client_non_rinnova(): void
    {
        $tokens = $this->fullFlow();

        config()->set('oauth.clients.altro', [
            'name'          => 'Altra App',
            'client_id'     => 'altro-client',
            'secret'        => 'altro-segreto-lungo',
            'redirect_uris' => [self::REDIRECT],
            'scopes'        => ['profile'],
        ]);

        $this->postJson(route('api.oauth.token'), [
            'grant_type'    => 'refresh_token',
            'client_id'     => 'altro-client',
            'client_secret' => 'altro-segreto-lungo',
            'refresh_token' => $tokens['refresh_token'],
        ])
            ->assertStatus(400)
            ->assertJsonPath('error', 'invalid_grant');
    }

    // =========================================================================
    // 6. Revoca
    // =========================================================================

    public function test_la_revoca_spegne_il_token(): void
    {
        $tokens = $this->fullFlow();

        $this->userInfo($tokens['access_token'])->assertOk();

        $this->postJson(route('api.oauth.token.revoke'), [
            'client_id'     => self::CLIENT_ID,
            'client_secret' => self::CLIENT_SECRET,
            'token'         => $tokens['access_token'],
        ])->assertOk();

        $this->userInfo($tokens['access_token'])->assertStatus(401);

        // Revocare l'access token chiude anche la porta di servizio: il refresh
        // della stessa catena non deve poter riaprire la sessione.
        $this->refresh($tokens['refresh_token'])->assertStatus(400);

        $this->assertTrue(
            AuditLog::query()->where('event', 'oauth.token_revoked')->exists()
        );
    }

    public function test_la_revoca_di_un_token_inesistente_risponde_comunque_ok(): void
    {
        $this->postJson(route('api.oauth.token.revoke'), [
            'client_id'     => self::CLIENT_ID,
            'client_secret' => self::CLIENT_SECRET,
            'token'         => 'token-mai-esistito',
        ])->assertOk();
    }

    // =========================================================================
    // 7. /userinfo
    // =========================================================================

    public function test_userinfo_senza_token_e_chiuso(): void
    {
        $this->getJson(route('api.v1.userinfo'))
            ->assertStatus(401)
            ->assertJsonPath('error', 'invalid_token');
    }

    public function test_userinfo_dice_chi_e_l_utente_senza_id_interni(): void
    {
        $user   = $this->privateUser();
        $tokens = $this->fullFlow($user);

        $response = $this->userInfo($tokens['access_token'])->assertOk();

        $this->assertSame($user->fresh()->uuid, $response->json('sub'));
        $this->assertSame($user->name, $response->json('profile.name'));
        $this->assertSame($user->email, $response->json('profile.email'));

        // L'id numerico dell'utente non deve uscire da KMoney.
        $this->assertStringNotContainsString('"id"', (string) $response->getContent());
    }

    public function test_userinfo_non_dice_mai_il_saldo(): void
    {
        $tokens = $this->fullFlow();

        $contenuto = (string) $this->userInfo($tokens['access_token'])->getContent();

        $this->assertStringNotContainsString('balance', $contenuto);
        $this->assertStringNotContainsString('saldo', $contenuto);
    }

    public function test_userinfo_dice_conto_e_stato_commerciale(): void
    {
        [$user, $account] = $this->companyUserWithAccount();
        $tokens = $this->fullFlow($user);

        $response = $this->userInfo($tokens['access_token'])->assertOk();

        $this->assertSame($account->uuid, $response->json('account.number'));
        $this->assertIsBool($response->json('trading.can_sell'));
        $this->assertIsArray($response->json('trading.allowed_ky_percentages'));
        $this->assertContains('buyer', $response->json('roles'));
    }

    public function test_senza_scope_account_read_userinfo_non_espone_il_conto(): void
    {
        $tokens = $this->fullFlow(scope: 'profile');

        $response = $this->userInfo($tokens['access_token'])->assertOk();

        $this->assertNotNull($response->json('sub'));
        $this->assertNull($response->json('account'));
        $this->assertNull($response->json('trading'));
    }

    public function test_token_scaduto_non_apre_piu_niente(): void
    {
        $tokens = $this->fullFlow();

        $this->travel(61)->minutes();

        $this->userInfo($tokens['access_token'])
            ->assertStatus(401)
            ->assertJsonPath('error', 'invalid_token');
    }

    public function test_uno_scope_mancante_chiude_la_porta_con_403(): void
    {
        Route::middleware(['api', 'oauth.token:mandate'])
            ->get('/_test/solo-mandato', fn () => response()->json(['ok' => true]));

        $tokens = $this->fullFlow(scope: 'profile account.read');

        $this->withHeaders(['Authorization' => 'Bearer ' . $tokens['access_token']])
            ->getJson('/_test/solo-mandato')
            ->assertStatus(403)
            ->assertJsonPath('error', 'insufficient_scope');
    }

    public function test_lo_scope_concesso_apre_la_porta(): void
    {
        Route::middleware(['api', 'oauth.token:mandate'])
            ->get('/_test/solo-mandato', fn () => response()->json(['ok' => true]));

        $tokens = $this->fullFlow(scope: 'profile mandate');

        $this->withHeaders(['Authorization' => 'Bearer ' . $tokens['access_token']])
            ->getJson('/_test/solo-mandato')
            ->assertOk()
            ->assertJsonPath('ok', true);
    }

    // =========================================================================
    // Helper
    // =========================================================================

    /**
     * Percorso completo: consenso dell'utente + scambio del codice.
     *
     * @return array{access_token: string, refresh_token: string, expires_in: int, scope: string}
     */
    private function fullFlow(?User $user = null, string $scope = 'profile account.read'): array
    {
        $code = $this->consentAndGetCode($user, $scope);

        return $this->postJson(route('api.oauth.token'), $this->exchangePayload($code))
            ->assertOk()
            ->json();
    }

    private function consentAndGetCode(?User $user = null, string $scope = 'profile account.read'): string
    {
        $user ??= $this->privateUser();

        $url = route('oauth.authorize', [
            'response_type'         => 'code',
            'client_id'             => self::CLIENT_ID,
            'redirect_uri'          => self::REDIRECT,
            'scope'                 => $scope,
            'state'                 => 'stato-di-prova',
            'code_challenge'        => app(OAuthService::class)->pkceChallengeFor(self::VERIFIER),
            'code_challenge_method' => 'S256',
        ]);

        $this->actingAs($user)->get($url)->assertOk();

        $response = $this->actingAs($user)->post(route('oauth.authorize.approve'));

        parse_str((string) parse_url((string) $response->headers->get('Location'), PHP_URL_QUERY), $query);

        $this->assertArrayHasKey('code', $query, 'Il consenso deve produrre un codice.');

        return $query['code'];
    }

    /**
     * @return array<string, string>
     */
    private function exchangePayload(string $code): array
    {
        return [
            'grant_type'    => 'authorization_code',
            'client_id'     => self::CLIENT_ID,
            'client_secret' => self::CLIENT_SECRET,
            'code'          => $code,
            'redirect_uri'  => self::REDIRECT,
            'code_verifier' => self::VERIFIER,
        ];
    }

    private function refresh(string $refreshToken)
    {
        return $this->postJson(route('api.oauth.token'), [
            'grant_type'    => 'refresh_token',
            'client_id'     => self::CLIENT_ID,
            'client_secret' => self::CLIENT_SECRET,
            'refresh_token' => $refreshToken,
        ]);
    }

    private function userInfo(string $accessToken)
    {
        return $this->withHeaders(['Authorization' => 'Bearer ' . $accessToken])
            ->getJson(route('api.v1.userinfo'));
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
     * @return array{0: User, 1: Account}
     */
    private function companyUserWithAccount(): array
    {
        $slug = 'azienda-' . Str::random(6);

        $company = \App\Models\Company::create([
            'name'          => 'Azienda ' . Str::random(4),
            'slug'          => $slug,
            'email'         => $slug . '@test.test',
            'status'        => 'active',
            'kyc_status'    => 'approved',
            'currency_code' => 'KY',
            'sector'        => 'informatica',
            'description'   => 'Azienda di test',
        ]);

        $account = Account::create([
            'company_id'        => $company->id,
            'owner_type'        => 'company',
            'type'              => 'member',
            'status'            => 'active',
            'available_balance' => 100000,
        ]);

        $user = User::create([
            'name'                => 'Titolare',
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

        return [$user->fresh(), $account->fresh()];
    }
}
