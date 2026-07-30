<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\MlmBonusPayout;
use App\Models\MlmCommission;
use App\Models\MlmPayout;
use App\Models\MlmWalletLedgerEntry;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Aggrega commissioni (mlm_commissions) e bonus (mlm_bonus_payouts) in
 * liquidazioni EUR per agente/mese (mlm_payouts). Vedi MLM_PROPOSAL.md §5-6.
 *
 * Flusso di stato di una liquidazione: pending -> approved -> paid
 * (oppure pending|approved -> rejected). Le righe collegate (commissioni e
 * bonus) seguono lo stesso stato della liquidazione a cui appartengono:
 * restano 'pending' finche' la liquidazione non e' approvata, diventano
 * 'approved' quando la liquidazione e' approvata, 'paid' quando e' pagata.
 * Se la liquidazione viene rifiutata le righe vengono scollegate
 * (mlm_payout_id = null) cosi' da poter rientrare in una generazione futura.
 */
class MlmPayoutService
{
    /**
     * Genera (o aggiorna) le liquidazioni pending per il mese indicato,
     * raggruppando per agente tutte le commissioni del relativo run mensile
     * e i bonus con week_ending nel mese, non ancora collegati a nessuna
     * liquidazione. Idempotente: rieseguirla aggancia solo le righe ancora
     * libere (mlm_payout_id null); le liquidazioni gia' approvate/pagate/
     * rifiutate per lo stesso periodo non vengono toccate.
     *
     * @return Collection<int, MlmPayout>
     */
    public function generateForMonth(Carbon $month): Collection
    {
        $periodFrom = $month->copy()->startOfMonth();
        $periodTo = $month->copy()->endOfMonth();

        return DB::transaction(function () use ($periodFrom, $periodTo): Collection {
            $commissionAgentIds = MlmCommission::query()
                ->whereNull('mlm_payout_id')
                ->where('status', 'pending')
                ->whereHas('run', function ($query) use ($periodFrom): void {
                    $query->whereDate('period_month', $periodFrom->toDateString());
                })
                ->pluck('agent_user_id');

            $bonusAgentIds = MlmBonusPayout::query()
                ->whereNull('mlm_payout_id')
                ->where('status', 'pending')
                ->whereDate('week_ending', '>=', $periodFrom->toDateString())
                ->whereDate('week_ending', '<=', $periodTo->toDateString())
                ->pluck('beneficiary_user_id');

            $agentIds = $commissionAgentIds->merge($bonusAgentIds)->unique()->values();

            $payouts = collect();

            foreach ($agentIds as $agentId) {
                $payout = $this->attachAgentPeriod((int) $agentId, $periodFrom, $periodTo);
                if ($payout) {
                    $payouts->push($payout);
                }
            }

            return $payouts;
        });
    }

