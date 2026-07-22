<?php

namespace App\Services;

use App\Models\MlmBonusEvent;
use App\Models\MlmBonusPayout;
use App\Models\MlmCommission;
use App\Models\MlmCommissionBaseLedgerEntry;
use App\Models\MlmPointLedgerEntry;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Simulatore "cosa succederebbe se..." per il piano compensi MLM
 * (2026-07-21, richiesta di Laura: un modo semplice per calcolare i bonus e
 * verificare che funzionino, dal pannello admin).
 *
 * PRINCIPIO: nessuna logica di calcolo duplicata. Le simulazioni eseguono i
 * VERI motori di produzione (MlmPointsService, MlmCommissionEngine,
 * MlmBonusService) dentro una transazione database che viene SEMPRE
 * annullata (rollback) alla fine — con successo o con errore. Ne consegue
 * che:
 *
 *  - i numeri mostrati sono esattamente quelli che produrrebbe il sistema
 *    reale (stesso codice, stessi arrotondamenti, stesso gating);
 *  - non resta scritto NULLA: niente punti, niente commissioni, niente
 *    bonus, niente audit log. Si puo' simulare quante volte si vuole, anche
 *    in produzione.
 *
 * Due scenari:
 *
 *  1. simulateDeposit(): un cliente fa una ricarica di X EUR. Mostra i punti
 *     assegnati all'agente (tabella "punti per evento" del 2026-07-22, vedi
 *     mlm_point_rules), la base commissionabile UNA TANTUM (intero importo
 *     x margine KNM = "Prov K") e il DELTA delle commissioni (dirette +
 *     indirette) che quella ricarica produrra' nell'unico run che la paga
 *     (il 1° del mese successivo). Il delta e'
 *     calcolato eseguendo il motore due volte — senza e con la ricarica —
 *     e sottraendo: cosi' le commissioni gia' maturate dagli altri depositi
 *     attivi non inquinano il risultato.
 *
 *  2. simulateBasiq(): un agente diventa BasiQ. Mostra la cascata dei bonus
 *     di struttura sulla upline (regola "per POSIZIONE" del 2026-07-20),
 *     con una nota per ogni anello della catena che spiega perche' incassa
 *     quel importo (o perche' non incassa nulla: qualifica senza bonus,
 *     importo assorbito da una qualifica piu' alta sotto, Key non ancora
 *     eleggibile).
 */
class MlmSimulationService
{
    public function __construct(
        private readonly MlmPointsService $points,
        private readonly MlmCommissionEngine $commissions,
        private readonly MlmBonusService $bonuses,
        private readonly MlmTreeService $tree,
    ) {}

    /**
     * Simula una ricarica di $depositEurCents del cliente e restituisce
     * punti, base commissionabile e delta commissioni. Nessuna scrittura
     * permanente (rollback garantito dal finally).
     *
     * @return array{
     *   month: Carbon,
     *   ledger_entries: array<int, array{agent_name:string, points:int|float, valid_from:string, valid_until:string}>,
     *   base_entries: array<int, array{monthly_amount_eur_cents:int, knm_margin_percent:int, prov_k_eur_cents:int}>,
     *   commissions: array<int, array{agent_id:int, agent_name:string, type:string, level:?int, base_amount_eur_cents:int, percentage:float, amount_eur_cents:int}>,
     *   total_commissions_eur_cents: int,
     * }
     */
    public function simulateDeposit(User $client, int $depositEurCents): array
    {
        // L'unico run che paghera' la ricarica: la riga di base ha finestra
        // di validita' = il solo 1° del mese successivo (una tantum,
        // 2026-07-22), quindi il run di quel mese la cattura e mai piu'.
        $month = now()->addMonthNoOverflow()->startOfMonth();

        $result = [
            'month' => $month,
            'ledger_entries' => [],
            'base_entries' => [],
            'commissions' => [],
            'total_commissions_eur_cents' => 0,
        ];

        DB::beginTransaction();

        try {
            // 1) Fotografia di riferimento: commissioni del mese SENZA la
            //    ricarica, calcolate in un savepoint annidato che viene
            //    subito annullato (il run e le sue righe spariscono).
            DB::beginTransaction();
            $baseline = $this->commissionRowsForMonth($month);
            DB::rollBack();

            // 2) La ricarica vera e propria, con il motore punti reale.
            $maxLedgerId = (int) MlmPointLedgerEntry::max('id');
            $maxBaseId = (int) MlmCommissionBaseLedgerEntry::max('id');

            $this->points->awardDepositPoints($client, $depositEurCents);

            $result['ledger_entries'] = MlmPointLedgerEntry::where('id', '>', $maxLedgerId)
                ->get()
                ->map(fn (MlmPointLedgerEntry $e) => [
                    'agent_name' => User::find($e->agent_user_id)?->name ?? ('#' . $e->agent_user_id),
                    'points' => mlm_points_normalize((float) $e->points),
                    'valid_from' => $e->valid_from->format('d/m/Y'),
                    'valid_until' => $e->valid_until->format('d/m/Y'),
                ])->values()->all();

            $result['base_entries'] = MlmCommissionBaseLedgerEntry::where('id', '>', $maxBaseId)
                ->get()
                ->map(fn (MlmCommissionBaseLedgerEntry $e) => [
                    'monthly_amount_eur_cents' => (int) $e->monthly_amount_eur_cents,
                    'knm_margin_percent' => (int) $e->knm_margin_percent,
                    'prov_k_eur_cents' => (int) round((int) $e->monthly_amount_eur_cents * (int) $e->knm_margin_percent / 100),
                ])->values()->all();

            // 3) Commissioni del mese CON la ricarica: la differenza riga per
            //    riga rispetto alla fotografia e' l'effetto della ricarica.
            $after = $this->commissionRowsForMonth($month);

            $result['commissions'] = $this->diffCommissionRows($baseline, $after);
            $result['total_commissions_eur_cents'] = array_sum(array_column($result['commissions'], 'amount_eur_cents'));
        } finally {
            DB::rollBack();
        }

        return $result;
    }

