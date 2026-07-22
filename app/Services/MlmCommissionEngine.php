<?php

namespace App\Services;

use App\Models\MlmCommission;
use App\Models\MlmCommissionRun;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Motore commissioni mensili (dirette + indirette). Vedi MLM_PROPOSAL.md §5.
 *
 * Eseguito il 1° di ogni mese sulle righe attive quel giorno di
 * mlm_commission_base_ledger. UNA TANTUM DAL 2026-07-22 (decisione di Laura,
 * sostituisce lo smoothing deposito/12 per 12 mesi): ogni ricarica genera
 * UNA riga con l'INTERO importo, con finestra di validita' = il solo 1° del
 * mese successivo — quindi viene pagata dal primo run utile e mai piu'
 * (vedi MlmPointsService::createCommissionBaseEntry()). Le righe storiche
 * pre-2026-07-22 (importo mensile = deposito/12, finestra 12 mesi) restano
 * attive fino a scadenza naturale e continuano a pagare ogni mese.
 *
 * BASE = "PROV K", NON L'IMPORTO PIENO (2026-07-16, "le slide fanno fede"):
 * le tabelle "Esempio compensi" delle slide applicano tutte le percentuali
 * (dirette e indirette) a Prov K = importo x margine KNM — il
 * margine e' il parametro "30 %" / "10 %" in testa alle tabelle (colonna
 * "Prov K" = 30% di "MontImp"), configurabile da admin
 * (SystemSetting::mlmKnmMarginPercent(), default 30) e fotografato per
 * deposito in mlm_commission_base_ledger.knm_margin_percent (snapshot; NULL
 * sulle righe storiche = valore corrente del setting). Coerente con la
 * slide del residuale: "fino al 40% del COMPENSO KNM sulle vendite dirette".
 * Verificato riproducendo al centesimo tutte e 4 le tabelle delle slide
 * (vedi MlmSlideCompensationTablesTest).
 *
 *  - DIRETTA: l'agente diretto di un cliente guadagna una % (in base ai
 *    PROPRI punti attivi) sul Prov K mensile di quel cliente.
 *  - INDIRETTA: ogni agente guadagna una % fissa sul Prov K mensile dei
 *    clienti di ciascun agente della propria downline, in base al livello
 *    (1=4%, 2=2%, 3=1%, 4=0,5%, 5=8% — quest'ultima uniforme per QUALSIASI
 *    agente, vedi tabella "Compensi indiretti" in 2°ParteKnm.pptx, verificata
 *    numericamente in tutte le tabelle "Esempio compensi" delle 3 slide).
 *    Dal 6° livello in poi: 0,5% aggiuntivo, ma SOLO se l'agente beneficiario
 *    ha gia' grado Top/SuperVisor/Manager (slide "Compensi indiretti
 *    estesi") — per gli agenti di grado inferiore la commissione indiretta
 *    si ferma al 5° livello.
 *
 *    LIMITE "SLIDE LETTERALE" (2026-07-20, corretto il 2026-07-22 su
 *    decisione di Laura — sostituisce la breakaway del 2026-07-03 che si
 *    fermava al primo Top+ incontrato): "Il TOP percepisce provvigioni
 *    dello 0,5% su tutti i clienti al di sotto del 5° livello per un numero
 *    illimitato di livelli e fino al 5° livello del TOP seguente".
 *    Quindi: il blocco e' per grado PARI O SUPERIORE al beneficiario (un
 *    Top e' bloccato da Top, SuperVisor e Manager; un grado INFERIORE non
 *    blocca — un Top non ferma un SuperVisor, gerarchia
 *    Top < SuperVisor < Manager) e non si ferma AL nodo bloccante, ma 5
 *    livelli SOTTO il primo grado pari-o-superiore incontrato lungo ciascun
 *    ramo (il suo "5° livello", incluso). I livelli 1-5 del beneficiario
 *    pagano SEMPRE a tabella piena, anche se il bloccante sta nei primi 5
 *    livelli (bloccante al 2° => tabella su 1-5, poi 0,5% al 6° e 7°).
 *    Senza pari grado o superiore nel ramo la discesa e' illimitata.
 *
 *  - GATING INDIRETTE (tabella "Criteri per i Compensi Indiretti",
 *    2°ParteKnm.pptx slide 7 — implementato il 2026-07-13 su conferma di
 *    Laura): ogni livello indiretto 1-5 viene pagato SOLO se l'agente
 *    beneficiario soddisfa in proprio i requisiti minimi di quel livello
 *    (punti personali attivi a inizio mese + n. Basic al 1° livello):
 *    I=12pt/0 Basic, II=12pt/2 Basic, III=24pt/2 Basic, IV=24pt/2 Basic,
 *    V=48pt/3 Basic. L'estensione oltre il 5° livello resta legata al solo
 *    grado Top/SuperVisor/Manager (che i requisiti li incorpora gia', ed e'
 *    mantenuto allineato ogni notte da MlmRankEngine::syncRank()).
 */
