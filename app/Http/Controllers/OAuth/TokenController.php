<?php

namespace App\Http\Controllers\OAuth;

use App\Exceptions\OAuthException;
use App\Http\Controllers\Controller;
use App\Services\OAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Gli endpoint "sul retro" del flusso: qui non c'è nessun utente col browser,
 * parla solo il server di kshop, che si autentica col proprio segreto.
 *
 * Le risposte seguono la forma prevista dallo standard OAuth2 (`access_token`,
 * `token_type`, `expires_in`, `refresh_token`, `scope`; errori come
 * `{"error": "...", "error_description": "..."}`), così dall'altra parte si può
 * usare una libreria client qualunque senza adattamenti — ed è anche il motivo
 * per cui un domani si potrebbe sostituire questo motore senza toccare kshop.
 */
class TokenController extends Controller
{
    public function __construct(private readonly OAuthService $oauth)
    {
    }

    /**
     * POST /api/oauth/token
     */
    public function issue(Request $request): JsonResponse
    {
        try {
            [$clientId, $secret] = $this->clientCredentials($request);

            $client = $this->oauth->authenticateClient($clientId, $secret);

            $tokens = match ((string) $request->input('grant_type')) {
                'authorization_code' => $this->oauth->exchangeAuthorizationCode(
                    $client,
                    $request->input('code'),
                    $request->input('redirect_uri'),
                    $request->input('code_verifier'),
                    $request->ip(),
                ),
                'refresh_token' => $this->oauth->refreshTokens(
                    $client,
                    $request->input('refresh_token'),
                    $request->ip(),
                ),
                default => throw OAuthException::unsupportedGrantType(
                    'Grant type non supportato: sono previsti solo authorization_code e refresh_token.'
                ),
            };

            return $this->noStore(response()->json([
                'access_token'  => $tokens['access_token'],
                'token_type'    => 'Bearer',
                'expires_in'    => $tokens['expires_in'],
                'refresh_token' => $tokens['refresh_token'],
                'scope'         => implode(' ', $tokens['scopes']),
            ]));
        } catch (OAuthException $e) {
            return $this->noStore(response()->json($e->toArray(), $e->status));
        }
    }

    /**
     * POST /api/oauth/token/revoke
     *
     * Per la specifica (RFC 7009) la revoca risponde 200 anche se il token non
     * esisteva: non si dice a chi chiede se ha indovinato.
     */
    public function revoke(Request $request): JsonResponse
    {
        try {
            [$clientId, $secret] = $this->clientCredentials($request);

            $client = $this->oauth->authenticateClient($clientId, $secret);

            $this->oauth->revokeByPlainToken($client, $request->input('token'), $request->ip());

            return $this->noStore(response()->json(['revoked' => true]));
        } catch (OAuthException $e) {
            return $this->noStore(response()->json($e->toArray(), $e->status));
        }
    }

    // =========================================================================

    /**
     * Le credenziali del client arrivano o in HTTP Basic (forma consigliata
     * dallo standard) o nel corpo della richiesta. Accettiamo entrambe.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function clientCredentials(Request $request): array
    {
        $basicUser = $request->getUser();

        if ($basicUser !== null && $basicUser !== '') {
            return [$basicUser, $request->getPassword()];
        }

        return [
            $request->input('client_id'),
            $request->input('client_secret'),
        ];
    }

    private function noStore(JsonResponse $response): JsonResponse
    {
        return $response
            ->header('Cache-Control', 'no-store')
            ->header('Pragma', 'no-cache');
    }
}
