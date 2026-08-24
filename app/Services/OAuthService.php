<?php

namespace App\Services;

use App\Exceptions\OAuthException;
use App\Models\AuditLog;
use App\Models\OAuthAccessToken;
use App\Models\OAuthAuthorizationCode;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * "Accedi con KMoney" — il motore del flusso OAuth2.
 *
 * Implementa UN SOLO flusso: `authorization_code` con PKCE obbligatorio, più il
 * rinnovo `refresh_token` con rotazione. Non esistono password grant, client
 * credentials, device flow, personal access token: quello che non c'è non si
 * può sbagliare.
 *
 * Le regole che questo file ha il compito di far rispettare — e che i test
 * verificano una per una (tests/Feature/OAuth*) — sono:
 *
 *  1. PKCE S256 obbligatorio (niente `plain`, niente PKCE assente)
 *  2. il codice di autorizzazione è monouso, consumato dentro una transazione
 *     con lock: due richieste in parallelo non possono spenderlo due volte
 *  3. il codice vive 60 secondi
 *  4. la `redirect_uri` si confronta per intero con la lista chiusa
 *  5. il segreto del client si confronta con `hash_equals`
 *  6. gli scope sono validati contro la lista chiusa di config/oauth.php
 *  7. codice e token stanno in tabella solo come SHA-256
 *  8. il riuso di un codice o di un refresh già speso REVOCA tutta la catena:
 *     se qualcuno li ha rubati, quella sessione muore invece di continuare
 *  9. ogni emissione, rinnovo, revoca e sospetto finisce in AuditLog
 */
class OAuthService
{
    /** Prefissi: rendono i token riconoscibili a colpo d'occhio in un log o in un incidente. */
    private const ACCESS_PREFIX  = 'kma_';
    private const REFRESH_PREFIX = 'kmr_';

    /** L'unico metodo PKCE accettato. */
    private const PKCE_METHOD = 'S256';

    // =========================================================================
    // Client
    // =========================================================================

    /**
     * Trova un client per `client_id`, senza verificarne il segreto.
     *
     * @return array{key: string, name: string, client_id: string, secret: ?string, redirect_uris: array<int, string>, scopes: array<int, string>}
     */
    public function client(?string $clientId): array
    {
        if ($clientId === null || $clientId === '') {
            throw OAuthException::invalidClient('client_id mancante.');
        }

        foreach ((array) config('oauth.clients', []) as $key => $client) {
            $configured = (string) ($client['client_id'] ?? '');

            // Un client senza client_id nel .env non è configurato: non esiste.
            if ($configured !== '' && hash_equals($configured, $clientId)) {
                return array_merge($client, ['key' => (string) $key, 'client_id' => $configured]);
            }
        }

        throw OAuthException::invalidClient('Client sconosciuto.');
    }

    /**
     * Trova un client e ne verifica il segreto (endpoint /token).
     *
     * @return array{key: string, name: string, client_id: string, secret: ?string, redirect_uris: array<int, string>, scopes: array<int, string>}
     */
    public function authenticateClient(?string $clientId, ?string $secret): array
    {
        $client = $this->client($clientId);

        $expected = (string) ($client['secret'] ?? '');

        if ($expected === '' || $secret === null || ! hash_equals($expected, $secret)) {
            throw OAuthException::invalidClient('Segreto del client non valido.');
        }

        return $client;
    }