class MlmCommissionEngine
{
    /** Soglie punti -> % diretta, verificate dalla piu' alta alla piu' bassa. */
    private const DIRECT_TABLE = [
        200 => 0.40,
        150 => 0.30,
        96 => 0.25,
        48 => 0.20,
        24 => 0.15,
        12 => 0.10,
        6 => 0.05,
        0 => 0.0,
    ];

    /** % indiretta per livello 1-5, uniforme per qualsiasi agente. Dal 6° livello: INDIRECT_BEYOND_LEVEL_5_PERCENTAGE, solo per Top/SuperVisor/Manager. */
    private const INDIRECT_PERCENTAGES = [
        1 => 0.04,
        2 => 0.02,
        3 => 0.01,
        4 => 0.005,
        5 => 0.08,
    ];

    private const INDIRECT_BEYOND_LEVEL_5_PERCENTAGE = 0.005;

    /**
     * Requisiti personali per incassare ciascun livello indiretto 1-5:
     * livello => [punti personali attivi minimi, Basic minimi al 1° livello].
     * Fonte: tabella "Criteri per i Compensi Indiretti" (2°ParteKnm slide 7).
     */
    private const INDIRECT_REQUIREMENTS = [
        1 => [12, 0],
        2 => [12, 2],
        3 => [24, 2],
        4 => [24, 2],
        5 => [48, 3],
    ];

    /** Qualifiche che godono dell'estensione oltre il 5° livello ("compensi indiretti estesi"). */
    private const EXTENDED_RANKS = ['top', 'supervisor', 'manager'];

    /** Livelli conteggiati SOTTO il primo grado pari o superiore incontrato ("fino al 5° livello del TOP seguente"). */
    private const LEVELS_BELOW_NEXT_SAME_OR_HIGHER_RANK = 5;

    public function __construct(private readonly MlmTreeService $tree) {}

    /** I punti attivi possono essere frazionari dal 2026-07-20 (1 punto ogni 50 EUR di importo mensile). */
    public function directPercentage(int|float $activePoints): float
    {
        foreach (self::DIRECT_TABLE as $threshold => $pct) {
            if ($activePoints >= $threshold) {
                return $pct;
            }
        }

        return 0.0;
    }

    /**
     * Stato di gating dei livelli indiretti 1-5 dato il profilo attivo di un
     * agente (punti attivi personali + n. Basic diretti al 1 livello), senza
     * dover camminare l'albero o toccare il database. Riusa le stesse soglie
     * di INDIRECT_REQUIREMENTS usate da calculateIndirect(), cosi' un
     * eventuale cambio di soglia resta a un solo posto. Usato anche dal
     * comando mlm:simula per mostrare quali livelli indiretti un agente
     * incasserebbe con numeri scelti a mano.
     *
     * @return array<int, array{required_points:int, required_basics:int, points_ok:bool, basics_ok:bool, met:bool}>
     */
    public function indirectGatingStatus(int|float $activePoints, int $level1BasicCount): array
    {
        $status = [];

        foreach (self::INDIRECT_REQUIREMENTS as $level => [$requiredPoints, $requiredBasics]) {
            $pointsOk = $activePoints >= $requiredPoints;
            $basicsOk = $level1BasicCount >= $requiredBasics;

            $status[$level] = [
                'required_points' => $requiredPoints,
                'required_basics' => $requiredBasics,
                'points_ok' => $pointsOk,
                'basics_ok' => $basicsOk,
                'met' => $pointsOk && $basicsOk,
            ];
        }

        return $status;
    }

    /**
     * Esegue (o recupera, se gia' completato) il calcolo commissioni per il
     * mese indicato. Idempotente a livello di run e di singola riga.
     */
    public function runForMonth(Carbon $month): MlmCommissionRun
    {
        $periodMonth = $month->copy()->startOfMonth();

        $run = MlmCommissionRun::whereDate('period_month', $periodMonth->toDateString())->first();

        if ($run && $run->status === 'completed') {
            return $run;
        }

        if (! $run) {
            $run = MlmCommissionRun::create([
                'period_month' => $periodMonth->toDateString(),
                'idempotency_key' => 'mlm_commissions_' . $periodMonth->format('Y-m'),
                'status' => 'running',
                'started_at' => now(),
            ]);
        } else {
            $run->forceFill(['status' => 'running', 'started_at' => now()])->save();
        }

        try {
            DB::transaction(function () use ($run, $periodMonth): void {
                $this->processMonth($run, $periodMonth);
            });

            $run->forceFill(['status' => 'completed', 'completed_at' => now()])->save();
        } catch (\Throwable $e) {
            $run->forceFill(['status' => 'failed', 'error' => $e->getMessage()])->save();
            throw $e;
        }

        return $run;
    }

