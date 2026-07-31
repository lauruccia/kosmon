<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 2026-07-31 (richiesta di Laura): un utente con richiesta agente approvata
 * ma contratto di nomina NON ancora firmato (mlmAgentAwaitingContract())
 * deve firmarlo PRIMA di poter fare qualunque altra cosa nel portale —
 * incluso firmare il contratto di adesione generale KMoney, che deve
 * comparire solo DOPO. A differenza del contratto generale
 * (EnsureContractSigned) qui non esiste alcuna possibilità di posticipare:
 * è bloccante, coerente con la richiesta originale "da firmare con OTP
 * prima di iniziare a lavorare come agente".
 *
 * Applicato PRIMA del middleware 'contract' nello stack (vedi
 * bootstrap/app.php e routes/web.php), cosi' l'ordine visto dall'utente è:
 * 1) contratto agente (se in attesa)  2) contratto di adesione KMoney.
 *
 * Alias registrato in bootstrap/app.php: 'agent.contract'.
 */
class EnsureMlmAgentContractSigned
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Salta per non autenticati e admin/backoffice
        if (! $user || $user->canAccessBackoffice()) {
            return $next($request);
        }

        // Salta se il programma agenti (KNM/MLM) è disattivato su questa
        // installazione: le route del contratto agente in quel caso non
        // esistono nemmeno (EnsureMlmEnabled → 404), quindi non dobbiamo
        // mai provare a rediriggerci lì (kmoney.it ha MLM disabilitato).
        if (! config('kmoney.mlm_enabled')) {
            return $next($request);
        }

        // Già sulle route del contratto agente, o su logout → evita loop
        if ($request->routeIs('portal.mlm.agent-contract.*') || $request->routeIs('logout')) {
            return $next($request);
        }

        if ($user->mlmAgentAwaitingContract()) {
            return redirect()->route('portal.mlm.agent-contract.show')
                ->with('agent_contract_required', true);
        }

        return $next($request);
    }
}
