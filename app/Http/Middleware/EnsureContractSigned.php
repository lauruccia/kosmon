<?php

namespace App\Http\Middleware;

use App\Models\SystemSetting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureContractSigned
{
    /**
     * Garantisce che l'utente abbia firmato il contratto di adesione
     * prima di accedere al portale.
     *
     * Logica:
     *   - Admin / broker backoffice → skip
     *   - Utenti senza azienda → skip
     *   - Già firmato → pass through
     *   - Nuovo utente (creato dopo contract_required_from) → obbligatorio, no postpone
     *   - Utente esistente + contract_force_sign=true → obbligatorio
     *   - Utente esistente + postponed entro 24h → pass through (può rimandare)
     *   - Altrimenti → redirect alla pagina di firma
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Salta per non autenticati e admin/backoffice
        if (! $user || $user->canAccessBackoffice()) {
            return $next($request);
        }

        // Salta per utenti senza azienda
        if (! $user->company_id) {
            return $next($request);
        }

        // Salta se già sulle route contratto (evita loop)
        if ($request->routeIs('portal.contract.*')) {
            return $next($request);
        }

        // Contratto già firmato: via libera
        if ($user->contract_signed_at) {
            return $next($request);
        }

        // Carica impostazioni admin
        $forceSign    = (bool) SystemSetting::contractSettings()->contract_force_sign;
        $requiredFrom = SystemSetting::contractSettings()->contract_required_from;

        // Determina se è un nuovo utente (registrato dopo il deploy della feature)
        $isNewUser = $requiredFrom
            && $user->created_at
            && $user->created_at->toDateString() >= $requiredFrom;

        if ($isNewUser || $forceSign) {
            // Firma obbligatoria, nessuna possibilità di rimandare
            return redirect()->route('portal.contract.sign')
                ->with('contract_required', true);
        }

        // Utente esistente: verifica se ha posticipato di recente (finestra 24h)
        if (
            $user->contract_postponed_at
            && $user->contract_postponed_at->isAfter(now()->subHours(24))
        ) {
            return $next($request);
        }

        // Prima visita o finestra 24h scaduta: mostra pagina firma (con opzione rimanda)
        return redirect()->route('portal.contract.sign')
            ->with('contract_reminder', true);
    }
}
