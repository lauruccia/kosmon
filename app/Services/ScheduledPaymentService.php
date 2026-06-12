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
        int $count,
        User $createdBy,
    ): array {
        abort_if($count < 2,  422, 'Il numero di rate deve essere almeno 2.');
        abort_if($count > 60, 422, 'Non è possibile creare più di 60 rate ricorrenti in una volta.');

        $dates = $this->computeRecurrenceDates(
            Carbon::instance($firstDate),
            $recurrenceType,
            $count,
        );

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
     * Calcola le date di esecuzione in base alla frequenza e al numero di rate.
     *
     * @return Carbon[]
     */
    private function computeRecurrenceDates(Carbon $first, string $type, int $count): array
    {
        $dates   = [];
        $current = $first->copy();

        for ($i = 0; $i < $count; $i++) {
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
        } catch (\Throwable $e) {
            $payment->update([
                'status'         => 'failed',
                'failure_reason' => $e->getMessage(),
            ]);
            return;
        }

        $payment->update([
            'status'      => 'executed',
            'transfer_id' => $transfer->id,
            'executed_at' => now(),
        ]);

        // Notifica al mittente
        try {
            $initiator->notify(new ScheduledPaymentExecutedNotification($payment, isRecipient: false));

            // Notifica al destinatario
            $toOwner = $payment->toAccount?->ownerUser;
            if ($toOwner && $toOwner->id !== $initiator->id) {
                $toOwner->notify(new ScheduledPaymentExecutedNotification($payment, isRecipient: true));
            }
        } catch (\Throwable) {
            // Le notifiche non devono bloccare la contabilizzazione
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
            $payment->update(['status' => 'failed', 'failure_reason' => 'Rata non trovata.']);
            return;
        }

        $plan        = $installment->paymentPlan;
        $fromOwner   = $plan->fromAccount?->ownerUser ?? $plan->fromAccount?->company?->users()->first();
        $toOwner     = $plan->toAccount?->ownerUser  ?? $plan->toAccount?->company?->users()->first();

        /** @var \App\Services\PaymentPlanService $planService */
        $planService = app(\App\Services\PaymentPlanService::class);

        try {
            $transfer = $planService->processInstallment($installment);

            $payment->update([
                'status'      => 'executed',
                'transfer_id' => $transfer->id,
                'executed_at' => now(),
            ]);

            $installment->refresh();
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

            if ($fromOwner) {
                $fromOwner->notify(new InstallmentFailedNotification($installment, $plan, $e->getMessage()));
            }
            if ($toOwner && $toOwner->id !== $fromOwner?->id) {
                $toOwner->notify(new InstallmentFailedNotification($installment, $plan, $e->getMessage()));
            }
        }
    }

    /**
     * Riprova immediatamente un pagamento fallito.
     * Resetta lo stato a pending e chiama execute() subito.
     */
    /**
     * Esegue o riprova immediatamente un pagamento pending scaduto o fallito.
     * Funziona sia per status = 'failed' che per status = 'pending' già scaduto.
     */
    public function retry(ScheduledPayment $payment, Account $byAccount): void
    {
        abort_unless($payment->isFailed() || $payment->isPending(), 422, 'Il pagamento non può essere eseguito ora.');
        abort_unless((int) $payment->from_account_id === $byAccount->id, 403, 'Non autorizzato.');

        // Resetta campi di esecuzione precedente e porta scheduled_at a ora
        $payment->update([
            'status'         => 'pending',
            'scheduled_at'   => now(),
            'failure_reason' => null,
            'executed_at'    => null,
            'transfer_id'    => null,
        ]);

        $this->execute($payment);
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
