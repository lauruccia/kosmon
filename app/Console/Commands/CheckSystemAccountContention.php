<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\Transfer;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Monitora la contesa sul lock del conto sistema (Cassa Circuito KMoney).
 *
 * Contesto: TransferBookingService::bookFee() e CashbackService::applyIfEligible()
 * eseguono un lockForUpdate() sul conto sistema ad ogni fee/cashback erogato. Con
 * pochi pagamenti concorrenti non è un problema, ma oltre ~50 pagamenti/minuto i
 * thread iniziano a serializzarsi in coda su quella riga (vedi commento esteso in
 * TransferBookingService::bookFee(), che propone la soluzione a batch tramite una
 * tabella pending_system_credits + job di flush schedulato).
 *
 * Questo comando NON tocca saldi, lock o logica di booking: legge soltanto i
 * timestamp dei transfer già registrati che coinvolgono il conto sistema e segnala
 * (via log + Sentry) quando il picco di transfer/minuto si avvicina alla soglia,
 * così da poter valutare per tempo se implementare la soluzione a batch.
 */
class CheckSystemAccountContention extends Command
{
    protected $signature = 'accounting:check-contention
                            {--window=15 : Finestra di osservazione in minuti}
                            {--threshold=20 : Soglia di transfer/minuto (sul conto sistema) oltre la quale segnalare}';

    protected $description = 'Segnala se la contesa sul lock del conto sistema (fee/cashback) si avvicina alla soglia di serializzazione documentata in bookFee()';

    public function handle(): int
    {
        $system = Account::systemAccount();

        if (! $system) {
            $this->warn('Nessun conto sistema trovato, controllo saltato.');
            return self::SUCCESS;
        }

        $windowMinutes = max(1, (int) $this->option('window'));
        $threshold     = max(1, (int) $this->option('threshold'));
        $since         = now()->subMinutes($windowMinutes);

        // Sola lettura: ogni transfer booked che coinvolge il conto sistema come
        // mittente o destinatario (fee, cashback, ricariche, storni...) nella finestra.
        $timestamps = Transfer::query()
            ->where('status', 'booked')
            ->where(function ($q) use ($system) {
                $q->where('from_account_id', $system->id)
                  ->orWhere('to_account_id', $system->id);
            })
            ->where('booked_at', '>=', $since)
            ->pluck('booked_at');

        if ($timestamps->isEmpty()) {
            $this->info('OK — nessun transfer sul conto sistema nella finestra osservata.');
            return self::SUCCESS;
        }

        // Bucket per minuto in PHP (non SQL) per restare portabile tra sqlite (dev)
        // e mysql (prod), che usano funzioni di troncamento data diverse.
        $perMinute = $timestamps
            ->map(fn ($t) => Carbon::parse($t)->format('Y-m-d H:i'))
            ->countBy();

        $peakCount  = (int) $perMinute->max();
        $peakMinute = $perMinute->sortDesc()->keys()->first();

        if ($peakCount < $threshold) {
            $this->info("OK — picco {$peakCount} transfer/minuto sul conto sistema (soglia {$threshold}, finestra {$windowMinutes} min).");
            return self::SUCCESS;
        }

        $message = "Contesa sul conto sistema in crescita: {$peakCount} transfer nello stesso minuto ({$peakMinute}), "
            . "soglia di allerta {$threshold}. Il bottleneck documentato in TransferBookingService::bookFee() "
            . "diventa rilevante oltre ~50 transfer/minuto: valutare l'implementazione della soluzione a batch "
            . "(pending_system_credits + job di flush) se il picco continua a salire.";

        $this->warn($message);

        Log::warning('accounting.system_account_contention', [
            'peak_count'      => $peakCount,
            'peak_minute'     => $peakMinute,
            'threshold'       => $threshold,
            'window_minutes'  => $windowMinutes,
            'system_account'  => $system->uuid,
        ]);

        if (app()->bound('sentry')) {
            \Sentry\captureMessage(
                "Contesa lock conto sistema: {$peakCount} transfer/minuto (soglia {$threshold})",
                \Sentry\Severity::warning()
            );
        }

        return self::SUCCESS;
    }
}