    /**
     * Trova (o crea) la liquidazione 'pending' dell'agente per il periodo, vi
     * aggancia tutte le commissioni/bonus ancora liberi, e ricalcola i totali
     * dalla somma delle righe effettivamente collegate. Se per quell'agente/
     * periodo esiste gia' una liquidazione non piu' pending (approved/paid/
     * rejected), non tocca le righe libere: restano in attesa di un periodo
     * successivo o di una nuova generazione manuale.
     */
    private function attachAgentPeriod(int $agentId, Carbon $periodFrom, Carbon $periodTo): ?MlmPayout
    {
        $nonPendingExists = MlmPayout::where('agent_user_id', $agentId)
            ->whereDate('period_from', $periodFrom->toDateString())
            ->whereDate('period_to', $periodTo->toDateString())
            ->where('status', '!=', 'pending')
            ->exists();

        if ($nonPendingExists) {
            return null;
        }

        // Cerca esplicitamente per data (whereDate, non firstOrCreate): le colonne
        // 'date'-cast di Eloquent vengono salvate con un timestamp completo anche
        // su SQLite, quindi un confronto di uguaglianza su toDateString() non
        // troverebbe mai la riga gia' esistente.
        $payout = MlmPayout::where('agent_user_id', $agentId)
            ->whereDate('period_from', $periodFrom->toDateString())
            ->whereDate('period_to', $periodTo->toDateString())
            ->where('status', 'pending')
            ->first();

        if (! $payout) {
            $payout = MlmPayout::create([
                'agent_user_id' => $agentId,
                'period_from' => $periodFrom->toDateString(),
                'period_to' => $periodTo->toDateString(),
                'status' => 'pending',
                'commissions_total_eur_cents' => 0,
                'bonus_total_eur_cents' => 0,
                'total_eur_cents' => 0,
            ]);
        }

        MlmCommission::where('agent_user_id', $agentId)
            ->whereNull('mlm_payout_id')
            ->where('status', 'pending')
            ->whereHas('run', function ($query) use ($periodFrom): void {
                $query->whereDate('period_month', $periodFrom->toDateString());
            })
            ->update(['mlm_payout_id' => $payout->id]);

        MlmBonusPayout::where('beneficiary_user_id', $agentId)
            ->whereNull('mlm_payout_id')
            ->where('status', 'pending')
            ->whereDate('week_ending', '>=', $periodFrom->toDateString())
            ->whereDate('week_ending', '<=', $periodTo->toDateString())
            ->update(['mlm_payout_id' => $payout->id]);

        $commissionsTotal = (int) MlmCommission::where('mlm_payout_id', $payout->id)->sum('amount_eur_cents');
        $bonusTotal = (int) MlmBonusPayout::where('mlm_payout_id', $payout->id)->sum('amount_eur_cents');

        $payout->forceFill([
            'commissions_total_eur_cents' => $commissionsTotal,
            'bonus_total_eur_cents' => $bonusTotal,
            'total_eur_cents' => $commissionsTotal + $bonusTotal,
        ])->save();

        // Cassetto kmoney (2026-07-30): anche la generazione admin deve
        // riservare (togliere dal cassetto spendibile/prelevabile) l'importo
        // appena agganciato — altrimenti l'agente potrebbe ancora spenderlo
        // o ri-prelevarlo mentre questa liquidazione manuale e' in corso.
        $this->reserveWalletForPayout($payout);

        return $payout->fresh();
    }

    /**
     * Riserva nel cassetto kmoney (App\Services\MlmWalletService) solo la
     * DIFFERENZA tra il totale attuale del payout e quanto gia' riservato
     * per questo stesso payout in una chiamata precedente — attachAgentPeriod()
     * puo' essere rieseguita piu' volte sullo stesso payout 'pending' via
     * generateForMonth(), agganciando via via nuove righe libere: senza
     * questo calcolo a delta si rischierebbe di riservare due volte lo
     * stesso importo. Le chiamate da requestWithdrawal() partono sempre da
     * un payout appena creato (nulla ancora riservato), quindi riservano
     * l'intero importo in un colpo solo.
     */
    private function reserveWalletForPayout(MlmPayout $payout): void
    {
        if ($payout->total_eur_cents <= 0) {
            return;
        }

        $alreadyReserved = abs((int) MlmWalletLedgerEntry::where('source_type', 'withdrawal_reserve')
            ->where('idempotency_key', 'like', "mlm_wallet_reserve_payout_{$payout->id}_%")
            ->sum('amount_cents'));

        $delta = $payout->total_eur_cents - $alreadyReserved;
        if ($delta <= 0) {
            return;
        }

        app(MlmWalletService::class)->reserveForPayout(
            $payout->agent,
            $delta,
            "mlm_wallet_reserve_payout_{$payout->id}_{$payout->total_eur_cents}",
            "Riserva cassetto kmoney per liquidazione #{$payout->id}",
        );
    }

    /**
     * Rilascia (torna disponibile e ri-prelevabile) tutto quanto era stato
     * riservato nel cassetto kmoney per questo payout — chiamata da
     * reject(). Idempotente sulla sola presenza del payout nell'idempotency
     * key (una sola volta, anche se reject() venisse per assurdo richiamata
     * due volte: gia' impedito a monte dal controllo di stato).
     */
    private function releaseWalletReservationForPayout(MlmPayout $payout): void
    {
        $reserved = abs((int) MlmWalletLedgerEntry::where('source_type', 'withdrawal_reserve')
            ->where('idempotency_key', 'like', "mlm_wallet_reserve_payout_{$payout->id}_%")
            ->sum('amount_cents'));

        if ($reserved <= 0) {
            return;
        }

        app(MlmWalletService::class)->releaseReservation(
            $payout->agent,
            $reserved,
            "mlm_wallet_release_payout_{$payout->id}",
            "Rilascio riserva cassetto kmoney — liquidazione #{$payout->id} rifiutata",
        );
    }

