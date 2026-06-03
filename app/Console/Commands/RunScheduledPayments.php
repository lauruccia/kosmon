<?php

namespace App\Console\Commands;

use App\Models\ScheduledPayment;
use App\Services\ScheduledPaymentService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RunScheduledPayments extends Command
{
    protected $signature   = 'payments:run-scheduled';
    protected $description = 'Esegue tutti i pagamenti programmati scaduti (esecuzione sincrona, no coda).';

    public function handle(ScheduledPaymentService $service): int
    {
        $due = ScheduledPayment::query()
            ->with(['fromAccount.company', 'toAccount', 'creator'])
            ->where('status', 'pending')
            ->where('scheduled_at', '<=', now())
            ->whereHas('fromAccount', function ($q) {
                $q->where('status', 'active')
                  ->where(function ($sub) {
                      $sub->whereDoesntHave('company')
                          ->orWhereHas('company', fn ($c) => $c->whereNull('payments_paused_at'));
                  });
            })
            ->get();

        if ($due->isEmpty()) {
            $this->line('[' . now()->format('H:i:s') . '] Nessun pagamento scaduto da elaborare.');
            return self::SUCCESS;
        }

        $this->info('[' . now()->format('H:i:s') . '] Trovati ' . $due->count() . ' pagamenti scaduti.');

        $ok = $failed = 0;

        foreach ($due as $payment) {
            try {
                $service->execute($payment);
                $payment->refresh();

                if ($payment->isExecuted()) {
                    $this->line('  ✓ #' . $payment->id . ' eseguito.');
                    $ok++;
                } else {
                    $this->warn('  ✗ #' . $payment->id . ' fallito: ' . $payment->failure_reason);
                    $failed++;
                }
            } catch (\Throwable $e) {
                $this->error('  ✗ #' . $payment->id . ' eccezione: ' . $e->getMessage());
                Log::error('payments:run-scheduled errore #' . $payment->id, ['error' => $e->getMessage()]);
                $failed++;
            }
        }

        $this->info("Completato: {$ok} eseguiti, {$failed} falliti.");
        return self::SUCCESS;
    }
}
