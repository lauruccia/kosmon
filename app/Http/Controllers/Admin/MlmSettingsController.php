<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\AuthorizesBackoffice;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\MlmRankRequirement;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\View\View;

/**
 * Pannello admin per configurare i requisiti di qualifica agente (Basic..
 * Manager, tabella mlm_rank_requirements) e la scadenza dei punti cliente
 * (SystemSetting::mlmSettings()) — entrambi normalmente fissi nel codice
 * (vedi MlmRankEngine, MlmPointsService) ma resi editabili per permettere
 * test rapidi (es. scadenza punti a 1 ora invece che mesi) senza toccare il
 * codice. Introdotto il 2026-07-13 su richiesta di Laura.
 */
class MlmSettingsController extends Controller
{
    use AuthorizesBackoffice;

    private const REQUIREMENT_FIELDS = [
        'min_points',
        'min_level1_basic',
        'min_branches_with_key',
        'min_branches_with_senior',
        'min_branches_with_top',
        'min_branches_with_supervisor',
        'min_branches_300pt',
    ];

    /** Ranks configurabili: tutti tranne "start", che è il grado di default senza requisiti. */
    private function configurableRanks(): array
    {
        return array_values(array_diff(User::MLM_RANK_ORDER, ['start']));
    }

    public function edit(Request $request): View
    {
        $this->authorizeBackoffice($request->user());

        $requirements = MlmRankRequirement::query()->get()->keyBy('rank');

        return view('admin.mlm.settings', [
            'pageTitle' => 'MLM — Impostazioni qualifiche',
            'requirements' => $requirements,
            'ranks' => $this->configurableRanks(),
            'pointsValidityOverrideMinutes' => SystemSetting::mlmSettings()->mlm_points_validity_override_minutes,
            'activeNav' => 'mlm',
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $this->authorizeBackoffice($request->user());

        $ranks = $this->configurableRanks();

        $rules = [
            'points_validity_override_minutes' => ['nullable', 'integer', 'min:1'],
        ];
        foreach ($ranks as $rank) {
            foreach (self::REQUIREMENT_FIELDS as $field) {
                $rules["requirements.{$rank}.{$field}"] = ['required', 'integer', 'min:0'];
            }
        }

        $validated = $request->validate($rules);

        foreach ($ranks as $rank) {
            MlmRankRequirement::updateOrCreate(
                ['rank' => $rank],
                $validated['requirements'][$rank]
            );
        }

        $settings = SystemSetting::mlmSettings();
        $before = $settings->mlm_points_validity_override_minutes;
        $after = $validated['points_validity_override_minutes'] ?? null;

        $settings->forceFill(['mlm_points_validity_override_minutes' => $after])->save();

        AuditLog::create([
            'actor_user_id' => $request->user()->id,
            'event' => 'admin.mlm.settings_updated',
            'auditable_type' => SystemSetting::class,
            'auditable_id' => $settings->id,
            'context' => [
                'requirements' => $validated['requirements'],
                'points_validity_override_minutes_before' => $before,
                'points_validity_override_minutes_after' => $after,
            ],
        ]);

        return redirect()->route('admin.mlm.settings.edit')
            ->with('portal_success', 'Impostazioni MLM aggiornate.');
    }

    /**
     * Esegue subito `mlm:recalculate-points` (normalmente notturno, 03:00)
     * per verificare l'effetto delle nuove soglie/scadenze senza aspettare
     * il cron — comodo soprattutto dopo aver abbassato la scadenza punti per
     * un test rapido.
     */
    public function recalculateNow(Request $request): RedirectResponse
    {
        $this->authorizeBackoffice($request->user());

        Artisan::call('mlm:recalculate-points');
        $output = trim(Artisan::output());

        AuditLog::create([
            'actor_user_id' => $request->user()->id,
            'event' => 'admin.mlm.manual_recalculate',
            'auditable_type' => User::class,
            'auditable_id' => $request->user()->id,
            'context' => ['output' => $output],
        ]);

        return redirect()->route('admin.mlm.settings.edit')
            ->with('portal_success', 'Ricalcolo eseguito. ' . $output);
    }
}
