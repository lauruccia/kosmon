<?php

namespace App\Jobs;

use App\Models\ScheduledPayment;
use App\Services\ScheduledPaymentService;
use Illuminate\Support\Facades\Log;

class ProcessScheduledPayments
{

    public function handle(ScheduledPaymentService $service): void
    {
        $due = ScheduledPayment::query()
            ->with(['fromAccount.company', 'toAccount', 'creator'])
            ->where('status', 'pending')
            ->where('scheduled_at', '<=', now())
            ->whereHas('fromAccount', function ($q) {
                $q->whereHas('company', fn ($c) => $c->whereNull('payments_paused_at'));
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
