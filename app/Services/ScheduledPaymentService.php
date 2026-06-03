<?php

namespace App\Services;

use App\Models\Account;
use App\Models\ScheduledPayment;
use App\Models\User;
use App\Notifications\InstallmentFailedNotification;
use App\Notifications\InstallmentPaidNotification;
use App\Notifications\ScheduledPaymentExecutedNotification;
use Carbon\Carbon;
use Illuminate\Support\Str;

class ScheduledPaymentService
{
    public function __construct(
        private readonly TransferBookingService $booking,
    ) {}

    /**
     * Crea un pagamento programmato (non eseguito subito).
     */
    public function create(
        Account $fromAccount,
        Account $toAccount,
        int $amount,
        string $description,
        \DateTimeInterface $scheduledAt,
        User $createdBy,
    ): ScheduledPayment {
        return ScheduledPayment::create([
            'from_account_id' => $fromAccount->id,
            'to_account_id'   => $toAccount->id,
            'amount'          => $amount,
            'description'     => $description,
            'scheduled_at'    => $scheduledAt,
            'status'          => 'pending',
            'created_by'      => $createdBy->id,
        ]);
    }

    /**
     * Crea un gruppo di pagamenti ricorrenti (N rate generate tutte subito).
     *
     * @param  string  $recurrenceType  monthly | weekly | biweekly
     * @return ScheduledPayment[]
     */
    public function createRecurring(
        Account $fromAccount,
        Account $toAccount,
        int $amount,
        string $description,
        \DateTimeInterface $firstDate,
        string $recurrenceType,
        \DateTimeInterface $endDate,
        User $createdBy,
    ): array {
        $dates = $this->computeRecurrenceDates(
            Carbon::instance($firstDate),
            $recurrenceType,
            Carbon::instance($endDate),
        );

        abort_if(count($dates) === 0, 422, 'Le date selezionate non producono alcuna rata.');
        abort_if(count($dates) > 60, 422, 'Non è possibile creare più di 60 rate ricorrenti in una volta.');

        $group   = (string) Str::uuid();
        $total   = count($dates);
        $created = [];

        foreach ($dates as $i => $date) {
            $index = $i + 1;
            $suffix = $total > 1 ? " (rata $index di $total)" : '';

            $created[] = ScheduledPayment::create([
                'from_account_id'  => $fromAccount->id,
                'to_account_id'    => $toAccount->id,
                'amount'           => $amount,
                'description'      => $description . $suffix,
                'scheduled_at'     => $date,
                'status'           => 'pending',
                'created_by'       => $createdBy->id,
                'recurrence_group' => $group,
                'recurrence_index' => $index,
                'recurrence_total' => $total,
                'recurrence_type'  => $recurrenceType,
            ]);
        }

        return $created;
    }

    /**
     * Calcola le date di esecuzione in base alla frequenza e alla data fine.
     *
     * @return Carbon[]
     */
    private function computeRecurrenceDates(Carbon $first, string $type, Carbon $end): array
    {
        $dates   = [];
        $current = $first->copy();

        while ($current->lte($end)) {
            $dates[] = $current->copy();

            $current = match ($type) {
                'monthly'  => $current->addMonthNoOverflow(),
                'weekly'   => $current->addWeek(),
                'biweekly' => $current->addWeeks(2),
                default    => $current->addMonthNoOverflow(),
            };
        }

        return $dates;
    }

