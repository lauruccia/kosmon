<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\MlmRankHistory;
use App\Models\MlmRankRequirement;
use App\Models\User;
use App\Notifications\MlmRankDemotedNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Motore di valutazione delle qualifiche agente. Vedi MLM_PROPOSAL.md §4.2.
 *
 * I requisiti NON sono piu' hardcoded qui dal 2026-07-13: vengono letti da
 * `mlm_rank_requirements` (vedi MlmRankRequirement), editabile da admin in
 * /admin/mlm-impostazioni — introdotto su richiesta di Laura per poter
 * testare rapidamente cambi di soglia senza toccare il codice. I valori di
 * default seedati dalla migration sono ESATTAMENTE quelli confermati dal
 * testo letterale della slide "Qualifiche" KNM ufficiale (screenshot
 * caricato in chat il 2026-07-13 — fa fede su qualunque altra fonte, incluso
 * il foglio mlm_piano.xlsx che su SuperVisor/Manager usava una notazione a
 * punti PS/PT/PSPV/PM ambigua e meno letterale):
 *
 *   Basic = 12 pt
 *   Key   = 24 pt + 2 Basic al 1° liv.
 *   Senior = 48 pt + 3 Basic al 1° liv. + 2 Key su 2 colonne diverse
 *   Top    = 48 pt + 4 Basic al 1° liv. + 3 colonne da 300 punti attivi
 *   SuperVisor = 48 pt + 5 Basic al 1° liv. + 2 Senior e 2 Top su 4 colonne diverse
 *   Manager    = 48 pt + 6 Basic al 1° liv. + 3 SuperVisor su 3 colonne diverse
 *
 * Il numero di Basic al 1° livello cresce in modo monotono con il grado
 * (2/3/4/5/6) nei default. Per SuperVisor, "2 Senior e 2 Top" e' implementato
 * come branchesWithTop>=2 (le 2 colonne Top) E branchesWithSenior>=4 (il
 * totale delle colonne con almeno un Senior, che include gia' le 2 Top dato
 * che Top > Senior nell'ordine dei gradi) — equivalente esatto del testo
 * della slide, vedi countBranchesWithMinRank(). Ogni qualifica e' comunque
 * un requisito indipendente, NON una progressione stretta: il motore valuta
 * TUTTE le qualifiche (ciascuna contro la propria riga di configurazione) e
 * assegna la piu' alta soddisfatta (es. un agente puo' soddisfare "manager"
 * senza soddisfare "top" o "supervisor", se non ha le colonne da 300 punti
 * ma ha 3 SuperVisor in downline).
 *
 * RETROCESSIONE (confermata da Laura il 2026-07-13): i punti hanno una
 * finestra di validita' (mlm_point_ledger.valid_from/valid_until, a
 * precisione di minuto dal 2026-07-13 — vedi SystemSetting::mlmSettings())
 * e quando scadono i requisiti possono venire meno. syncRank() allinea
 * quindi il grado in ENTRAMBE le direzioni — promuove e retrocede — fino a
 * "start", senza grado minimo garantito. Il flag storico BasiQ
 * (mlm_basiq_at) non viene mai toccato. Nessun ricalcolo retroattivo di
 * bonus/commissioni gia' generati: la retrocessione vale solo dalle
 * valutazioni successive.
 */
class MlmRankEngine
{
    /** Ordine crescente delle qualifiche valutate da questo motore (esclude "start"). */
    private const ORDER = ['basic', 'key', 'senior', 'top', 'supervisor', 'manager'];

