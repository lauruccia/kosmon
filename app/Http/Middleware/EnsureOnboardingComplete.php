<?php

namespace App\Http\Middleware;

use App\Models\Company;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureOnboardingComplete
{
    /**
     * Intercetta le route del portale per utenti aziendali non ancora verificati.
     *
     * Logica degli step:
     *   Step 1 — Profilo incompleto (sector o description mancanti)
     *   Step 2 — Nessun documento KYC caricato
     *   Step 3 — Documenti caricati ma approvazione in attesa
     *
     * Non si applica a: admin, broker backoffice, utenti privati, route /benvenuto/*.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Salta per utenti non autenticati o admin/backoffice
        if (! $user || $user->canAccessBackoffice()) {
            return $next($request);
        }

        // Salta per utenti senza azienda (account privati, broker senza company)
        if (! $user->company_id) {
            return $next($request);
        }

        // Gia sulle route di onboarding o verifica email → lascia passare (evita loop di redirect)
        if ($request->routeIs('onboarding.*') || $request->routeIs('verification.*') || $request->routeIs('portal.contract.*')) {
            return $next($request);
        }

        // Email non verificata → mostra notice (solo per utenti registrati dopo l'attivazione della verifica)
        if (! $user->hasVerifiedEmail() && $user->created_at?->isAfter(now()->subYear())) {
            return redirect()->route('verification.notice');
        }

        $company = Company::query()
            ->with(['kycDocuments'])
            ->find($user->company_id);

        if (! $company) {
            return $next($request);
        }

        // Azienda già approvata → accesso completo
        if ($company->kyc_status === 'approved') {
            return $next($request);
        }

        // Step 0/1: profilo incompleto
        // Nuovi utenti (nessun documento ancora) vanno alla schermata di benvenuto (step0),
        // utenti che hanno gia' caricato documenti ma hanno il profilo vuoto vanno direttamente a step1.
        if (empty($company->sector) || empty($company->description)) {
            if ($company->kycDocuments->isEmpty()) {
                return redirect()->route('onboarding.step0')
                    ->with('onboarding_info', 'Completa il profilo della tua azienda per continuare.');
            }
            return redirect()->route('onboarding.step1')
                ->with('onboarding_info', 'Completa il profilo della tua azienda per continuare.');
        }

        // Step 2: nessun documento KYC caricato
        if ($company->kycDocuments->isEmpty()) {
            return redirect()->route('onboarding.step2')
                ->with('onboarding_info', 'Carica i documenti richiesti per verificare la tua azienda.');
        }

        // Step 3: documenti caricati, in attesa di approvazione (o rifiutata)
        return redirect()->route('onboarding.step3');
    }
}