    /** Approva la liquidazione: le righe collegate passano da 'pending' ad 'approved'. */
    public function approve(MlmPayout $payout, User $admin): MlmPayout
    {
        return DB::transaction(function () use ($payout, $admin): MlmPayout {
            $payout = MlmPayout::whereKey($payout->id)->lockForUpdate()->firstOrFail();

            if ($payout->status !== 'pending') {
                throw new \RuntimeException("Impossibile approvare una liquidazione con stato '{$payout->status}'.");
            }

            $payout->forceFill([
                'status' => 'approved',
                'approved_by_user_id' => $admin->id,
                'approved_at' => now(),
            ])->save();

            MlmCommission::where('mlm_payout_id', $payout->id)->update(['status' => 'approved']);
            MlmBonusPayout::where('mlm_payout_id', $payout->id)->update(['status' => 'approved']);

            AuditLog::create([
                'actor_user_id' => $admin->id,
                'event' => 'mlm.payout_approved',
                'auditable_type' => MlmPayout::class,
                'auditable_id' => $payout->id,
                'context' => [
                    'agent_user_id' => $payout->agent_user_id,
                    'period_from' => $payout->period_from->toDateString(),
                    'period_to' => $payout->period_to->toDateString(),
                    'total_eur_cents' => $payout->total_eur_cents,
                ],
            ]);

            return $payout->fresh();
        });
    }

    /** Segna la liquidazione come pagata: le righe collegate passano da 'approved' a 'paid'. */
    public function markPaid(MlmPayout $payout, User $admin, string $paymentReference, ?string $notes = null): MlmPayout
    {
        return DB::transaction(function () use ($payout, $admin, $paymentReference, $notes): MlmPayout {
            $payout = MlmPayout::whereKey($payout->id)->lockForUpdate()->firstOrFail();

            if ($payout->status !== 'approved') {
                throw new \RuntimeException("Impossibile pagare una liquidazione con stato '{$payout->status}'.");
            }

            $payout->forceFill([
                'status' => 'paid',
                'payment_reference' => $paymentReference,
                'paid_at' => now(),
                'admin_notes' => $notes ?? $payout->admin_notes,
            ])->save();

            MlmCommission::where('mlm_payout_id', $payout->id)->update(['status' => 'paid']);
            MlmBonusPayout::where('mlm_payout_id', $payout->id)->update(['status' => 'paid']);

            AuditLog::create([
                'actor_user_id' => $admin->id,
                'event' => 'mlm.payout_paid',
                'auditable_type' => MlmPayout::class,
                'auditable_id' => $payout->id,
                'context' => [
                    'agent_user_id' => $payout->agent_user_id,
                    'payment_reference' => $paymentReference,
                    'total_eur_cents' => $payout->total_eur_cents,
                ],
            ]);

            return $payout->fresh();
        });
    }