    private function processMonth(MlmCommissionRun $run, Carbon $periodMonth): void
    {
        $dateString = $periodMonth->toDateString();

        // Tutte le righe di base commissioni attive per il mese in esame.
        $activeBaseRows = DB::table('mlm_commission_base_ledger')
            ->whereDate('valid_from', '<=', $dateString)
            ->whereDate('valid_until', '>=', $dateString)
            ->get();

        // Margine KNM corrente: fallback per le righe storiche senza snapshot
        // (create prima del 2026-07-16, colonna knm_margin_percent NULL).
        $fallbackMarginPercent = SystemSetting::mlmSettings()->mlmKnmMarginPercent();

        // Per ciascun agente diretto: mappa cliente -> PROV K mensile attivo
        // (= importo mensile x margine KNM della riga, arrotondato al
        // centesimo per riga; un cliente puo' avere piu' righe se ha fatto
        // piu' depositi ancora attivi). E' su questa base — il compenso KNM,
        // non l'importo pieno — che si applicano tutte le percentuali.
        $clientsByAgent = [];
        foreach ($activeBaseRows as $row) {
            $agentId = (int) $row->direct_agent_id;
            $clientId = (int) $row->client_user_id;
            $marginPercent = $row->knm_margin_percent !== null ? (int) $row->knm_margin_percent : $fallbackMarginPercent;
            $provK = (int) round((int) $row->monthly_amount_eur_cents * $marginPercent / 100);
            $clientsByAgent[$agentId][$clientId] = ($clientsByAgent[$agentId][$clientId] ?? 0) + $provK;
        }

        $agents = User::where('mlm_role', 'agente')->get()->keyBy('id');

        foreach ($agents as $agent) {
            $this->calculateDirect($agent, $clientsByAgent[$agent->id] ?? [], $run, $periodMonth);
            $this->calculateIndirect($agent, $clientsByAgent, $run, $periodMonth);
        }
    }

    /** Commissione diretta: % (su punti attivi dell'agente) su ciascun cliente diretto. */
    private function calculateDirect(User $agent, array $clients, MlmCommissionRun $run, Carbon $periodMonth): void
    {
        if (empty($clients)) {
            return;
        }

        $percentage = $this->directPercentage($agent->mlmActivePoints($periodMonth));
        if ($percentage <= 0) {
            return;
        }

        foreach ($clients as $clientId => $base) {
            $idempotencyKey = "mlm_commission_direct_{$run->id}_{$clientId}";
            if (MlmCommission::where('idempotency_key', $idempotencyKey)->exists()) {
                continue;
            }

            MlmCommission::create([
                'mlm_commission_run_id' => $run->id,
                'agent_user_id' => $agent->id,
                'type' => 'diretta',
                'source_client_id' => $clientId,
                'source_agent_id' => null,
                'level' => null,
                'base_amount_eur_cents' => $base,
                'percentage' => $percentage * 100,
                'amount_eur_cents' => (int) round($base * $percentage),
                'status' => 'pending',
                'idempotency_key' => $idempotencyKey,
            ]);
        }
    }

