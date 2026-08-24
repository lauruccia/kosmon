<?php

namespace App\Http\Middleware;

use App\Models\OAuthAccessToken;
use App\Services\OAuthService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autenticazione delle API con un token di "Accedi con KMoney".
 *
 * Convive con `api.token` (ApiTokenAuth) senza sostituirlo: quello è il token
 * *dell'azienda*, emesso una volta e usato da un server; questo rappresenta
 * *un utente* che ha dato il consenso a un'applicazione. Sono due cose diverse
 * e restano separate.
 *
 * Uso: ->middleware('oauth.token')  oppure  ->middleware('oauth.token:orders.write')
 */
class OAuthTokenAuth
{
    public function __construct(private readonly OAuthService $oauth)
    {
    }

    public function handle(Request $request, Closure $next, ?string $scope = null): Response
    {
        $header = (string) $request->header('Authorization', '');

        if (! str_starts_with($header, 'Bearer ')) {
            return $this->unauthorized('Authorization header mancante.');
        }

        $token = $this->oauth->findAccessToken(substr($header, 7));

        if (! $token) {
            return $this->unauthorized('Token non valido.');
        }

        if ($token->isRevoked()) {
            return $this->unauthorized('Token revocato.');
        }

        if ($token->isExpired()) {
            return $this->unauthorized('Token scaduto.');
        }

        if ($scope !== null && ! $token->hasScope($scope)) {
            return response()->json([
                'error'             => 'insufficient_scope',
                'error_description' => "Questo token non ha il permesso richiesto: {$scope}",
            ], 403);
        }

        $this->touch($token, $request->ip());

        $request->attributes->set('oauth_token', $token);
        $request->attributes->set('oauth_user', $token->user);

        return $next($request);
    }

    /**
     * Aggiorna `last_used_at` senza scrivere a ogni singola chiamata: stessa
     * accortezza già usata da ApiTokenAuth per non moltiplicare le scritture.
     */
    private function touch(OAuthAccessToken $token, ?string $ip): void
    {
        $stale = $token->last_used_at === null
            || $token->last_used_at->lt(now()->subSeconds(60));

        if ($stale) {
            $token->forceFill(['last_used_at' => now()])->saveQuietly();
        }
    }

    private function unauthorized(string $description): Response
    {
        return response()->json([
            'error'             => 'invalid_token',
            'error_description' => $description,
        ], 401);
    }
}
