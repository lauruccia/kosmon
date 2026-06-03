<?php

namespace App\Jobs;

use App\Models\PaymentPlan;
use App\Models\PaymentPlanInstallment;
use App\Notifications\InstallmentPaidNotification;
use App\Notifications\InstallmentFailedNotification;
use App\Services\PaymentPlanService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class ProcessDueInstallments implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function handle(PaymentPlanService $service): void
    {
        // Fetch all pending installments whose due_date <= today, for active plans.
        // Esclude le rate che hanno già una ScheduledPayment pending collegata:
        // quelle vengono gestite da ProcessScheduledPayments.
        $due = PaymentPlanInstallment::query()
            ->with(['paymentPlan.fromAccount.company', 'paymentPlan.fromAccount.ownerUser',
                    'paymentPlan.toAccount.company', 'paymentPlan.toAccount.ownerUser'])
            ->whereHas('paymentPlan', function ($q) {
                $q->where('status', 'active')
                  ->whereHas('fromAccount', fn ($a) => $a->whereHas('company', fn ($c) => $c->whereNull('payments_paused_at')));
            })
            ->whereDoesntHave('scheduledPayment', fn ($q) => $q->where('status', 'pending'))
            ->where('status', 'pending')
            ->whereDate('due_date', '<=', now()->toDateString())
            ->orderBy('due_date')
            ->get();

        Log::info('[ProcessDueInstallments] Found ' . $due->count() . ' due installments.');

        foreach ($due as $installment) {
            try {
                $transfer = $service->processInstallment($installment);
                $installment->refresh();

                // Notify debtor (from_account owner)
                $plan      = $installment->paymentPlan;
                $fromOwner = $plan->fromAccount?->ownerUser ?? $plan->fromAccount?->company?->users()->first();
                $toOwner   = $plan->toAccount?->ownerUser ?? $plan->toAccount?->company?->users()->first();

                if ($fromOwner) {
                    $fromOwner->notify(new InstallmentPaidNotification($installment, $plan));
                }
                if ($toOwner) {
                    $toOwner->notify(new InstallmentPaidNotification($installment, $plan));
                }

                Log::info('[ProcessDueInstallments] Installment #' . $installment->id . ' processed OK — transfer ' . $transfer->reference);

            } catch (RuntimeException $e) {
                $installment->refresh();

                $plan      = $installment->paymentPlan;
                $fromOwner = $plan->fromAccount?->ownerUser ?? $plan->fromAccount?->company?->users()->first();
                $toOwner   = $plan->toAccount?->ownerUser  ?? $plan->toAccount?->company?->users()->first();

                if ($fromOwner) {
                    $fromOwner->notify(new InstallmentFailedNotification($installment, $plan, $e->getMessage(), isCreditor: false));
                }
                if ($toOwner && $toOwner->id !== $fromOwner?->id) {
                    $toOwner->notify(new InstallmentFailedNotification($installment, $plan, $e->getMessage(), isCreditor: true));
                }

                Log::warning('[ProcessDueInstallments] Installment #' . $installment->id . ' FAILED: ' . $e->getMessage());
            }
        }
    }
}