    /**
     * Commissione indiretta: cammina la downline di $agent livello per
     * livello (BFS), sommando le commissioni sui clienti di ogni agente
     * incontrato. Livelli 1-5: percentuale fissa per tutti (vedi
     * INDIRECT_PERCENTAGES). Livello 6+: solo se $agent ha gia' grado
     * Top/SuperVisor/Manager.
     *
     * LIMITE "SLIDE LETTERALE" (2026-07-20, corretto il 2026-07-22 — vedi
     * classe docblock): lungo ciascun ramo, quando si incontra il primo
     * agente con grado PARI O SUPERIORE a quello del beneficiario (per un
     * Top: un altro Top, un SuperVisor o un Manager), la discesa prosegue
     * solo fino a 5 livelli sotto quel nodo (il suo "5° livello", incluso)
     * e poi si ferma. Un grado INFERIORE non blocca (un Top sotto un
     * SuperVisor non interrompe il conteggio del SuperVisor). I livelli 1-5
     * pagano sempre a tabella piena. Senza grado pari o superiore nel ramo
     * la discesa e' illimitata.
     */
    private function calculateIndirect(User $agent, array $clientsByAgent, MlmCommissionRun $run, Carbon $periodMonth): void
    {
        $agentQualifiesForExtension = in_array($agent->mlm_rank, self::EXTENDED_RANKS, true);

        // Requisiti personali dell'agente beneficiario per il gating dei
        // livelli 1-5 (punti attivi a inizio mese + Basic diretti attuali).
        $agentPoints = $agent->mlmActivePoints($periodMonth);
        $agentLevel1Basics = $this->countLevel1Basics($agent);

        // Se non soddisfa nemmeno il livello I (12 punti) e non gode
        // dell'estensione di grado, nessun livello potra' mai pagare:
        // inutile camminare l'albero.
        if ($agentPoints < 12 && ! $agentQualifiesForExtension) {
            return;
        }

        // 'stop_depth': profondita' massima conteggiabile lungo questo ramo
        // (null = nessun grado pari o superiore incontrato finora, discesa
        // illimitata).
        $agentRankLevel = $this->rankLevel($agent->mlm_rank);
        $queue = [];
        foreach ($this->tree->directDownline($agent) as $child) {
            $queue[] = ['agent' => $child, 'depth' => 1, 'stop_depth' => null];
        }

        while (! empty($queue)) {
            $item = array_shift($queue);
            $node = $item['agent'];
            $depth = $item['depth'];
            $stopDepth = $item['stop_depth'];

            if ($depth <= 5) {
                [$requiredPoints, $requiredBasics] = self::INDIRECT_REQUIREMENTS[$depth];
                $meetsRequirements = $agentPoints >= $requiredPoints && $agentLevel1Basics >= $requiredBasics;
                $percentage = $meetsRequirements ? self::INDIRECT_PERCENTAGES[$depth] : 0.0;
            } elseif ($agentQualifiesForExtension) {
                $percentage = self::INDIRECT_BEYOND_LEVEL_5_PERCENTAGE;
            } else {
                $percentage = 0.0;
            }

            if ($percentage > 0) {
                foreach (($clientsByAgent[$node->id] ?? []) as $clientId => $base) {
                    $idempotencyKey = "mlm_commission_indirect_{$run->id}_{$agent->id}_{$clientId}";
                    if (MlmCommission::where('idempotency_key', $idempotencyKey)->exists()) {
                        continue;
                    }

                    MlmCommission::create([
                        'mlm_commission_run_id' => $run->id,
                        'agent_user_id' => $agent->id,
                        'type' => 'indiretta',
                        'source_client_id' => $clientId,
                        'source_agent_id' => $node->id,
                        'level' => $depth,
                        'base_amount_eur_cents' => $base,
                        'percentage' => $percentage * 100,
                        'amount_eur_cents' => (int) round($base * $percentage),
                        'status' => 'pending',
                        'idempotency_key' => $idempotencyKey,
                    ]);
                }
            }

            // Oltre il 5° livello non ha senso proseguire se l'agente non e'
            // Top/SuperVisor/Manager: nessun livello successivo genererebbe
            // mai commissioni per lui.
            if ($depth >= 5 && ! $agentQualifiesForExtension) {
                continue;
            }

            // Primo grado PARI O SUPERIORE al beneficiario lungo questo ramo
            // (2026-07-22: un grado superiore blocca come il pari grado; un
            // grado inferiore no): da qui la discesa prosegue solo fino al
            // suo "5° livello" (depth + 5).
            if ($agentQualifiesForExtension && $stopDepth === null && $this->rankLevel($node->mlm_rank) >= $agentRankLevel) {
                $stopDepth = $depth + self::LEVELS_BELOW_NEXT_SAME_OR_HIGHER_RANK;
            }

            // Raggiunto il 5° livello del grado pari-o-superiore seguente: stop al ramo.
            if ($stopDepth !== null && $depth >= $stopDepth) {
                continue;
            }

            foreach ($this->tree->directDownline($node) as $child) {
                $queue[] = ['agent' => $child, 'depth' => $depth + 1, 'stop_depth' => $stopDepth];
            }
        }
    }

    /** Numero di figli diretti (1° livello) con qualifica >= basic. */
    private function countLevel1Basics(User $agent): int
    {
        $basicLevel = $this->rankLevel('basic');

        return $this->tree->directDownline($agent)
            ->filter(fn (User $child) => $this->rankLevel($child->mlm_rank) >= $basicLevel)
            ->count();
    }

    private function rankLevel(string $rank): int
    {
        $index = array_search($rank, User::MLM_RANK_ORDER, true);

        return $index === false ? 0 : $index;
    }
}