    /**
     * Mappa metrica calcolata (chiave di ritorno di evaluate()) => campo
     * corrispondente in MlmRankRequirement. Un'unica fonte per il confronto
     * generico soglia-per-soglia usato sia in evaluate() che in
     * nextRankRequirements() (con le relative etichette, vedi RANK_LABELS).
     */
    private const METRIC_TO_REQUIREMENT_FIELD = [
        'points' => 'min_points',
        'level1_basic_count' => 'min_level1_basic',
        'branches_with_key' => 'min_branches_with_key',
        'branches_with_senior' => 'min_branches_with_senior',
        'branches_with_top' => 'min_branches_with_top',
        'branches_with_supervisor' => 'min_branches_with_supervisor',
        'branches_300pt' => 'min_branches_300pt',
    ];

    /** Etichette leggibili per la checklist di nextRankRequirements(), stesso ordine di METRIC_TO_REQUIREMENT_FIELD. */
    private const RANK_LABELS = [
        'min_points' => 'Punti attivi',
        'min_level1_basic' => 'Basic al 1° livello',
        'min_branches_with_key' => 'Colonne con almeno un Key+',
        'min_branches_with_senior' => 'Colonne con almeno un Senior+',
        'min_branches_with_top' => 'Colonne con almeno un Top+',
        'min_branches_with_supervisor' => 'Colonne con almeno un SuperVisor+',
        'min_branches_300pt' => 'Colonne con >= 300 punti',
    ];

    public function __construct(
        private readonly MlmTreeService $tree,
        private readonly MlmAwardService $awards,
    ) {}

    /**
     * Valuta l'agente e restituisce un array diagnostico completo:
     * - 'points' => punti attivi
     * - 'level1_basic_count' => n. figli diretti con rank >= basic
     * - 'branches_300pt' => n. colonne con >= 300 punti attivi
     * - 'branches_with_key' => n. colonne con almeno un agente >= key
     * - 'branches_with_senior' => n. colonne con almeno un agente >= senior
     * - 'branches_with_top' => n. colonne con almeno un agente >= top
     * - 'branches_with_supervisor' => n. colonne con almeno un agente >= supervisor
     * - 'satisfied' => ['basic' => bool, 'key' => bool, ...]
     * - 'eligible_rank' => qualifica piu' alta soddisfatta (stringa)
     */
    public function evaluate(User $agent): array
    {
        $branches = $this->tree->branchSummaries($agent);

        // $points include gia' gli eventuali punti "omaggio" assegnati da un
        // admin (vedi User::mlmActivePoints()). Tutte le altre metriche
        // sono calcolate dalla downline REALE e poi sommate al relativo
        // "regalo" omaggio (MlmMetricGrant, esteso il 2026-07-15 dalle sole
        // points/level1_basic_count anche alle 4 metriche di colonna-rango
        // e a branches_300pt) — un admin puo' cosi' far scattare qualsiasi
        // qualifica (fino a Manager) anche senza che la struttura sotto
        // esista ancora davvero. Sono contatori astratti: non creano nulla
        // nell'albero vero, quindi non alterano ne' la vista Albero ne' i
        // bonus di struttura (quelli restano legati solo alla downline reale).
        //
        // Dal 2026-07-15 il "regalo" puo' essere negativo (un admin puo'
        // correggere/togliere quanto assegnato): il totale combinato con il
        // valore reale non scende mai sotto zero (max(0, ...) sotto).
        $points = $agent->mlmActivePoints();
        $level1BasicCount = max(0, $branches->filter(
            fn (array $b) => $this->rankLevel($b['branch_root']->mlm_rank) >= $this->rankLevel('basic')
        )->count() + $agent->mlmGrantedLevel1Basic());

        $branches300pt = max(0, $branches->filter(fn (array $b) => $b['active_points'] >= 300)->count()
            + $agent->mlmGrantedMetric('branches_300pt'));
        $branchesWithKey = max(0, $this->countBranchesWithMinRank($branches, 'key')
            + $agent->mlmGrantedMetric('branches_with_key'));
        $branchesWithSenior = max(0, $this->countBranchesWithMinRank($branches, 'senior')
            + $agent->mlmGrantedMetric('branches_with_senior'));
        $branchesWithTop = max(0, $this->countBranchesWithMinRank($branches, 'top')
            + $agent->mlmGrantedMetric('branches_with_top'));
        $branchesWithSupervisor = max(0, $this->countBranchesWithMinRank($branches, 'supervisor')
            + $agent->mlmGrantedMetric('branches_with_supervisor'));

        $metrics = [
            'points' => $points,
            'level1_basic_count' => $level1BasicCount,
            'branches_with_key' => $branchesWithKey,
            'branches_with_senior' => $branchesWithSenior,
            'branches_with_top' => $branchesWithTop,
            'branches_with_supervisor' => $branchesWithSupervisor,
            'branches_300pt' => $branches300pt,
        ];

        $requirements = MlmRankRequirement::allByRank();

        $satisfied = [];
        foreach (self::ORDER as $rank) {
            $satisfied[$rank] = $this->meetsRequirement($metrics, $requirements->get($rank));
        }

        $eligibleRank = 'start';
        foreach (self::ORDER as $rank) {
            if ($satisfied[$rank]) {
                $eligibleRank = $rank;
            }
        }

        return [
            'points' => $points,
            'level1_basic_count' => $level1BasicCount,
            'branches_300pt' => $branches300pt,
            'branches_with_key' => $branchesWithKey,
            'branches_with_senior' => $branchesWithSenior,
            'branches_with_top' => $branchesWithTop,
            'branches_with_supervisor' => $branchesWithSupervisor,
            'satisfied' => $satisfied,
            'eligible_rank' => $eligibleRank,
        ];
    }