    /**
     * Rifiuta la liquidazione: scollega le righe (tornano candidabili per una
     * generazione futura, restano al proprio stato 'pending'/'approved').
     */
    public function reject(MlmPayout $payout, User $admin, ?string $reason = null): MlmPayout
    {
        return DB::transaction(function () use ($payout, $admin, $reason): MlmPayout {
            $payout = MlmPayout::whereKey($payout->id)->lockForUpdate()->firstOrFail();

            if (! in_array($payout->status, ['pending', 'approved'], true)) {
                throw new \RuntimeException("Impossibile rifiutare una liquidazione con stato '{$payout->status}'.");
            }

            MlmCommission::where('mlm_payout_id', $payout->id)->update([
                'mlm_payout_id' => null,
                'status' => 'pending',
            ]);
            MlmBonusPayout::where('mlm_payout_id', $payout->id)->update([
                'mlm_payout_id' => null,
                'status' => 'pending',
            ]);

            // Cassetto kmoney (2026-07-30): rilascia quanto era stato
            // riservato per questa liquidazione — torna disponibile e
            // ri-prelevabile per l'agente.
            $this->releaseWalletReservationForPayout($payout);

            $payout->forceFill([
                'status' => 'rejected',
                'admin_notes' => $reason ?? $payout->admin_notes,
            ])->save();

            AuditLog::create([
                'actor_user_id' => $admin->id,
                'event' => 'mlm.payout_rejected',
                'auditable_type' => MlmPayout::class,
                'auditable_id' => $payout->id,
                'context' => [
                    'agent_user_id' => $payout->agent_user_id,
                    'reason' => $reason,
                ],
            ]);

            return $payout->fresh();
        });
    }

    /**
     * Quanto l'agente puo' richiedere di prelevare ORA — dal 2026-07-30
     * (cassetto kmoney) e' il saldo del cassetto (App\Services\MlmWalletService::withdrawableBalance()),
     * non piu' la semplice somma delle righe 'pending' non ancora
     * collegate: i compensi sono gia' accreditati in KY non appena
     * calcolati (vedi MlmWalletService), quindi questo importo puo' essere
     * INFERIORE alla somma storica delle righe se l'agente ha gia' speso
     * parte del cassetto come kmoney nel frattempo.
     */
    public function pendingWithdrawableCents(User $agent): int
    {
        return app(MlmWalletService::class)->withdrawableBalance($agent);
    }

    /** L'agente ha gia' una liquidazione in corso (pending o approved)? */
    public function hasOpenPayout(User $agent): bool
    {
        return MlmPayout::where('agent_user_id', $agent->id)
            ->whereIn('status', ['pending', 'approved'])
            ->exists();
    }

    /**
     * Soglia minima (EUR centesimi) impostata dall'admin sotto la quale il
     * prelievo self-service dal portale agente e' bloccato (2026-07-29).
     * 0 = nessuna soglia. Non si applica alle liquidazioni generate
     * manualmente dall'admin (generateForMonth()).
     */
    public function payoutThresholdCents(): int
    {
        return SystemSetting::mlmSettings()->mlmPayoutThresholdEurCents();
    }

    /**
     * L'agente ha maturato abbastanza (rispetto alla soglia impostata
     * dall'admin) per poter richiedere un prelievo self-service ora?
     */
    public function canRequestWithdrawal(User $agent): bool
    {
        return $this->pendingWithdrawableCents($agent) >= $this->payoutThresholdCents()
            && $this->pendingWithdrawableCents($agent) > 0;
    }