    /**
     * Simula l'evento "l'agente diventa BasiQ" e restituisce la cascata bonus
     * sulla upline, annotata anello per anello. Nessuna scrittura permanente.
     *
     * @return array{
     *   chain: array<int, array{position:int, name:string, rank:string, tier_eur_cents:?int, payout_eur_cents:int, note:string}>,
     *   total_eur_cents: int,
     *   week_ending: ?string,
     * }
     */
    public function simulateBasiq(User $agent): array
    {
        $result = ['chain' => [], 'total_eur_cents' => 0, 'week_ending' => null];

        DB::beginTransaction();

        try {
            // Ogni agente puo' avere UN solo evento BasiQ reale (vincolo
            // unique): per simulare "cosa succederebbe se diventasse BasiQ
            // ORA" rimuoviamo l'eventuale evento storico dentro la
            // transazione (il rollback finale lo ripristina).
            MlmBonusEvent::where('basiq_user_id', $agent->id)->delete();

            $event = $this->bonuses->processBasiqEvent($agent);

            $payouts = MlmBonusPayout::where('mlm_bonus_event_id', $event->id)
                ->get()
                ->keyBy('beneficiary_user_id');

            $result['week_ending'] = $payouts->first()?->week_ending?->format('d/m/Y');

            // Spiegazione della cascata: si ripercorre la stessa catena
            // upline usata dal motore, ma gli IMPORTI vengono letti dai
            // payout appena creati dal motore reale — qui si costruiscono
            // solo le note. $highestBelow replica la sottrazione "per
            // POSIZIONE" al solo scopo di spiegare gli zeri.
            $highestBelow = 0;
            $position = 0;

            foreach ($this->tree->orderedUpline($agent) as $ancestor) {
                $position++;
                $rank = $ancestor->mlm_rank;
                $tier = MlmBonusService::BONUS_AMOUNTS_EUR_CENTS[$rank] ?? null;
                $payout = (int) ($payouts->get($ancestor->id)?->amount_eur_cents ?? 0);

                if ($tier === null) {
                    $note = 'Qualifica senza bonus di struttura (solo da Key in su).';
                } elseif ($rank === 'key' && ! $this->bonuses->keyIsBonusEligible($ancestor, $event->triggered_at)) {
                    $count = $this->bonuses->keyBasiqEventCount($ancestor, $event->triggered_at);
                    $note = sprintf(
                        'Key non ancora eleggibile: %d event%s BasiQ nella sua struttura su %d richiesti (i primi %d sono "consumati" dal requisito di qualifica). Trattato come assente: non abbassa il bonus di chi sta sopra.',
                        $count,
                        $count === 1 ? 'o' : 'i',
                        MlmBonusService::KEY_MIN_BASIQ_EVENTS,
                        MlmBonusService::KEY_MIN_BASIQ_EVENTS - 1,
                    );
                } elseif ($payout > 0) {
                    $note = $highestBelow > 0
                        ? sprintf('Bonus %s (%s) meno il bonus piu\' alto gia\' presente sotto (%s).', ucfirst($rank), self::eur($tier), self::eur($highestBelow))
                        : sprintf('Primo bonus-eligibile della catena: incassa il bonus %s pieno.', ucfirst($rank));
                    $highestBelow = max($highestBelow, $tier);
                } else {
                    $note = sprintf('Assorbito: sotto di lui c\'e\' gia\' un bonus di pari o maggiore importo (%s >= %s). Non incassa nulla.', self::eur($highestBelow), self::eur($tier));
                    $highestBelow = max($highestBelow, $tier);
                }

                $result['chain'][] = [
                    'position' => $position,
                    'name' => $ancestor->name,
                    'rank' => $rank,
                    'tier_eur_cents' => $tier,
                    'payout_eur_cents' => $payout,
                    'note' => $note,
                ];
            }

            $result['total_eur_cents'] = (int) $payouts->sum('amount_eur_cents');
        } finally {
            DB::rollBack();
        }

        return $result;
    }

