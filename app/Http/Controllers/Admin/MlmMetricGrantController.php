<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\AuthorizesBackoffice;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\MlmMetricGrant;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

/**
 * "Punti/agenti omaggio" (richiesta di Laura, 2026-07-14; estesa il
 * 2026-07-15 a tutte le 7 metriche di qualifica, e lo stesso giorno per
 * ammettere anche importi NEGATIVI): l'admin seleziona uno o piu' agenti
 * dall'elenco /admin/mlm e assegna/toglie una quantita' — di punti cliente,
 * agenti Basic al 1° livello, o colonne con Key/Senior/Top/SuperVisor+/300
 * punti (vedi MlmMetricGrant::METRICS) — che NON scade mai. Un importo
 * positivo aggiunge, uno negativo toglie (es. -3 corregge un regalo fatto
 * per errore, senza dover revocare l'intero grant originale): entrambi si
 * sommano ai valori reali (vedi MlmRankEngine::evaluate() e
 * User::mlmActivePoints(), che clampano il totale combinato a >= 0). Un
 * agente puo' cosi' partire gia' da qualsiasi qualifica (fino a Manager)
 * senza aspettare l'accumulo/la struttura reale. Sono contatori astratti:
 * NON creano agenti o nodi veri nell'albero, quindi non alterano la vista
 * Albero ne' generano bonus di struttura (quelli restano legati solo alla
 * downline reale).
 *
 * Dopo l'assegnazione viene eseguito subito `mlm:recalculate-points`
 * (stesso pattern di MlmSettingsController::recalculateNow()): cosi' la
 * promozione/retrocessione, l'Extra Bonus una tantum e i Bonus Diretti
 * collegati partono subito, senza aspettare il cron notturno.
 */
class MlmMetricGrantController extends Controller
{
    use AuthorizesBackoffice;

    public function store(Request $request): RedirectResponse
    {
        $admin = $request->user();
        $this->authorizeBackoffice($admin);

        $validated = $request->validate([
            'agent_ids'         => ['required', 'array', 'min:1'],
            'agent_ids.*'       => ['integer', 'exists:users,id'],
            'metric'            => ['required', 'in:' . implode(',', array_keys(MlmMetricGrant::METRICS))],
            // Positivo = aggiunge, negativo = toglie (correzione); 0 non ha
            // senso (non farebbe nulla) e viene rifiutato esplicitamente.
            'amount'            => ['required', 'integer', 'not_in:0'],
            'reason'            => ['nullable', 'string', 'max:255'],
            'redirect_agent_id' => ['nullable', 'integer'],
        ]);

        $agents = User::query()
            ->whereIn('id', $validated['agent_ids'])
            ->where('mlm_role', 'agente')
            ->get();

        if ($agents->isEmpty()) {
            return back()->withErrors(['agent_ids' => 'Nessun agente valido selezionato.']);
        }

        foreach ($agents as $agent) {
            $grant = MlmMetricGrant::create([
                'agent_user_id'        => $agent->id,
                'metric'               => $validated['metric'],
                'amount'               => $validated['amount'],
                'reason'               => $validated['reason'] ?? null,
                'granted_by_admin_id'  => $admin->id,
            ]);

            AuditLog::create([
                'actor_user_id'   => $admin->id,
                'event'           => 'mlm.metric_grant_created',
                'auditable_type'  => User::class,
                'auditable_id'    => $agent->id,
                'context'         => [
                    'grant_id' => $grant->id,
                    'metric'   => $grant->metric,
                    'amount'   => $grant->amount,
                    'reason'   => $grant->reason,
                ],
            ]);
        }

        // Applica subito l'effetto (promozione + Extra Bonus + Bonus Diretti),
        // con la stessa cascata bottom-up del job notturno, invece di aspettare
        // il cron — stesso pattern di MlmSettingsController::recalculateNow().
        Artisan::call('mlm:recalculate-points');
        $output = trim(Artisan::output());

        $metricLabel = MlmMetricGrant::metricLabel($validated['metric']);
        $amount = $validated['amount'];
        $verb = $amount > 0 ? 'assegnati' : 'tolti';

        $successMessage = sprintf(
            '%+d %s omaggio %s a %d agenti. %s',
            $amount,
            $metricLabel,
            $verb,
            $agents->count(),
            $output
        );

        // Se la richiesta arriva dalla pagina "Promuovi agente" (singolo
        // agente), torna li' invece che all'indice — solo se l'agente e'
        // effettivamente tra quelli appena serviti (evita redirect arbitrari).
        $redirectAgentId = $validated['redirect_agent_id'] ?? null;
        if ($redirectAgentId !== null && $agents->contains('id', $redirectAgentId)) {
            return redirect()->route('admin.mlm.show', $redirectAgentId)
                ->with('portal_success', $successMessage);
        }

        return redirect()->route('admin.mlm.index')
            ->with('portal_success', $successMessage);
    }

    public function destroy(Request $request, MlmMetricGrant $mlmMetricGrant): RedirectResponse
    {
        $admin = $request->user();
        $this->authorizeBackoffice($admin);

        if ($mlmMetricGrant->revoked_at === null) {
            $mlmMetricGrant->forceFill([
                'revoked_at' => now(),
                'revoked_by_admin_id' => $admin->id,
            ])->save();

            AuditLog::create([
                'actor_user_id'   => $admin->id,
                'event'           => 'mlm.metric_grant_revoked',
                'auditable_type'  => User::class,
                'auditable_id'    => $mlmMetricGrant->agent_user_id,
                'context'         => [
                    'grant_id' => $mlmMetricGrant->id,
                    'metric'   => $mlmMetricGrant->metric,
                    'amount'   => $mlmMetricGrant->amount,
                ],
            ]);

            // Rivaluta subito (puo' generare una retrocessione se il grant
            // revocato era cio' che teneva l'agente sopra soglia).
            Artisan::call('mlm:recalculate-points');
        }

        return back()->with('portal_success', 'Regalo revocato.');
    }
}
