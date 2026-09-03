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
     *   - Già firmato E versione non superata → pass through
     *   - Già firmato ma versione superata da una revisione → rifirma
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

        // PRIMA il contratto agente, POI questo — l'ordine deciso il 31/07 e
        // fissato da MlmAgentContractGateTest. Serve ora che esiste la
        // rifirma: un'azienda che e' anche agente in attesa di nomina, con
        // una rifirma in corso, verrebbe rimbalzata da qui e non arriverebbe
        // mai alla pagina della nomina. E la quota codice agente e' l'unica
        // strada che porta a quella firma.
        if ($request->routeIs('portal.mlm.agent-contract.*') || $request->routeIs('portal.mlm.agent-code-fee.*')) {
            return $next($request);
        }

        $settings = SystemSetting::contractSettings();

        // RIFIRMA DOPO UNA REVISIONE (2026-09-03). Prima di questa riga qui
        // c'era solo `if ($user->contract_signed_at) return $next()`: chi
        // aveva firmato una volta non veniva piu' interpellato, e una
        // revisione delle condizioni non raggiungeva nessuno. Adesso una
        // firma vale solo fino alla soglia che l'admin alza quando pubblica
        // una revisione sostanziale.
        $deveRifirmare = $settings->resignRequiredFor($user);

        if ($user->contract_signed_at && ! $deveRifirmare) {
            return $next($request);
        }

        // Carica impostazioni admin
        $forceSign    = (bool) $settings->contract_force_sign;
        $requiredFrom = $settings->contract_required_from;

        // Determina se è un nuovo utente (registrato dopo il deploy della feature)
        $isNewUser = $requiredFrom
            && $user->created_at
            && $user->created_at->toDateString() >= $requiredFrom;

        if ($isNewUser || $forceSign) {
            // Firma obbligatoria, nessuna possibilità di rimandare
            return redirect()->route('portal.contract.sign')
                ->with('contract_required', true)
                ->with('contract_resign', $deveRifirmare);
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
            ->with('contract_reminder', true)
            ->with('contract_resign', $deveRifirmare);
    }
}
