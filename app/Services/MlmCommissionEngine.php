<?php

namespace App\Services;

use App\Models\MlmCommission;
use App\Models\MlmCommissionRun;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Motore commissioni mensili (dirette + indirette). Vedi MLM_PROPOSAL.md §5.
 *
 * Eseguito il 1° di ogni mese sulla base dell'"importo mensile" attivo di
 * ciascun cliente (mlm_commission_base_ledger, stesso smoothing dei punti):
 *
 *  - DIRETTA: l'agente diretto di un cliente guadagna una % (in base ai
 *    PROPRI punti attivi) sull'importo mensile di quel cliente.
 *  - INDIRETTA: ogni agente guadagna una % fissa sull'importo mensile dei
 *    clienti di ciascun agente della propria downline, in base al livello
 *    (1=4%, 2=2%, 3=1%, 4=0,5%, 5=8% — quest'ultima uniforme per QUALSIASI
 *    agente, vedi tabella "Compensi indiretti" in 2°ParteKnm.pptx, verificata
 *    numericamente in tutte le tabelle "Esempio compensi" delle 3 slide).
 *    Dal 6° livello in poi: 0,5% aggiuntivo, ma SOLO se l'agente beneficiario
 *    ha gia' grado Top/SuperVisor/Manager (slide "Compensi indiretti
 *    estesi") — per gli agenti di grado inferiore la commissione indiretta
 *    si ferma al 5° livello. La "breakaway" si applica dal 5° livello in
 *    poi: il conteggio non scende oltre il primo agente di rank
 *    Top/SuperVisor/Manager incontrato lungo ciascun ramo — quel nodo viene
 *    comunque incluso, ma i SUOI discendenti non vengono piu' conteggiati
 *    per l'agente a monte (confermato da Laura il 2026-07-03, vedi
 *    [[mlm_livello5_8percento_da_confermare]]).
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

    /** Qualifiche che godono dell'estensione oltre il 5° livello e che fanno da "breakaway" (si ferma la discesa una volta incontrate). */
    private const BREAKAWAY_RANKS = ['top', 'supervisor', 'manager'];

    public function __construct(private readonly MlmTreeService $tree) {}

    public function directPercentage(int $activePoints): float
    {
        foreach (self::DIRECT_TABLE as $threshold => $pct) {
            if ($activePoints >= $threshold) {
                return $pct;
            }
        }

        return 0.0;
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

        // Per ciascun agente diretto: mappa cliente -> importo mensile attivo
        // (un cliente puo' avere piu' righe se ha fatto piu' depositi ancora attivi).
        $clientsByAgent = [];
        foreach ($activeBaseRows as $row) {
            $agentId = (int) $row->direct_agent_id;
            $clientId = (int) $row->client_user_id;
            $clientsByAgent[$agentId][$clientId] = ($clientsByAgent[$agentId][$clientId] ?? 0) + (int) $row->monthly_amount_eur_cents;
        }

        $agents = User::where('mlm_role', 'agente')->get()->keyBy('id');

        foreach ($agents as $agent) {
            $this->calculateDirect($agent, $clientsByAgent[$agent->id] ?? [], $run, $periodMonth);
            $this->calculateIndirect($agent, $clientsByAgent, $run);
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
     * Top/SuperVisor/Manager, con breakaway sul primo nodo Top+ incontrato
     * (vedi classe docblock).
     */
    private function calculateIndirect(User $agent, array $clientsByAgent, MlmCommissionRun $run): void
    {
        $agentQualifiesForExtension = in_array($agent->mlm_rank, self::BREAKAWAY_RANKS, true);

        $queue = [];
        foreach ($this->tree->directDownline($agent) as $child) {
            $queue[] = ['agent' => $child, 'depth' => 1];
        }

        while (! empty($queue)) {
            $item = array_shift($queue);
            $node = $item['agent'];
            $depth = $item['depth'];

            if ($depth <= 5) {
                $percentage = self::INDIRECT_PERCENTAGES[$depth];
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

            $isBreakawayPoint = $depth >= 5 && in_array($node->mlm_rank, self::BREAKAWAY_RANKS, true);

            if (! $isBreakawayPoint) {
                foreach ($this->tree->directDownline($node) as $child) {
                    $queue[] = ['agent' => $child, 'depth' => $depth + 1];
                }
            }
        }
    }
}