    /**
     * Richiesta di prelievo dal portale agente: aggancia TUTTO il maturato
     * libero (commissioni + bonus pending senza liquidazione) a una nuova
     * liquidazione 'pending' con requested_at valorizzato. L'admin la
     * approva e la paga con il flusso esistente (bonifico manuale).
     *
     * Dal 2026-07-29: la richiesta e' consentita solo se il maturato ha
     * raggiunto la soglia minima decisa dall'admin
     * (SystemSetting::mlmSettings()->mlmPayoutThresholdEurCents(), 0 =
     * nessuna soglia). Questo vincolo riguarda SOLO la richiesta
     * self-service dell'agente: l'admin puo' comunque generare liquidazioni
     * manualmente per qualunque importo da /admin/mlm-payouts
     * (generateForMonth()), tipicamente a fine mese.
     *
     * @throws \RuntimeException se c'e' gia' una richiesta in corso, non c'e' nulla da
     *                           prelevare, o il maturato e' sotto la soglia minima
     */
    public function requestWithdrawal(User $agent): MlmPayout
    {
        return DB::transaction(function () use ($agent): MlmPayout {
            $open = MlmPayout::where('agent_user_id', $agent->id)
                ->whereIn('status', ['pending', 'approved'])
                ->lockForUpdate()
                ->exists();

            if ($open) {
                throw new \RuntimeException('Hai gia\' una richiesta di prelievo in corso: attendi che venga elaborata prima di richiederne un\'altra.');
            }

            $commissions = MlmCommission::where('agent_user_id', $agent->id)
                ->whereNull('mlm_payout_id')
                ->where('status', 'pending')
                ->lockForUpdate()
                ->get();
            $commissions->load('run:id,period_month');

            $bonuses = MlmBonusPayout::where('beneficiary_user_id', $agent->id)
                ->whereNull('mlm_payout_id')
                ->where('status', 'pending')
                ->lockForUpdate()
                ->get();

            $commissionsTotal = (int) $commissions->sum('amount_eur_cents');
            $bonusTotal = (int) $bonuses->sum('amount_eur_cents');
            $rowsTotal = $commissionsTotal + $bonusTotal;

            // Cassetto kmoney (2026-07-30): il maturato storico (righe
            // ancora non collegate) puo' essere superiore a quanto e'
            // REALMENTE ancora prelevabile, se l'agente ha gia' speso parte
            // del cassetto come kmoney in negozio — vedi MlmWalletService.
            // Il prelievo non puo' mai superare il saldo cassetto reale.
            $total = min($rowsTotal, app(MlmWalletService::class)->withdrawableBalance($agent));

            if ($total <= 0) {
                throw new \RuntimeException('Non hai importi maturati da prelevare.');
            }

            // Se il prelievo e' stato limitato dal saldo cassetto reale,
            // riproporziona commissioni/bonus cosi' che la somma torni al
            // totale effettivo (le righe restano comunque tutte agganciate
            // per intero come traccia di audit di cosa le ha generate).
            if ($rowsTotal > 0 && $total < $rowsTotal) {
                $commissionsTotal = (int) round($commissionsTotal * $total / $rowsTotal);
                $bonusTotal = $total - $commissionsTotal;
            }

            $threshold = $this->payoutThresholdCents();
            if ($threshold > 0 && $total < $threshold) {
                throw new \RuntimeException(sprintf(
                    'Hai maturato € %s, ma la soglia minima per richiedere un prelievo e\' € %s. Continua ad accumulare prima di richiederne uno nuovo.',
                    number_format($total / 100, 2, ',', '.'),
                    number_format($threshold / 100, 2, ',', '.')
                ));
            }

            // Periodo coperto: dalla piu' vecchia riga agganciata a oggi.
            $dates = collect();
            foreach ($commissions as $commission) {
                if ($commission->run?->period_month) {
                    $dates->push($commission->run->period_month->copy());
                }
            }
            foreach ($bonuses as $bonus) {
                if ($bonus->week_ending) {
                    $dates->push($bonus->week_ending->copy());
                }
            }
            $periodFrom = $dates->sort()->first() ?? now()->startOfMonth();

            $payout = MlmPayout::create([
                'agent_user_id'               => $agent->id,
                'period_from'                 => $periodFrom->toDateString(),
                'period_to'                   => now()->toDateString(),
                'status'                      => 'pending',
                'requested_at'                => now(),
                'commissions_total_eur_cents' => $commissionsTotal,
                'bonus_total_eur_cents'       => $bonusTotal,
                'total_eur_cents'             => $total,
            ]);

            MlmCommission::whereIn('id', $commissions->pluck('id'))->update(['mlm_payout_id' => $payout->id]);
            MlmBonusPayout::whereIn('id', $bonuses->pluck('id'))->update(['mlm_payout_id' => $payout->id]);

            // Cassetto kmoney (2026-07-30): sposta davvero il KY dal conto
            // dell'agente al conto sistema, cosi' non e' piu' spendibile ne'
            // ri-prelevabile mentre questa richiesta e' in corso.
            $this->reserveWalletForPayout($payout);

            AuditLog::create([
                'actor_user_id'  => $agent->id,
                'event'          => 'mlm.payout_requested',
                'auditable_type' => MlmPayout::class,
                'auditable_id'   => $payout->id,
                'context'        => [
                    'agent_user_id'   => $agent->id,
                    'total_eur_cents' => $total,
                ],
            ]);

            return $payout->fresh();
        });
    }
}
