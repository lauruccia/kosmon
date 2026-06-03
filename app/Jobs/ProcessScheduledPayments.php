<?php

namespace App\Jobs;

use App\Models\ScheduledPayment;
use App\Services\ScheduledPaymentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessScheduledPayments implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(ScheduledPaymentService $service): void
    {
        $due = ScheduledPayment::query()
            ->with(['fromAccount.company', 'toAccount', 'creator'])
            ->where('status', 'pending')
            ->where('scheduled_at', '<=', now())
            ->whereHas('fromAccount', function ($q) {
                $q->where('status', 'active')
                  ->where(function ($sub) {
                      // Conto aziendale: l'azienda non deve avere i pagamenti in pausa
                      $sub->whereDoesntHave('company')
                          ->orWhereHas('company', fn ($c) => $c->whereNull('payments_paused_at'));
                  });
            })
            ->get();

        foreach ($due as $payment) {
            try {
                $service->execute($payment);
            } catch (\Throwable $e) {
                Log::error('ProcessScheduledPayments: errore pagamento #' . $payment->id, [
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
