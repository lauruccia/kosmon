<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TwoFactorChallenge
{
    /**
     * Gestisce il challenge 2FA.
     *
     * Per un circuito monetario il 2FA è obbligatorio:
     * - Se l'utente ha TOTP configurato → redirect alla verifica se non ancora verificato
     * - Se l'utente ha passkey ma non TOTP → già autenticato via passkey (sicuro, skip)
     * - Se l'utente non ha né TOTP né passkey → redirect al wizard di configurazione
     *
     * Admin e backoffice: stesso controllo (nessuna esenzione per ruolo).
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        // Non redirigere le route di 2FA, setup e webauthn per evitare loop
        if ($request->routeIs('2fa.*', 'portal.2fa.*', 'webauthn.*', 'portal.step-up.*')) {
            return $next($request);
        }

        $hasTwoFactor = (bool) $user->two_factor_confirmed_at;
        $hasPasskey   = $user->webAuthnCredentials()->exists();

        // Nessun secondo fattore configurato → l'utente può scegliere se attivarlo
        if (! $hasTwoFactor && ! $hasPasskey) {
            return $next($request);
        }

        // TOTP configurato ma non ancora verificato in questa sessione
        if ($hasTwoFactor && ! $request->session()->get('two_factor_verified')) {
            return redirect()->route('2fa.challenge');
        }

        // Passkey senza TOTP: autenticazione già forte via WebAuthn → accesso consentito
        return $next($request);
    }
}