    /**
     * Allinea il grado dell'agente alla qualifica piu' alta soddisfatta,
     * in ENTRAMBE le direzioni: promuove se superiore all'attuale,
     * RETROCEDE se inferiore (es. punti scaduti nel ledger — confermato
     * da Laura il 2026-07-13, senza grado minimo garantito). Registra lo
     * storico e l'audit log in entrambi i casi.
     *
     * Restituisce 'promoted', 'demoted' oppure null se il grado era gia'
     * corretto.
     */
    public function syncRank(User $agent): ?string
    {
        $evaluation = $this->evaluate($agent);
        $eligibleRank = $evaluation['eligible_rank'];

        $eligibleLevel = $this->rankLevel($eligibleRank);
        $currentLevel = $this->rankLevel($agent->mlm_rank);

        if ($eligibleLevel === $currentLevel) {
            return null;
        }

        $direction = $eligibleLevel > $currentLevel ? 'promoted' : 'demoted';
        $previousRank = $agent->mlm_rank;

        $agent->forceFill([
            'mlm_rank' => $eligibleRank,
            'mlm_rank_updated_at' => now(),
        ])->save();

        MlmRankHistory::create([
            'agent_user_id' => $agent->id,
            'rank' => $eligibleRank,
            'achieved_at' => now(),
            'evaluation_snapshot' => $evaluation,
        ]);

        AuditLog::create([
            'actor_user_id' => null,
            'event' => $direction === 'promoted' ? 'mlm.rank_promoted' : 'mlm.rank_demoted',
            'auditable_type' => User::class,
            'auditable_id' => $agent->id,
            'context' => [
                'previous_rank' => $previousRank,
                'new_rank' => $eligibleRank,
                'evaluation' => $evaluation,
            ],
        ]);

        if ($direction === 'promoted') {
            // Extra Bonus una tantum alla prima promozione a senior+
            // (2026-07-13). Dal 2026-07-15 la promozione viene solo
            // ACCODATA qui (rilevamento notturno): l'erogazione vera e
            // propria del premio avviene nel job settimanale del mercoledi'
            // (mlm:calculate-weekly-bonuses), vedi MlmAwardService.
            $this->awards->queueRankAward($agent, $eligibleRank);
        } else {
            // La retrocessione viene comunicata all'agente (email + in-app).
            // Il grado, lo storico e l'audit log sopra sono gia' salvati a
            // prescindere dall'esito della notifica: un fallimento di invio
            // (mailbox inesistente, SMTP giu', bounce 550...) NON deve far
            // fallire syncRank() ne' interrompere il resto della passata
            // bottom-up su mlm:recalculate-points (un solo agente con email
            // non recapitabile bloccava prima l'intero ricalcolo con un 500,
            // vedi incident 2026-07-13 su /admin/mlm-impostazioni/ricalcola).
            try {
                $agent->notify(new MlmRankDemotedNotification($previousRank, $eligibleRank));
            } catch (\Throwable $e) {
                Log::warning('mlm.rank_demoted_notification_failed', [
                    'agent_id' => $agent->id,
                    'previous_rank' => $previousRank,
                    'new_rank' => $eligibleRank,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $direction;
    }

    /**
     * Checklist dei requisiti per la PROSSIMA qualifica rispetto a quella
     * attuale dell'agente (es. se e' "key", restituisce i requisiti di
     * "senior"). Restituisce null se l'agente e' gia' al grado massimo.
     * Ogni voce: ['label' => string, 'required' => int, 'current' => int, 'met' => bool].
     */
    public function nextRankRequirements(User $agent): ?array
    {
        $currentLevel = $this->rankLevel($agent->mlm_rank);
        if ($currentLevel >= count(self::ORDER)) {
            return null;
        }

        $nextRank = self::ORDER[$currentLevel];
        $evaluation = $this->evaluate($agent);
        $requirement = MlmRankRequirement::allByRank()->get($nextRank);

        $items = [];
        if ($requirement !== null) {
            foreach (self::METRIC_TO_REQUIREMENT_FIELD as $metric => $field) {
                $required = (int) $requirement->{$field};
                if ($required <= 0) {
                    // Soglia non richiesta per questo grado (es. 0 colonne Key
                    // per "basic"): non mostrarla nella checklist.
                    continue;
                }

                // I punti attivi possono essere frazionari dal 2026-07-20
                // (slide "Importo Personale Mensile"): niente cast a int,
                // che troncherebbe 12,2 a 12 nella checklist.
                $current = $metric === 'points'
                    ? mlm_points_normalize($evaluation[$metric])
                    : (int) $evaluation[$metric];
                $items[] = [
                    'label' => self::RANK_LABELS[$field],
                    'required' => $required,
                    'current' => $current,
                    'met' => $current >= $required,
                ];
            }
        }

        return [
            'rank' => $nextRank,
            'items' => $items,
        ];
    }

    /**
     * Vero se tutte le metriche calcolate soddisfano (>=) la relativa soglia
     * configurata per il grado. Nessuna riga di configurazione per il grado
     * (non dovrebbe mai succedere dopo il seed della migration) => grado mai
     * raggiungibile: "fail closed" invece di promuovere per errore con
     * soglie implicite a zero.
     *
     * @param array<string,int> $metrics
     */
    private function meetsRequirement(array $metrics, ?MlmRankRequirement $requirement): bool
    {
        if ($requirement === null) {
            return false;
        }

        foreach (self::METRIC_TO_REQUIREMENT_FIELD as $metric => $field) {
            if ($metrics[$metric] < $requirement->{$field}) {
                return false;
            }
        }

        return true;
    }

    /** Numero di colonne (rami di 1° livello) che contengono almeno un agente di rank >= $minRank. */
    private function countBranchesWithMinRank(Collection $branches, string $minRank): int
    {
        $minLevel = $this->rankLevel($minRank);

        return $branches->filter(function (array $branch) use ($minLevel) {
            foreach ($branch['rank_counts'] as $rank => $count) {
                if ($count > 0 && $this->rankLevel($rank) >= $minLevel) {
                    return true;
                }
            }

            return false;
        })->count();
    }

    private function rankLevel(string $rank): int
    {
        $index = array_search($rank, User::MLM_RANK_ORDER, true);

        return $index === false ? 0 : $index;
    }
}
