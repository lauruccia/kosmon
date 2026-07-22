<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\AuthorizesBackoffice;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\MlmPointRule;
use App\Models\MlmRankRequirement;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\MlmTreeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\View\View;

/**
 * Pannello admin per configurare i requisiti di qualifica agente (Basic..
 * Manager, tabella mlm_rank_requirements), la scadenza dei punti cliente
 * (SystemSetting::mlmSettings()) e — dal 2026-07-22 — i punti "apertura
 * conto" (mlm_point_rules, riga registration). I punti delle ricariche si
 * gestiscono invece sulle KY Card reali in /admin/ky-cards (qui vengono
 * solo mostrati). Introdotto il 2026-07-13 su richiesta di Laura.
 */
class MlmSettingsController extends Controller
{
    use AuthorizesBackoffice;

    private const REQUIREMENT_FIELDS = [
        'min_points',
        'min_clients',
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

    public function edit(Request $request, MlmTreeService $treeService): View
    {
        $this->authorizeBackoffice($request->user());

        $requirements = MlmRankRequirement::query()->get()->keyBy('rank');

        return view('admin.mlm.settings', [
            'pageTitle' => 'MLM — Impostazioni qualifiche',
            'requirements' => $requirements,
            // Punti per evento (2026-07-22): riga registrazione editabile
            // qui; i punti delle ricariche vivono sulle KY Card reali
            // (/admin/ky-cards) e qui vengono solo mostrati.
            'registrationRule' => MlmPointRule::registrationRule(),
            'kyCards' => \App\Models\KyCard::orderBy('sort_order')->orderBy('price_eur_cents')->get(),
            'ranks' => $this->configurableRanks(),
            'pointsValidityOverrideMinutes' => SystemSetting::mlmSettings()->mlm_points_validity_override_minutes,
            'knmMarginPercent' => SystemSetting::mlmSettings()->mlmKnmMarginPercent(),
            'currentRootAgent' => $treeService->systemRootAgent(),
            'activeNav' => 'mlm',
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $this->authorizeBackoffice($request->user());

        $ranks = $this->configurableRanks();

        $rules = [
            'points_validity_override_minutes' => ['nullable', 'integer', 'min:1'],
            // Margine KNM ("Prov K"): percentuale del compenso KNM su cui si
            // calcolano TUTTE le commissioni (2026-07-16). Nullable per
            // retro-compatibilita' (assente/vuoto = default 30, vedi
            // SystemSetting::mlmKnmMarginPercent()).
            'knm_margin_percent' => ['nullable', 'integer', 'min:1', 'max:100'],
            // Punti per evento (2026-07-22): qui si configura solo
            // l'apertura conto (points puo' essere 0 = evento disabilitato).
            // I punti delle ricariche si impostano sulle KY Card in
            // /admin/ky-cards (durata in giorni; "1 mese = 30 giorni").
            'registration_points' => ['required', 'numeric', 'min:0', 'max:999999'],
            'registration_duration_days' => ['required', 'integer', 'min:1', 'max:36500'],
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

        // ── Punti per evento: riga apertura conto (2026-07-22) ──
        // (points=0 = evento disabilitato). I punti delle ricariche NON si
        // toccano qui: vivono sulle KY Card reali in /admin/ky-cards.
        MlmPointRule::updateOrCreate(
            ['event_type' => MlmPointRule::EVENT_REGISTRATION],
            [
                'points' => round((float) $validated['registration_points'], 2),
                'duration_days' => (int) $validated['registration_duration_days'],
            ]
        );

        $settings = SystemSetting::mlmSettings();
        $before = $settings->mlm_points_validity_override_minutes;
        $after = $validated['points_validity_override_minutes'] ?? null;

        $marginBefore = $settings->mlmKnmMarginPercent();
        $marginAfter = isset($validated['knm_margin_percent'])
            ? (int) $validated['knm_margin_percent']
            : SystemSetting::MLM_KNM_MARGIN_DEFAULT_PERCENT;

        $settings->forceFill([
            'mlm_points_validity_override_minutes' => $after,
            'mlm_knm_margin_percent' => $marginAfter,
        ])->save();

        AuditLog::create([
            'actor_user_id' => $request->user()->id,
            'event' => 'admin.mlm.settings_updated',
            'auditable_type' => SystemSetting::class,
            'auditable_id' => $settings->id,
            'context' => [
                'requirements' => $validated['requirements'],
                'points_validity_override_minutes_before' => $before,
                'points_validity_override_minutes_after' => $after,
                'knm_margin_percent_before' => $marginBefore,
                'knm_margin_percent_after' => $marginAfter,
                'point_rules' => MlmPointRule::orderBy('event_type')
                    ->get(['event_type', 'points', 'duration_days'])
                    ->toArray(),
            ],
        ]);

        return redirect()->route('admin.mlm.settings.edit')
            ->with('portal_success', 'Impostazioni MLM aggiornate.');
    }

    /**
     * Esegue subito `mlm:recalculate-points` (normalmente notturno, 03:00)
     * per verificare l'effetto delle nuove soglie/scadenze senza aspettare
     * il cron — comodo soprattutto dopo aver abbassato la scadenza punti per
     * un test rapido. Dal 2026-07-15 lancia anche subito dopo
     * `mlm:calculate-weekly-bonuses` (normalmente del mercoledi'), cosi' il
     * pulsante "Ricalcola ora" applica per intero l'effetto — qualifiche E
     * bonus/extra bonus — invece di lasciare i bonus in attesa del mercoledi'
     * successivo durante un test manuale.
     */
    public function recalculateNow(Request $request): RedirectResponse
    {
        $this->authorizeBackoffice($request->user());

        Artisan::call('mlm:recalculate-points');
        $pointsOutput = trim(Artisan::output());

        Artisan::call('mlm:calculate-weekly-bonuses');
        $bonusesOutput = trim(Artisan::output());

        $output = $pointsOutput . "\n" . $bonusesOutput;

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

    /**
     * GET /admin/mlm-impostazioni/radice — pagina per designare l'unica
     * radice del sistema MLM (2026-07-15, vedi
     * MlmTreeService::systemRootAgent()/setSystemRootAgent()). Mostra la
     * radice attuale, il conteggio degli alberi indipendenti ancora da
     * consolidare, e un elenco cercabile/paginato di agenti candidati
     * (stesso pattern di ricerca di Admin\MlmController::moveForm()).
     */
    public function rootAgentForm(Request $request, MlmTreeService $treeService): View
    {
        $this->authorizeBackoffice($request->user());

        $search = trim((string) $request->query('q', ''));

        $candidates = User::query()
            ->where('mlm_role', 'agente')
            ->when($search, fn ($q) => $q->where(fn ($qq) => $qq
                ->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")))
            ->orderBy('name')
            ->paginate(20)->withQueryString();

        $currentRoot = $treeService->systemRootAgent();

        return view('admin.mlm.settings-root', [
            'pageTitle' => 'MLM — Agente radice',
            'currentRoot' => $currentRoot,
            // Alberi indipendenti ancora da consolidare: tutti gli agenti
            // senza sponsor, esclusa la radice designata stessa (che e'
            // anch'essa senza sponsor per costruzione).
            'orphanCount' => max(0, $treeService->rootAgents()->count() - ($currentRoot ? 1 : 0)),
            'candidates' => $candidates,
            'search' => $search,
            'activeNav' => 'mlm',
        ]);
    }

    /**
     * POST /admin/mlm-impostazioni/radice — designa la nuova radice unica,
     * consolidando automaticamente ogni albero indipendente esistente sotto
     * di essa (MlmTreeService::setSystemRootAgent()).
     */
    public function updateRootAgent(Request $request, MlmTreeService $treeService): RedirectResponse
    {
        $this->authorizeBackoffice($request->user());

        $validated = $request->validate([
            'root_agent_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        $newRoot = User::findOrFail($validated['root_agent_id']);

        $consolidated = $treeService->setSystemRootAgent($newRoot, $request->user());

        return redirect()->route('admin.mlm.settings.root-agent')
            ->with('portal_success', sprintf(
                '%s designato come radice unica del sistema. %d %s consolidat%s sotto di lui.',
                $newRoot->name,
                $consolidated,
                $consolidated === 1 ? 'albero indipendente' : 'alberi indipendenti',
                $consolidated === 1 ? 'o' : 'i'
            ));
    }
}
