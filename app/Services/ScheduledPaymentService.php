<?php

namespace App\Services;

use App\Models\Account;
use App\Models\ScheduledPayment;
use App\Models\User;
use App\Notifications\ScheduledPaymentExecutedNotification;
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
     * Esegue un pagamento programmato scaduto.
     * Chiamato dal job ProcessScheduledPayments.
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

        // Usa lo stesso utente che ha creato il pagamento come initiator
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
     * Annulla un pagamento programmato.
     */
    public function cancel(ScheduledPayment $payment, Account $byAccount): void
    {
        abort_unless($payment->canBeCancelledBy($byAccount), 403, 'Non autorizzato ad annullare questo pagamento.');
        $payment->update(['status' => 'cancelled']);
    }
}
