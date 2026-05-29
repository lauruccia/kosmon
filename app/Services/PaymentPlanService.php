<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\PaymentPlan;
use App\Models\PaymentPlanInstallment;
use App\Models\Transfer;
use App\Models\User;
use App\Notifications\PaymentPlanApprovedNotification;
use App\Notifications\PaymentPlanProposedNotification;
use App\Notifications\PaymentPlanRejectedNotification;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class PaymentPlanService
{
    public function __construct(private readonly TransferBookingService $bookingService) {}

    /**
     * Create a payment plan and persist all installment rows.
     * Does NOT process any payment immediately — the first installment
     * will be picked up by ProcessDueInstallments on its due date.
     */
    public function create(
        int $fromAccountId,
        int $toAccountId,
        int $totalAmount,
        int $installmentsCount,
        string $frequency,
        Carbon $firstDueDate,
        int $initiatedBy,
        ?string $description = null,
        ?string $ipAddress = null,
        string $initiatorRole = 'debtor',
    ): PaymentPlan {
        if ($totalAmount <= 0) {
            throw new RuntimeException('L\'importo totale deve essere maggiore di zero.');
        }
        if ($installmentsCount < 2 || $installmentsCount > 60) {
            throw new RuntimeException('Il numero di rate deve essere compreso tra 2 e 60.');
        }
        if (! in_array($frequency, ['weekly', 'biweekly', 'monthly'], true)) {
            throw new RuntimeException('Frequenza non valida.');
        }
        if ($firstDueDate->isPast() && ! $firstDueDate->isToday()) {
            throw new RuntimeException('La data della prima rata non può essere nel passato.');
        }

        return DB::transaction(function () use (
            $fromAccountId, $toAccountId, $totalAmount, $installmentsCount,
            $frequency, $firstDueDate, $initiatedBy, $description, $ipAddress, $initiatorRole
        ) {
            $initiator   = User::query()->findOrFail($initiatedBy);
            $fromAccount = Account::query()->with(['company', 'ownerUser'])->findOrFail($fromAccountId);
            $toAccount   = Account::query()->with(['company', 'ownerUser'])->findOrFail($toAccountId);

            if ($fromAccount->status !== 'active' || $toAccount->status !== 'active') {
                throw new RuntimeException('Entrambi i conti devono essere attivi.');
            }
            if ($fromAccount->currency_code !== $toAccount->currency_code) {
                throw new RuntimeException('I conti devono usare la stessa valuta.');
            }

            $plan = PaymentPlan::create([
                'initiated_by'       => $initiator->id,
                'from_account_id'    => $fromAccount->id,
                'to_account_id'      => $toAccount->id,
                'total_amount'       => $totalAmount,
                'currency_code'      => $fromAccount->currency_code,
                'installments_count' => $installmentsCount,
                'frequency'          => $frequency,
                'first_due_date'     => $firstDueDate->toDateString(),
                'description'        => $description,
                'status'             => 'pending_approval',
            ]);

            $schedule = PaymentPlan::buildSchedule($totalAmount, $installmentsCount, $frequency, $firstDueDate);
            foreach ($schedule as $row) {
                PaymentPlanInstallment::create([
                    'payment_plan_id'    => $plan->id,
                    'installment_number' => $row['installment_number'],
                    'amount'             => $row['amount'],
                    'due_date'           => $row['due_date'],
                    'status'             => 'pending',
                ]);
            }

            AuditLog::create([
                'actor_user_id'  => $initiator->id,
                'event'          => 'payment_plan.created',
                'auditable_type' => PaymentPlan::class,
                'auditable_id'   => $plan->id,
                'ip_address'     => $ipAddress,
                'context'        => [
                    'from_account_id'    => $fromAccount->id,
                    'to_account_id'      => $toAccount->id,
                    'total_amount'       => $totalAmount,
                    'installments_count' => $installmentsCount,
                    'frequency'          => $frequency,
                ],
            ]);

            // Notifica alla controparte che deve approvare
            $counterpartyAccount = $plan->counterpartyAccount();
            $counterpartyOwner = $counterpartyAccount?->ownerUser ?? $counterpartyAccount?->company?->users()->first();
            if ($counterpartyOwner) {
                $counterpartyOwner->notify(new PaymentPlanProposedNotification($plan));
            }

            return $plan->load(['installments', 'fromAccount', 'toAccount', 'initiator']);
        });
    }

    /**
     * Approva una proposta di piano rateale (chiamata dalla controparte).
     */
    public function approve(PaymentPlan $plan, int $approvedBy, ?string $ipAddress = null): void
    {
        if ($plan->status !== 'pending_approval') {
            throw new RuntimeException('Il piano non e\' in attesa di approvazione.');
        }

        DB::transaction(function () use ($plan, $approvedBy, $ipAddress) {
            $plan->forceFill(['status' => 'active'])->save();

            AuditLog::create([
                'actor_user_id'  => $approvedBy,
                'event'          => 'payment_plan.approved',
                'auditable_type' => PaymentPlan::class,
                'auditable_id'   => $plan->id,
                'ip_address'     => $ipAddress,
                'context'        => ['approved_by' => $approvedBy],
            ]);

            // Notifica al proponente
            $proposerAccount = $plan->proposerAccount();
            $proposerOwner = $proposerAccount?->ownerUser ?? $proposerAccount?->company?->users()->first();
            if ($proposerOwner) {
                $proposerOwner->notify(new PaymentPlanApprovedNotification($plan));
            }
        });
    }

    /**
     * Rifiuta una proposta di piano rateale (chiamata dalla controparte).
     */
    public function reject(PaymentPlan $plan, int $rejectedBy, ?string $ipAddress = null): void
    {
        if ($plan->status !== 'pending_approval') {
            throw new RuntimeException('Il piano non e\' in attesa di approvazione.');
        }

        DB::transaction(function () use ($plan, $rejectedBy, $ipAddress) {
            // Cancella le rate pendenti
            PaymentPlanInstallment::query()
                ->where('payment_plan_id', $plan->id)
                ->where('status', 'pending')
                ->update(['status' => 'cancelled']);

            $plan->forceFill(['status' => 'rejected'])->save();

            AuditLog::create([
                'actor_user_id'  => $rejectedBy,
                'event'          => 'payment_plan.rejected',
                'auditable_type' => PaymentPlan::class,
                'auditable_id'   => $plan->id,
                'ip_address'     => $ipAddress,
                'context'        => ['rejected_by' => $rejectedBy],
            ]);

            // Notifica al proponente
            $proposerAccount = $plan->proposerAccount();
            $proposerOwner = $proposerAccount?->ownerUser ?? $proposerAccount?->company?->users()->first();
            if ($proposerOwner) {
                $proposerOwner->notify(new PaymentPlanRejectedNotification($plan));
            }
        });
    }

    /**
     * Process a single installment: book the transfer and mark as paid.
     * Called by ProcessDueInstallments job. Safe to call inside a transaction.
     */
    public function processInstallment(PaymentPlanInstallment $installment): Transfer
    {
        return DB::transaction(function () use ($installment) {
            $inst = PaymentPlanInstallment::query()
                ->with(['paymentPlan.fromAccount', 'paymentPlan.toAccount', 'paymentPlan.initiator'])
                ->lockForUpdate()
                ->findOrFail($installment->id);

            if ($inst->status !== 'pending') {
                throw new RuntimeException('La rata non e in stato pending.');
            }

            $plan        = $inst->paymentPlan;
            $fromAccount = Account::query()->with(['company', 'ownerUser', 'parentAccount'])->lockForUpdate()->findOrFail($plan->from_account_id);
            $toAccount   = Account::query()->with(['company', 'ownerUser'])->lockForUpdate()->findOrFail($plan->to_account_id);

            if ($fromAccount->status !== 'active' || $toAccount->status !== 'active') {
                $inst->forceFill([
                    'status'         => 'failed',
                    'processed_at'   => CarbonImmutable::now(),
                    'failure_reason' => 'Uno o entrambi i conti non sono attivi.',
                ])->save();
                throw new RuntimeException('Uno o entrambi i conti non sono attivi.');
            }

            $amount             = (int) $inst->amount;
            $bookedAt           = CarbonImmutable::now();
            $debitBalanceAfter  = $fromAccount->available_balance - $amount;
            $creditBalanceAfter = $toAccount->available_balance + $amount;

            // Check balance (soft check — allow if account permits negative)
            if (! $fromAccount->allow_negative_balance && $debitBalanceAfter < 0) {
                $inst->forceFill([
                    'status'         => 'failed',
                    'processed_at'   => CarbonImmutable::now(),
                    'failure_reason' => 'Saldo insufficiente per la rata ' . $inst->installment_number . '.',
                ])->save();
                throw new RuntimeException('Saldo insufficiente per la rata ' . $inst->installment_number . '.');
            }

            $fromAccount->forceFill(['available_balance' => $debitBalanceAfter])->save();
            $toAccount->forceFill(['available_balance'   => $creditBalanceAfter])->save();

            $transfer = Transfer::create([
                'initiated_by'    => $plan->initiated_by,
                'from_account_id' => $fromAccount->id,
                'to_account_id'   => $toAccount->id,
                'amount'          => $amount,
                'currency_code'   => $plan->currency_code,
                'status'          => 'booked',
                'kind'            => 'portal_installment',
                'idempotency_key' => (string) Str::uuid(),
                'description'     => trim(($plan->description ? $plan->description . ' — ' : '') . 'Rata ' . $inst->installment_number . '/' . $plan->installments_count),
                'booked_at'       => $bookedAt,
            ]);

            \App\Models\LedgerEntry::create([
                'transfer_id'   => $transfer->id,
                'account_id'    => $fromAccount->id,
                'direction'     => 'debit',
                'amount'        => $amount,
                'balance_after' => $debitBalanceAfter,
                'posted_at'     => $bookedAt,
                'meta'          => ['counterparty_account_id' => $toAccount->id, 'payment_plan_id' => $plan->id, 'installment_number' => $inst->installment_number],
            ]);

            \App\Models\LedgerEntry::create([
                'transfer_id'   => $transfer->id,
                'account_id'    => $toAccount->id,
                'direction'     => 'credit',
                'amount'        => $amount,
                'balance_after' => $creditBalanceAfter,
                'posted_at'     => $bookedAt,
                'meta'          => ['counterparty_account_id' => $fromAccount->id, 'payment_plan_id' => $plan->id, 'installment_number' => $inst->installment_number],
            ]);

            $inst->forceFill([
                'status'       => 'paid',
                'transfer_id'  => $transfer->id,
                'processed_at' => $bookedAt,
            ])->save();

            // Mark plan as completed if all installments are done
            $remaining = PaymentPlanInstallment::query()
                ->where('payment_plan_id', $plan->id)
                ->whereIn('status', ['pending'])
                ->count();
            if ($remaining === 0) {
                $plan->forceFill(['status' => 'completed'])->save();
            }

            AuditLog::create([
                'actor_user_id'  => $plan->initiated_by,
                'event'          => 'payment_plan.installment_paid',
                'auditable_type' => PaymentPlan::class,
                'auditable_id'   => $plan->id,
                'ip_address'     => null,
                'context'        => [
                    'installment_id'     => $inst->id,
                    'installment_number' => $inst->installment_number,
                    'transfer_id'        => $transfer->id,
                    'amount'             => $amount,
                ],
            ]);

            return $transfer;
        });
    }

    /**
     * Cancel a payment plan and all pending installments.
     */
    public function cancel(PaymentPlan $plan, int $cancelledBy, ?string $ipAddress = null): void
    {
        DB::transaction(function () use ($plan, $cancelledBy, $ipAddress) {
            PaymentPlanInstallment::query()
                ->where('payment_plan_id', $plan->id)
                ->where('status', 'pending')
                ->update(['status' => 'cancelled']);

            $plan->forceFill(['status' => 'cancelled'])->save();

            AuditLog::create([
                'actor_user_id'  => $cancelledBy,
                'event'          => 'payment_plan.cancelled',
                'auditable_type' => PaymentPlan::class,
                'auditable_id'   => $plan->id,
                'ip_address'     => $ipAddress,
                'context'        => ['cancelled_by' => $cancelledBy],
            ]);
        });
    }
}
