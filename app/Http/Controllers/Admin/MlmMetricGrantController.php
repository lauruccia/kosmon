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
 * "Punti/agenti omaggio" (richiesta di Laura, 2026-07-14): l'admin seleziona
 * uno o piu' agenti dall'elenco /admin/mlm e assegna loro una base di punti
 * cliente e/o di "Basic al 1° livello" che NON scade mai (MlmMetricGrant).
 * Si sommano ai valori reali (vedi MlmRankEngine::evaluate() e
 * User::mlmActivePoints()): un agente puo' cosi' partire gia' da una
 * qualifica senza aspettare l'accumulo naturale, MA i requisiti legati alle
 * colonne/downline reale (Key/Senior/Top/SuperVisor, colonne da 300 punti)
 * restano legati alla struttura vera — non sono "regalabili" da qui.
 *
 * Dopo l'assegnazione viene eseguito subito `mlm:recalculate-points`
 * (stesso pattern di MlmSettingsController::recalculateNow()): cosi' la
 * promozione, l'Extra Bonus una tantum e i Bonus Diretti collegati partono
 * come per una promozione normale, senza aspettare il cron notturno.
 */
class MlmMetricGrantController extends Controller
{
    use AuthorizesBackoffice;

    public function store(Request $request): RedirectResponse
    {
        $admin = $request->user();
        $this->authorizeBackoffice($admin);

        $validated = $request->validate([
            'agent_ids'   => ['required', 'array', 'min:1'],
            'agent_ids.*' => ['integer', 'exists:users,id'],
            'metric'      => ['required', 'in:points,level1_basic_count'],
            'amount'      => ['required', 'integer', 'min:1'],
            'reason'      => ['nullable', 'string', 'max:255'],
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

        $metricLabel = $validated['metric'] === 'points' ? 'punti cliente' : 'agenti Basic al 1° livello';

        return redirect()->route('admin.mlm.index')
            ->with('portal_success', sprintf(
                '%d %s omaggio assegnati a %d agenti. %s',
                $validated['amount'],
                $metricLabel,
                $agents->count(),
                $output
            ));
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