    /**
     * Esegue un pagamento programmato scaduto.
     * Chiamato dal job ProcessScheduledPayments.
     *
     * Se la scheduled payment è collegata a una rata di un piano rateale,
     * delega l'esecuzione a PaymentPlanService::processInstallment().
     */
    public function execute(ScheduledPayment $payment): void
    {
        if (! $payment->isPending()) {
            return;
        }

        // Ricarica con lock per evitare doppia esecuzione
        $payment = ScheduledPayment::lockForUpdate()->find($payment->id);
        if (! $payment || ! $payment->isPending()) {
            return;
        }

        // ── Rata di un piano rateale ──────────────────────────────────────────
        if ($payment->isPlanInstallment()) {
            $this->executeInstallment($payment);
            return;
        }

        // ── Pagamento programmato standard ───────────────────────────────────
        $initiator = $payment->creator ?? User::findOrFail($payment->created_by);

        try {
            $transfer = $this->booking->book([
                'initiated_by'    => $initiator->id,
                'from_account_id' => $payment->from_account_id,
                'to_account_id'   => $payment->to_account_id,
                'amount'          => $payment->amount,
                'description'     => $payment->description,
                'kind'            => 'portal_scheduled',
                'idempotency_key' => 'sched_' . $payment->uuid,
            ]);

            $payment->update([
                'status'      => 'executed',
                'transfer_id' => $transfer->id,
                'executed_at' => now(),
            ]);

            // Notifica al mittente
            if ($initiator) {
                $initiator->notify(new ScheduledPaymentExecutedNotification($payment, isRecipient: false));
            }

            // Notifica al destinatario
            $toOwner = $payment->toAccount?->ownerUser;
            if ($toOwner) {
                $toOwner->notify(new ScheduledPaymentExecutedNotification($payment, isRecipient: true));
            }
        } catch (\Throwable $e) {
            $payment->update([
                'status'         => 'failed',
                'failure_reason' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Esegue una scheduled payment collegata a una rata di piano rateale.
     * Delega il bookkeeping a PaymentPlanService::processInstallment() e
     * invia notifiche in-app + email a entrambe le parti.
     */
    private function executeInstallment(ScheduledPayment $payment): void
    {
        $payment->load(['planInstallment.paymentPlan.fromAccount.ownerUser',
                        'planInstallment.paymentPlan.toAccount.ownerUser']);

        $installment = $payment->planInstallment;
        if (! $installment) {
            // Installment rimossa: marca come fallita e torna
            $payment->update(['status' => 'failed', 'failure_reason' => 'Rata non trovata.']);
            return;
        }

        $plan = $installment->paymentPlan;

        /** @var \App\Services\PaymentPlanService $planService */
        $planService = app(\App\Services\PaymentPlanService::class);

        try {
            $transfer = $planService->processInstallment($installment);

            $payment->update([
                'status'      => 'executed',
                'transfer_id' => $transfer->id,
                'executed_at' => now(),
            ]);

            // Notifica in-app + email a entrambe le parti
            $installment->refresh();
            $fromOwner = $plan->fromAccount?->ownerUser ?? $plan->fromAccount?->company?->users()->first();
            $toOwner   = $plan->toAccount?->ownerUser  ?? $plan->toAccount?->company?->users()->first();

            if ($fromOwner) {
                $fromOwner->notify(new InstallmentPaidNotification($installment, $plan));
            }
            if ($toOwner && $toOwner->id !== $fromOwner?->id) {
                $toOwner->notify(new InstallmentPaidNotification($installment, $plan));
            }

        } catch (\Throwable $e) {
            $payment->update([
                'status'         => 'failed',
                'failure_reason' => $e->getMessage(),
            ]);

            // Notifica fallimento a entrambe le parti
            $fromOwner = $plan->fromAccount?->ownerUser ?? $plan->fromAccount?->company?->users()->first();
            $toOwner   = $plan->toAccount?->ownerUser  ?? $plan->toAccount?->company?->users()->first();

            if ($fromOwner) {
                $fromOwner->notify(new InstallmentFailedNotification($installment, $plan, $e->getMessage()));
            }
            if ($toOwner && $toOwner->id !== $fromOwner?->id) {
                $toOwner->notify(new InstallmentFailedNotification($installment, $plan, $e->getMessage()));
            }
        }
    }

    /**
     * Annulla un pagamento programmato.
     */
    public function cancel(ScheduledPayment $payment, Account $byAccount): void
    {
        abort_unless($payment->canBeCancelledBy($byAccount), 403, 'Non autorizzato ad annullare questo pagamento.');
        $payment->update(['status' => 'cancelled']);
    }
}
