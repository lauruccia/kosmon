<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\MlmBonusPayout;
use App\Models\MlmCommission;
use App\Models\MlmPayout;
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

        return $payout->fresh();
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
}