    /**
     * Esegue il motore commissioni per il mese indicato e restituisce le
     * righe prodotte, indicizzate per chiave logica (beneficiario + tipo +
     * livello + sorgente) cosi' da poterle sottrarre riga per riga.
     *
     * @return array<string, array{agent_id:int, agent_name:string, type:string, level:?int, base_amount_eur_cents:int, percentage:float, amount_eur_cents:int}>
     */
    private function commissionRowsForMonth(Carbon $month): array
    {
        $run = $this->commissions->runForMonth($month);

        $rows = MlmCommission::where('mlm_commission_run_id', $run->id)->get();

        $names = User::whereIn('id', $rows->pluck('agent_user_id')->unique())
            ->pluck('name', 'id');

        $indexed = [];
        foreach ($rows as $row) {
            $key = implode('|', [
                $row->agent_user_id,
                $row->type,
                $row->level ?? '-',
                $row->source_client_id ?? '-',
                $row->source_agent_id ?? '-',
            ]);

            $indexed[$key] = [
                'agent_id' => (int) $row->agent_user_id,
                'agent_name' => $names[$row->agent_user_id] ?? ('#' . $row->agent_user_id),
                'type' => $row->type,
                'level' => $row->level !== null ? (int) $row->level : null,
                'base_amount_eur_cents' => (int) $row->base_amount_eur_cents,
                'percentage' => (float) $row->percentage,
                'amount_eur_cents' => (int) $row->amount_eur_cents,
            ];
        }

        return $indexed;
    }

    /**
     * Differenza riga per riga fra due esecuzioni del motore: restituisce
     * solo le righe il cui importo cambia per effetto della ricarica
     * simulata, con base e importo gia' al netto della fotografia di
     * riferimento.
     *
     * @param array<string, array<string, mixed>> $baseline
     * @param array<string, array<string, mixed>> $after
     * @return array<int, array{agent_id:int, agent_name:string, type:string, level:?int, base_amount_eur_cents:int, percentage:float, amount_eur_cents:int}>
     */
    private function diffCommissionRows(array $baseline, array $after): array
    {
        $delta = [];

        foreach ($after as $key => $row) {
            $before = $baseline[$key] ?? null;
            $amountDelta = $row['amount_eur_cents'] - ($before['amount_eur_cents'] ?? 0);
            $baseDelta = $row['base_amount_eur_cents'] - ($before['base_amount_eur_cents'] ?? 0);

            if ($amountDelta === 0 && $baseDelta === 0) {
                continue;
            }

            $delta[] = array_merge($row, [
                'base_amount_eur_cents' => $baseDelta,
                'amount_eur_cents' => $amountDelta,
            ]);
        }

        // Righe presenti solo nella fotografia (sparite con la ricarica):
        // non dovrebbe succedere per un deposito, ma se succede va mostrato,
        // non nascosto.
        foreach ($baseline as $key => $row) {
            if (! array_key_exists($key, $after)) {
                $delta[] = array_merge($row, [
                    'base_amount_eur_cents' => -$row['base_amount_eur_cents'],
                    'amount_eur_cents' => -$row['amount_eur_cents'],
                ]);
            }
        }

        usort($delta, fn (array $a, array $b) => [$a['type'], $a['level'] ?? 0, $a['agent_name']] <=> [$b['type'], $b['level'] ?? 0, $b['agent_name']]);

        return $delta;
    }

    private static function eur(int $cents): string
    {
        return number_format($cents / 100, 2, ',', '.') . ' EUR';
    }
}
