<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Step-up authentication — richiede una re-verifica recente (OTP o password)
 * prima di permettere operazioni sensibili: cambio password, disattivazione 2FA,
 * revoca token API, modifica email.
 *
 * Algoritmo:
 *  1. Se nella sessione c'è 'step_up_verified_at' e il timestamp è entro
 *     STEP_UP_WINDOW_MINUTES, lascia passare.
 *  2. Altrimenti reindirizza a /profilo/conferma-identita passando il returnUrl.
 *
 * Il controller StepUpController marca la sessione e reindirizza all'URL originale.
 */
class RequireStepUp
{
    public const STEP_UP_WINDOW_MINUTES = 15;

    public function handle(Request $request, Closure $next): Response
    {
        $verifiedAt = $request->session()->get('step_up_verified_at');

        $isValid = $verifiedAt
            && now()->diffInMinutes($verifiedAt) < self::STEP_UP_WINDOW_MINUTES;

        if (! $isValid) {
            // Salva l'URL di destinazione per il redirect post-verifica
            $request->session()->put('step_up_return_url', $request->fullUrl());

            return redirect()->route('portal.step-up.show')
                ->with('step_up_reason', 'Per continuare devi confermare la tua identità.');
        }

        return $next($request);
    }
}