    /**
     * La redirect_uri deve stare, IDENTICA, nella lista del client.
     *
     * Niente confronto per prefisso: `https://kosmoshop.it/callback` non
     * autorizza `https://kosmoshop.it/callback.attaccante.it`.
     */
    public function isRedirectUriAllowed(array $client, ?string $redirectUri): bool
    {
        if ($redirectUri === null || $redirectUri === '') {
            return false;
        }

        foreach ((array) ($client['redirect_uris'] ?? []) as $allowed) {
            if (hash_equals((string) $allowed, $redirectUri)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Normalizza e valida gli scope richiesti.
     *
     * @return array<int, string>
     */
    public function validateScopes(array $client, ?string $requested): array
    {
        $known   = array_keys((array) config('oauth.scopes', []));
        $allowed = (array) ($client['scopes'] ?? []);

        $scopes = array_values(array_unique(array_filter(
            preg_split('/\s+/', (string) $requested) ?: []
        )));

        if ($scopes === []) {
            throw OAuthException::invalidScope('Nessuno scope richiesto.');
        }

        foreach ($scopes as $scope) {
            if (! in_array($scope, $known, true)) {
                throw OAuthException::invalidScope("Scope inesistente: {$scope}");
            }

            if (! in_array($scope, $allowed, true)) {
                throw OAuthException::invalidScope("Scope non concesso a questo client: {$scope}");
            }
        }

        return $scopes;
    }

    // =========================================================================
    // Codice di autorizzazione
    // =========================================================================

    /**
     * Emette il codice usa e getta dopo il consenso dell'utente.
     * Restituisce il codice IN CHIARO: è l'unica volta che esiste.
     *
     * @param array<int, string> $scopes
     */
    public function issueAuthorizationCode(
        array $client,
        User $user,
        string $redirectUri,
        array $scopes,
        string $codeChallenge,
        string $codeChallengeMethod,
        ?string $ip = null,
    ): string {
        if ($codeChallengeMethod !== self::PKCE_METHOD) {
            throw OAuthException::invalidRequest('PKCE obbligatorio nella variante S256.');
        }

        if ($codeChallenge === '' || strlen($codeChallenge) > 128) {
            throw OAuthException::invalidRequest('code_challenge assente o malformato.');
        }

        $code = Str::random(64);

        $record = OAuthAuthorizationCode::create([
            'code_hash'             => hash('sha256', $code),
            'chain_uuid'            => (string) Str::uuid(),
            'client_id'             => $client['client_id'],
            'user_id'               => $user->id,
            'scopes'                => $scopes,
            'redirect_uri'          => $redirectUri,
            'code_challenge'        => $codeChallenge,
            'code_challenge_method' => $codeChallengeMethod,
            'expires_at'            => now()->addSeconds((int) config('oauth.ttl.authorization_code', 60)),
            'created_ip'            => $ip,
        ]);

        $this->audit('oauth.consent_granted', $user->id, $record, $ip, [
            'client_id' => $client['client_id'],
            'scopes'    => $scopes,
        ]);

        return $code;
    }

    /**
     * Scambia il codice con una coppia di token.
     *
     * @return array{access_token: string, refresh_token: string, expires_in: int, scopes: array<int, string>, model: OAuthAccessToken}
     */
    public function exchangeAuthorizationCode(
        array $client,
        ?string $code,
        ?string $redirectUri,
        ?string $codeVerifier,
        ?string $ip = null,
    ): array {
        if ($code === null || $code === '') {
            throw OAuthException::invalidRequest('code mancante.');
        }

        if ($codeVerifier === null || strlen($codeVerifier) < 43 || strlen($codeVerifier) > 128) {
            throw OAuthException::invalidRequest('code_verifier assente o di lunghezza non valida (43-128 caratteri).');
        }

        $result = DB::transaction(function () use ($client, $code, $redirectUri, $codeVerifier, $ip) {
            /** @var OAuthAuthorizationCode|null $record */
            $record = OAuthAuthorizationCode::query()
                ->where('code_hash', hash('sha256', $code))
                ->lockForUpdate()
                ->first();

            if (! $record) {
                throw OAuthException::invalidGrant('Codice di autorizzazione non valido.');
            }

            if (! hash_equals($record->client_id, $client['client_id'])) {
                throw OAuthException::invalidGrant('Il codice è stato emesso per un altro client.');
            }

            // Riuso: il codice era già stato speso. È il sintomo classico di un
            // codice intercettato — la sessione nata da quel consenso muore.
            //
            // ATTENZIONE (bug preso al volo dai test il 24/08): la revoca NON
            // può stare qui dentro. Uscire da una transazione con un'eccezione
            // fa il rollback di tutto, revoca compresa: la difesa si sarebbe
            // annullata da sola. Si segnala il riuso e si spegne la catena
            // FUORI, dopo che la transazione si è chiusa.
            if ($record->isConsumed()) {
                return ['reuse' => $record];
            }

            if ($record->isExpired()) {
                throw OAuthException::invalidGrant('Codice scaduto.');
            }

            if ($redirectUri === null || ! hash_equals($record->redirect_uri, $redirectUri)) {
                throw OAuthException::invalidGrant('redirect_uri diversa da quella usata per ottenere il codice.');
            }

            if ($record->code_challenge_method !== self::PKCE_METHOD) {
                throw OAuthException::invalidGrant('Metodo PKCE non supportato.');
            }

            $computed = $this->pkceChallengeFor($codeVerifier);

            if (! hash_equals($record->code_challenge, $computed)) {
                throw OAuthException::invalidGrant('code_verifier non valido.');
            }

            $record->forceFill(['consumed_at' => now()])->save();

            $user = User::findOrFail($record->user_id);

            $tokens = $this->issueTokenPair(
                $client,
                $user,
                (array) $record->scopes,
                $record->chain_uuid,
                $ip,
            );

            $this->audit('oauth.token_issued', $user->id, $tokens['model'], $ip, [
                'client_id' => $client['client_id'],
                'scopes'    => $record->scopes,
            ]);

            return ['tokens' => $tokens];
        });

        if (isset($result['reuse'])) {
            /** @var OAuthAuthorizationCode $record */
            $record  = $result['reuse'];
            $revoked = $this->revokeChain($record->chain_uuid, 'authorization_code_reuse', $ip);

            $this->audit('oauth.code_reuse_detected', $record->user_id, $record, $ip, [
                'client_id'      => $client['client_id'],
                'revoked_tokens' => $revoked,
            ]);

            throw OAuthException::invalidGrant('Codice già utilizzato.');
        }

        return $result['tokens'];
    }

    /**
     * Rinnova la coppia di token, ruotandola.
     *
     * @return array{access_token: string, refresh_token: string, expires_in: int, scopes: array<int, string>, model: OAuthAccessToken}
     */
    public function refreshTokens(array $client, ?string $refreshToken, ?string $ip = null): array
    {
        if ($refreshToken === null || $refreshToken === '') {
            throw OAuthException::invalidRequest('refresh_token mancante.');
        }

        $result = DB::transaction(function () use ($client, $refreshToken, $ip) {
            /** @var OAuthAccessToken|null $record */
            $record = OAuthAccessToken::query()
                ->where('refresh_hash', hash('sha256', $refreshToken))
                ->lockForUpdate()
                ->first();

            if (! $record) {
                throw OAuthException::invalidGrant('Refresh token non valido.');
            }

            if (! hash_equals($record->client_id, $client['client_id'])) {
                throw OAuthException::invalidGrant('Il refresh token è stato emesso per un altro client.');
            }

            // Un refresh già ruotato (o revocato) che torna indietro significa
            // che qualcuno ne ha una copia: si spegne tutta la catena — ma
            // fuori di qui, per lo stesso motivo spiegato sopra sul rollback.
            if ($record->isRevoked()) {
                return ['reuse' => $record];
            }

            if ($record->refresh_expires_at === null || $record->refresh_expires_at->isPast()) {
                throw OAuthException::invalidGrant('Refresh token scaduto.');
            }

            // Rotazione: il vecchio muore nello stesso istante in cui nasce il nuovo.
            $record->forceFill(['revoked_at' => now()])->save();

            $user = User::findOrFail($record->user_id);

            $tokens = $this->issueTokenPair(
                $client,
                $user,
                (array) $record->scopes,
                $record->chain_uuid,
                $ip,
            );

            $this->audit('oauth.token_refreshed', $user->id, $tokens['model'], $ip, [
                'client_id' => $client['client_id'],
            ]);

            return ['tokens' => $tokens];
        });

        if (isset($result['reuse'])) {
            /** @var OAuthAccessToken $record */
            $record  = $result['reuse'];
            $revoked = $this->revokeChain($record->chain_uuid, 'refresh_token_reuse', $ip);

            $this->audit('oauth.refresh_reuse_detected', $record->user_id, $record, $ip, [
                'client_id'      => $client['client_id'],
                'revoked_tokens' => $revoked,
            ]);

            throw OAuthException::invalidGrant('Refresh token già utilizzato: la sessione è stata revocata per sicurezza.');
        }

        return $result['tokens'];
    }

    // =========================================================================
    // Token
    // =========================================================================

    /**
     * @param array<int, string> $scopes
     * @return array{access_token: string, refresh_token: string, expires_in: int, scopes: array<int, string>, model: OAuthAccessToken}
     */
    private function issueTokenPair(array $client, User $user, array $scopes, string $chainUuid, ?string $ip): array
    {
        $access  = self::ACCESS_PREFIX . Str::random(60);
        $refresh = self::REFRESH_PREFIX . Str::random(60);

        $ttl        = (int) config('oauth.ttl.access_token', 3600);
        $refreshTtl = (int) config('oauth.ttl.refresh_token', 2592000);

        $token = OAuthAccessToken::create([
            'token_hash'         => hash('sha256', $access),
            'refresh_hash'       => hash('sha256', $refresh),
            'chain_uuid'         => $chainUuid,
            'client_id'          => $client['client_id'],
            'user_id'            => $user->id,
            'scopes'             => $scopes,
            'expires_at'         => now()->addSeconds($ttl),
            'refresh_expires_at' => now()->addSeconds($refreshTtl),
            'created_ip'         => $ip,
        ]);

        return [
            'access_token'  => $access,
            'refresh_token' => $refresh,
            'expires_in'    => $ttl,
            'scopes'        => $scopes,
            'model'         => $token,
        ];
    }

    /**
     * Ritrova un token dal valore in chiaro. Non giudica: dice solo se esiste.
     */
    public function findAccessToken(string $plain): ?OAuthAccessToken
    {
        if ($plain === '') {
            return null;
        }

        return OAuthAccessToken::query()
            ->where('token_hash', hash('sha256', $plain))
            ->first();
    }

    /**
     * Revoca un token a partire dal valore in chiaro (access o refresh).
     * Revoca sempre l'intera catena: revocare "solo l'access" darebbe
     * all'utente l'illusione di aver chiuso una porta lasciandola aperta.
     */
    public function revokeByPlainToken(array $client, ?string $plain, ?string $ip = null): bool
    {
        if ($plain === null || $plain === '') {
            return false;
        }

        $hash = hash('sha256', $plain);

        /** @var OAuthAccessToken|null $record */
        $record = OAuthAccessToken::query()
            ->where('token_hash', $hash)
            ->orWhere('refresh_hash', $hash)
            ->first();

        if (! $record || ! hash_equals($record->client_id, $client['client_id'])) {
            // Per la specifica la revoca è sempre "riuscita": non si dice a chi
            // chiede se il token esisteva o no.
            return false;
        }

        $revoked = $this->revokeChain($record->chain_uuid, 'client_request', $ip);

        $this->audit('oauth.token_revoked', $record->user_id, $record, $ip, [
            'client_id'      => $client['client_id'],
            'revoked_tokens' => $revoked,
        ]);

        return true;
    }

    /**
     * Spegne tutti i token vivi di una catena. Restituisce quanti ne ha spenti.
     */
    public function revokeChain(string $chainUuid, string $reason, ?string $ip = null): int
    {
        return OAuthAccessToken::query()
            ->where('chain_uuid', $chainUuid)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }

    // =========================================================================
    // Utilità
    // =========================================================================

    /**
     * base64url(sha256(verifier)) — la trasformazione prevista da PKCE S256.
     */
    public function pkceChallengeFor(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    private function audit(string $event, ?int $userId, object $subject, ?string $ip, array $context = []): void
    {
        AuditLog::create([
            'actor_user_id'  => $userId,
            'event'          => $event,
            'auditable_type' => $subject::class,
            'auditable_id'   => $subject->getKey(),
            'ip_address'     => $ip,
            'context'        => $context,
        ]);
    }
}
