<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\CreditLimit;
use App\Models\LedgerEntry;
use App\Models\Transfer;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class TransferBookingService
{

    public function book(array $attributes): Transfer
    {
        $amount = (int) ($attributes['amount'] ?? 0);
        $fromAccountId = (int) ($attributes['from_account_id'] ?? 0);
        $toAccountId = (int) ($attributes['to_account_id'] ?? 0);
        $initiatedBy = (int) ($attributes['initiated_by'] ?? 0);
        $idempotencyKey = (string) ($attributes['idempotency_key'] ?? '');
        $ipAddress = $attributes['ip_address'] ?? null;

        $this->assertTransferPayload($amount, $fromAccountId, $toAccountId, $initiatedBy, $idempotencyKey);

        return DB::transaction(function () use ($attributes, $amount, $fromAccountId, $toAccountId, $initiatedBy, $idempotencyKey, $ipAddress) {
            $existingTransfer = Transfer::query()
                ->where('idempotency_key', $idempotencyKey)
                ->with('ledgerEntries')
                ->first();

            if ($existingTransfer !== null) {
                return $existingTransfer;
            }

            $initiator = User::query()->with(['company', 'managedAccount', 'roles.permissions'])->findOrFail($initiatedBy);
            $fromAccount = Account::query()->with(['company', 'ownerUser', 'parentAccount'])->lockForUpdate()->findOrFail($fromAccountId);
            $toAccount = Account::query()->with(['company', 'ownerUser'])->lockForUpdate()->findOrFail($toAccountId);

            $this->assertAccountsOperational($fromAccount, $toAccount);
            $this->assertAuthorizedInitiator($initiator, $fromAccount);

            if ($fromAccount->currency_code !== $toAccount->currency_code) {
                throw new RuntimeException('Accounts must use the same currency.');
            }

            return $this->bookSettledTransfer(
                attributes: $attributes,
                fromAccount: $fromAccount,
                toAccount: $toAccount,
                initiator: $initiator,
                amount: $amount,
                ipAddress: $ipAddress,
                idempotencyKey: $idempotencyKey,
            );
        });
    }

    public function requestPayment(array $attributes): Transfer
    {
        $amount = (int) ($attributes['amount'] ?? 0);
        $fromAccountId = (int) ($attributes['from_account_id'] ?? 0);
        $toAccountId = (int) ($attributes['to_account_id'] ?? 0);
        $initiatedBy = (int) ($attributes['initiated_by'] ?? 0);
        $idempotencyKey = (string) ($attributes['idempotency_key'] ?? '');
        $ipAddress = $attributes['ip_address'] ?? null;

        $this->assertTransferPayload($amount, $fromAccountId, $toAccountId, $initiatedBy, $idempotencyKey);

        return DB::transaction(function () use ($attributes, $amount, $fromAccountId, $toAccountId, $initiatedBy, $idempotencyKey, $ipAddress) {
            $existingTransfer = Transfer::query()
                ->where('idempotency_key', $idempotencyKey)
                ->with(['fromAccount', 'toAccount'])
                ->first();

            if ($existingTransfer !== null) {
                return $existingTransfer;
            }

            $initiator = User::query()->with(['company', 'managedAccount', 'roles.permissions'])->findOrFail($initiatedBy);
            $fromAccount = Account::query()->with(['company', 'ownerUser'])->lockForUpdate()->findOrFail($fromAccountId);
            $toAccount = Account::query()->with(['company', 'ownerUser', 'parentAccount'])->lockForUpdate()->findOrFail($toAccountId);

            $this->assertAccountsOperational($fromAccount, $toAccount);
            $this->assertAuthorizedReceiver($initiator, $toAccount);

            if ($fromAccount->currency_code !== $toAccount->currency_code) {
                throw new RuntimeException('Accounts must use the same currency.');
            }

            $transfer = Transfer::create([
                'initiated_by' => $initiator->id,
                'from_account_id' => $fromAccount->id,
                'to_account_id' => $toAccount->id,
                'amount' => $amount,
                'currency_code' => $fromAccount->currency_code,
                'status' => 'pending',
                'kind' => $attributes['kind'] ?? 'portal_collection_request',
                'idempotency_key' => $idempotencyKey,
                'description' => $attributes['description'] ?? null,
                'booked_at' => null,
            ]);

            AuditLog::create([
                'actor_user_id' => $initiator->id,
                'event' => 'transfer.requested',
                'auditable_type' => Transfer::class,
                'auditable_id' => $transfer->id,
                'ip_address' => $ipAddress,
                'context' => [
                    'from_account_id' => $fromAccount->id,
                    'to_account_id' => $toAccount->id,
                    'amount' => $amount,
                    'currency_code' => $fromAccount->currency_code,
                    'kind' => $transfer->kind,
                ],
            ]);

            return $transfer->load(['fromAccount', 'toAccount', 'initiator']);
        });
    }

    public function confirmRequest(Transfer $transfer, int $confirmedBy, ?string $ipAddress = null): Transfer
    {
        return DB::transaction(function () use ($transfer, $confirmedBy, $ipAddress) {
            $pendingTransfer = Transfer::query()
                ->with(['fromAccount.company', 'fromAccount.ownerUser', 'toAccount.company', 'toAccount.ownerUser', 'ledgerEntries'])
                ->lockForUpdate()
                ->findOrFail($transfer->id);

            if ($pendingTransfer->status !== 'pending') {
                throw new RuntimeException('Only pending requests can be confirmed.');
            }

            $confirmer = User::query()->with(['company', 'managedAccount', 'roles.permissions'])->findOrFail($confirmedBy);
            $fromAccount = Account::query()->with(['company', 'ownerUser', 'parentAccount'])->lockForUpdate()->findOrFail($pendingTransfer->from_account_id);
            $toAccount = Account::query()->with(['company', 'ownerUser'])->lockForUpdate()->findOrFail($pendingTransfer->to_account_id);

            $this->assertAccountsOperational($fromAccount, $toAccount);
            $this->assertAuthorizedInitiator($confirmer, $fromAccount);

            if ($fromAccount->currency_code !== $toAccount->currency_code) {
                throw new RuntimeException('Accounts must use the same currency.');
            }

            $this->assertTransferWithinLimits($fromAccount, (int) $pendingTransfer->amount, $confirmer);

            $bookedAt = CarbonImmutable::now();
            $debitBalanceAfter = $fromAccount->available_balance - (int) $pendingTransfer->amount;
            $creditBalanceAfter = $toAccount->available_balance + (int) $pendingTransfer->amount;

            $fromAccount->forceFill(['available_balance' => $debitBalanceAfter])->save();
            $toAccount->forceFill(['available_balance' => $creditBalanceAfter])->save();

            $pendingTransfer->forceFill([
                'initiated_by' => $confirmer->id,
                'status' => 'booked',
                'booked_at' => $bookedAt,
            ])->save();

            LedgerEntry::create([
                'transfer_id' => $pendingTransfer->id,
                'account_id' => $fromAccount->id,
                'direction' => 'debit',
                'amount' => (int) $pendingTransfer->amount,
                'balance_after' => $debitBalanceAfter,
                'posted_at' => $bookedAt,
                'meta' => [
                    'counterparty_account_id' => $toAccount->id,
                    'confirmed_by_user_id' => $confirmer->id,
                ],
            ]);

            LedgerEntry::create([
                'transfer_id' => $pendingTransfer->id,
                'account_id' => $toAccount->id,
                'direction' => 'credit',
                'amount' => (int) $pendingTransfer->amount,
                'balance_after' => $creditBalanceAfter,
                'posted_at' => $bookedAt,
                'meta' => [
                    'counterparty_account_id' => $fromAccount->id,
                    'confirmed_by_user_id' => $confirmer->id,
                ],
            ]);

            AuditLog::create([
                'actor_user_id' => $confirmer->id,
                'event' => 'transfer.request_confirmed',
                'auditable_type' => Transfer::class,
                'auditable_id' => $pendingTransfer->id,
                'ip_address' => $ipAddress,
                'context' => [
                    'from_account_id' => $fromAccount->id,
                    'to_account_id' => $toAccount->id,
                    'amount' => $pendingTransfer->amount,
                ],
            ]);

            return $pendingTransfer->load(['ledgerEntries', 'fromAccount', 'toAccount', 'initiator']);
        });
    }

    public function rejectRequest(Transfer $transfer, int $rejectedBy, ?string $ipAddress = null): Transfer
    {
        return DB::transaction(function () use ($transfer, $rejectedBy, $ipAddress) {
            $pendingTransfer = Transfer::query()->lockForUpdate()->findOrFail($transfer->id);

            if ($pendingTransfer->status !== 'pending') {
                throw new RuntimeException('Only pending requests can be rejected.');
            }

            $rejector = User::query()->with(['company', 'managedAccount', 'roles.permissions'])->findOrFail($rejectedBy);
            $fromAccount = Account::query()->with(['company', 'ownerUser', 'parentAccount'])->findOrFail($pendingTransfer->from_account_id);
            $this->assertAuthorizedInitiator($rejector, $fromAccount);

            $pendingTransfer->forceFill([
                'status' => 'rejected',
            ])->save();

            AuditLog::create([
                'actor_user_id' => $rejector->id,
                'event' => 'transfer.request_rejected',
                'auditable_type' => Transfer::class,
                'auditable_id' => $pendingTransfer->id,
                'ip_address' => $ipAddress,
                'context' => [
                    'from_account_id' => $pendingTransfer->from_account_id,
                    'to_account_id' => $pendingTransfer->to_account_id,
                    'amount' => $pendingTransfer->amount,
                ],
            ]);

            return $pendingTransfer;
        });
    }



    /**
     * Issue a credit note: the initiator (seller) sends KY to the counterparty.
     * Amount is free (not bounded by any original transfer).
     * Optionally references an original transfer via reversed_transfer_id.
     */
    public function issueCreditNote(
        int $fromAccountId,
        int $toAccountId,
        int $amount,
        int $initiatedBy,
        ?string $description = null,
        ?int $originalTransferId = null,
        ?string $ipAddress = null,
    ): Transfer {
        return DB::transaction(function () use ($fromAccountId, $toAccountId, $amount, $initiatedBy, $description, $originalTransferId, $ipAddress) {
            if ($amount <= 0) {
                throw new RuntimeException("L'importo della nota di credito deve essere maggiore di zero.");
            }
            if ($fromAccountId === $toAccountId) {
                throw new RuntimeException('Il conto emittente e il conto destinatario devono essere diversi.');
            }

            $initiator   = User::query()->with(['company', 'managedAccount', 'roles.permissions'])->findOrFail($initiatedBy);
            $fromAccount = Account::query()->with(['company', 'ownerUser', 'parentAccount'])->lockForUpdate()->findOrFail($fromAccountId);
            $toAccount   = Account::query()->with(['company', 'ownerUser'])->lockForUpdate()->findOrFail($toAccountId);

            $this->assertAccountsOperational($fromAccount, $toAccount);
            $this->assertAuthorizedInitiator($initiator, $fromAccount);

            if ($fromAccount->currency_code !== $toAccount->currency_code) {
                throw new RuntimeException('I conti devono usare la stessa valuta.');
            }

            // Optional: validate original transfer reference
            $originalTransfer = null;
            if ($originalTransferId !== null) {
                $originalTransfer = Transfer::query()->find($originalTransferId);
                if ($originalTransfer === null) {
                    throw new RuntimeException('Movimento originale non trovato.');
                }
                if ($originalTransfer->status !== 'booked') {
                    throw new RuntimeException('Il movimento originale deve essere contabilizzato.');
                }
            }

            // Credit notes bypass normal spending limits (it is a goodwill credit, not a purchase)
            if (! $initiator->is_super_admin) {
                $this->assertTransferWithinLimits($fromAccount, $amount, $initiator);
            }

            $bookedAt           = CarbonImmutable::now();
            $debitBalanceAfter  = $fromAccount->available_balance - $amount;
            $creditBalanceAfter = $toAccount->available_balance + $amount;

            $fromAccount->forceFill(['available_balance' => $debitBalanceAfter])->save();
            $toAccount->forceFill(['available_balance' => $creditBalanceAfter])->save();

            $creditNote = Transfer::create([
                'initiated_by'         => $initiator->id,
                'from_account_id'      => $fromAccount->id,
                'to_account_id'        => $toAccount->id,
                'amount'               => $amount,
                'currency_code'        => $fromAccount->currency_code,
                'status'               => 'booked',
                'kind'                 => 'portal_credit_note',
                'idempotency_key'      => (string) \Illuminate\Support\Str::uuid(),
                'description'          => $description ?? 'Nota di credito',
                'booked_at'            => $bookedAt,
                'reversed_transfer_id' => $originalTransfer?->id,
                'refunded_at'          => null,
                'admin_action'         => null,
            ]);

            LedgerEntry::create([
                'transfer_id'   => $creditNote->id,
                'account_id'    => $fromAccount->id,
                'direction'     => 'debit',
                'amount'        => $amount,
                'balance_after' => $debitBalanceAfter,
                'posted_at'     => $bookedAt,
                'meta'          => array_filter([
                    'counterparty_account_id' => $toAccount->id,
                    'credit_note_for'         => $originalTransfer?->id,
                ]),
            ]);

            LedgerEntry::create([
                'transfer_id'   => $creditNote->id,
                'account_id'    => $toAccount->id,
                'direction'     => 'credit',
                'amount'        => $amount,
                'balance_after' => $creditBalanceAfter,
                'posted_at'     => $bookedAt,
                'meta'          => array_filter([
                    'counterparty_account_id' => $fromAccount->id,
                    'credit_note_for'         => $originalTransfer?->id,
                ]),
            ]);

            AuditLog::create([
                'actor_user_id'  => $initiator->id,
                'event'          => 'transfer.credit_note',
                'auditable_type' => Transfer::class,
                'auditable_id'   => $creditNote->id,
                'ip_address'     => $ipAddress,
                'context'        => array_filter([
                    'from_account_id'      => $fromAccount->id,
                    'to_account_id'        => $toAccount->id,
                    'amount'               => $amount,
                    'original_transfer_id' => $originalTransfer?->id,
                ]),
            ]);

            return $creditNote->load(['ledgerEntries', 'fromAccount', 'toAccount', 'initiator', 'reversedTransfer']);
        });
    }

    public function refundMerchant(Transfer $originalTransfer, int $refundAmount, int $initiatedBy, ?string $description = null, ?string $ipAddress = null): Transfer
    {
        return DB::transaction(function () use ($originalTransfer, $refundAmount, $initiatedBy, $description, $ipAddress) {
            // Reload with lock
            $original = Transfer::query()
                ->with(['fromAccount.company', 'fromAccount.ownerUser', 'toAccount.company', 'toAccount.ownerUser', 'toAccount.parentAccount'])
                ->lockForUpdate()
                ->findOrFail($originalTransfer->id);

            if ($original->status !== 'booked') {
                throw new RuntimeException('Solo i movimenti contabilizzati possono essere rimborsati.');
            }

            $refundableKinds = ['portal_payment', 'portal_collection_request', 'trade_payment', 'portal_refund'];
            if (! in_array($original->kind, $refundableKinds, true)) {
                throw new RuntimeException('Questo tipo di movimento non e rimborsabile dal portale.');
            }

            // Sum of already booked refunds pointing to this transfer
            $alreadyRefunded = Transfer::query()
                ->where('reversed_transfer_id', $original->id)
                ->where('status', 'booked')
                ->sum('amount');

            $maxRefundable = (int) $original->amount - (int) $alreadyRefunded;

            if ($refundAmount <= 0 || $refundAmount > $maxRefundable) {
                throw new RuntimeException(
                    'Importo non valido. Puoi rimborsare al massimo ' . number_format($maxRefundable, 2, ',', '.') . ' KY.'
                );
            }

            // The merchant is the one who received: toAccount
            // Refund goes from toAccount -> fromAccount
            $fromAccount = Account::query()->with(['company', 'ownerUser', 'parentAccount'])->lockForUpdate()->findOrFail($original->to_account_id);
            $toAccount   = Account::query()->with(['company', 'ownerUser'])->lockForUpdate()->findOrFail($original->from_account_id);

            $initiator = User::query()->with(['company', 'managedAccount', 'roles.permissions'])->findOrFail($initiatedBy);

            if (! $initiator->is_super_admin && ! $initiator->canSendFromAccount($fromAccount)) {
                throw new RuntimeException('Non sei autorizzato a emettere rimborsi da questo conto.');
            }

            if ($fromAccount->status !== 'active' || $toAccount->status !== 'active') {
                throw new RuntimeException('Entrambi i conti devono essere attivi per procedere con il rimborso.');
            }

            $bookedAt = CarbonImmutable::now();
            $debitBalanceAfter  = $fromAccount->available_balance - $refundAmount;
            $creditBalanceAfter = $toAccount->available_balance + $refundAmount;

            $fromAccount->forceFill(['available_balance' => $debitBalanceAfter])->save();
            $toAccount->forceFill(['available_balance' => $creditBalanceAfter])->save();

            $refundTransfer = Transfer::create([
                'initiated_by'         => $initiator->id,
                'from_account_id'      => $fromAccount->id,
                'to_account_id'        => $toAccount->id,
                'amount'               => $refundAmount,
                'currency_code'        => $original->currency_code,
                'status'               => 'booked',
                'kind'                 => 'portal_refund',
                'idempotency_key'      => (string) \Illuminate\Support\Str::uuid(),
                'description'          => $description ?? 'Rimborso del movimento ' . $original->reference,
                'booked_at'            => $bookedAt,
                'reversed_transfer_id' => $original->id,
                'refunded_at'          => $bookedAt,
                'admin_action'         => null,
            ]);

            LedgerEntry::create([
                'transfer_id'  => $refundTransfer->id,
                'account_id'   => $fromAccount->id,
                'direction'    => 'debit',
                'amount'       => $refundAmount,
                'balance_after'=> $debitBalanceAfter,
                'posted_at'    => $bookedAt,
                'meta'         => ['counterparty_account_id' => $toAccount->id, 'refund_of' => $original->id],
            ]);

            LedgerEntry::create([
                'transfer_id'  => $refundTransfer->id,
                'account_id'   => $toAccount->id,
                'direction'    => 'credit',
                'amount'       => $refundAmount,
                'balance_after'=> $creditBalanceAfter,
                'posted_at'    => $bookedAt,
                'meta'         => ['counterparty_account_id' => $fromAccount->id, 'refund_of' => $original->id],
            ]);

            AuditLog::create([
                'actor_user_id'   => $initiator->id,
                'event'           => 'transfer.merchant_refund',
                'auditable_type'  => Transfer::class,
                'auditable_id'    => $refundTransfer->id,
                'ip_address'      => $ipAddress,
                'context' => [
                    'original_transfer_id' => $original->id,
                    'refund_amount'        => $refundAmount,
                    'from_account_id'      => $fromAccount->id,
                    'to_account_id'        => $toAccount->id,
                ],
            ]);

            return $refundTransfer->load(['ledgerEntries', 'fromAccount', 'toAccount', 'initiator', 'reversedTransfer']);
        });
    }

    public function recordRejectedAttempt(array $attributes, string $reason): void
    {
        AuditLog::create([
            'actor_user_id' => $attributes['initiated_by'] ?? null,
            'event' => 'transfer.rejected',
            'auditable_type' => Transfer::class,
            'auditable_id' => null,
            'ip_address' => $attributes['ip_address'] ?? null,
            'context' => [
                'reason' => $reason,
                'from_account_id' => $attributes['from_account_id'] ?? null,
                'to_account_id' => $attributes['to_account_id'] ?? null,
                'amount' => $attributes['amount'] ?? null,
                'idempotency_key' => $attributes['idempotency_key'] ?? null,
            ],
        ]);
    }

    private function assertTransferPayload(int $amount, int $fromAccountId, int $toAccountId, int $initiatedBy, string $idempotencyKey): void
    {
        if ($amount <= 0) {
            throw new RuntimeException('Transfer amount must be greater than zero.');
        }

        if ($fromAccountId === $toAccountId) {
            throw new RuntimeException('Source and destination accounts must be different.');
        }

        if ($initiatedBy <= 0) {
            throw new RuntimeException('Initiator is required.');
        }

        if ($idempotencyKey === '') {
            throw new RuntimeException('Idempotency key is required.');
        }
    }

    private function bookSettledTransfer(array $attributes, Account $fromAccount, Account $toAccount, User $initiator, int $amount, ?string $ipAddress, string $idempotencyKey): Transfer
    {
        // Sub-account: enforce sub-account limits then debit the parent account
        if ($fromAccount->isSubAccount()) {
            $fromAccount->assertSubAccountSpendingLimits($amount);
            // Check parent balance/limits too
            $parentAccount = Account::query()
                ->with(['company', 'ownerUser', 'creditLimits'])
                ->lockForUpdate()
                ->findOrFail($fromAccount->parent_account_id);
            $this->assertTransferWithinLimits($parentAccount, $amount, $initiator);
            $debitBalanceAfter  = $parentAccount->available_balance - $amount;
            $creditBalanceAfter = $toAccount->available_balance + $amount;
            $parentAccount->forceFill(['available_balance' => $debitBalanceAfter])->save();
            $toAccount->forceFill(['available_balance' => $creditBalanceAfter])->save();
            $bookedAt = \Carbon\CarbonImmutable::now();
            $transfer = Transfer::create([
                'initiated_by'    => $initiator->id,
                'from_account_id' => $fromAccount->id,
                'to_account_id'   => $toAccount->id,
                'amount'          => $amount,
                'currency_code'   => $fromAccount->currency_code,
                'status'          => 'booked',
                'kind'            => $attributes['kind'] ?? 'trade_payment',
                'idempotency_key' => $idempotencyKey,
                'description'     => $attributes['description'] ?? null,
                'booked_at'       => $bookedAt,
            ]);
            LedgerEntry::create([
                'transfer_id'   => $transfer->id,
                'account_id'    => $parentAccount->id,
                'direction'     => 'debit',
                'amount'        => $amount,
                'balance_after' => $debitBalanceAfter,
                'posted_at'     => $bookedAt,
                'meta'          => [
                    'counterparty_account_id' => $toAccount->id,
                    'sub_account_id'          => $fromAccount->id,
                ],
            ]);
            LedgerEntry::create([
                'transfer_id'   => $transfer->id,
                'account_id'    => $toAccount->id,
                'direction'     => 'credit',
                'amount'        => $amount,
                'balance_after' => $creditBalanceAfter,
                'posted_at'     => $bookedAt,
                'meta'          => ['counterparty_account_id' => $parentAccount->id],
            ]);
            AuditLog::create([
                'actor_user_id'  => $initiator->id,
                'event'          => 'transfer.booked',
                'auditable_type' => Transfer::class,
                'auditable_id'   => $transfer->id,
                'ip_address'     => $ipAddress,
                'context'        => [
                    'from_account_id'    => $fromAccount->id,
                    'parent_account_id'  => $parentAccount->id,
                    'to_account_id'      => $toAccount->id,
                    'amount'             => $amount,
                    'currency_code'      => $fromAccount->currency_code,
                ],
            ]);
            return $transfer->load(['ledgerEntries', 'fromAccount', 'toAccount', 'initiator']);
        }

        $this->assertTransferWithinLimits($fromAccount, $amount, $initiator);

        $debitBalanceAfter = $fromAccount->available_balance - $amount;
        $creditBalanceAfter = $toAccount->available_balance + $amount;

        $fromAccount->forceFill([
            'available_balance' => $debitBalanceAfter,
        ])->save();

        $toAccount->forceFill([
            'available_balance' => $creditBalanceAfter,
        ])->save();

        $bookedAt = CarbonImmutable::now();

        $transfer = Transfer::create([
            'initiated_by' => $initiator->id,
            'from_account_id' => $fromAccount->id,
            'to_account_id' => $toAccount->id,
            'amount' => $amount,
            'currency_code' => $fromAccount->currency_code,
            'status' => 'booked',
            'kind' => $attributes['kind'] ?? 'trade_payment',
            'idempotency_key' => $idempotencyKey,
            'description' => $attributes['description'] ?? null,
            'booked_at' => $bookedAt,
        ]);

        LedgerEntry::create([
            'transfer_id' => $transfer->id,
            'account_id' => $fromAccount->id,
            'direction' => 'debit',
            'amount' => $amount,
            'balance_after' => $debitBalanceAfter,
            'posted_at' => $bookedAt,
            'meta' => [
                'counterparty_account_id' => $toAccount->id,
            ],
        ]);

        LedgerEntry::create([
            'transfer_id' => $transfer->id,
            'account_id' => $toAccount->id,
            'direction' => 'credit',
            'amount' => $amount,
            'balance_after' => $creditBalanceAfter,
            'posted_at' => $bookedAt,
            'meta' => [
                'counterparty_account_id' => $fromAccount->id,
            ],
        ]);

        AuditLog::create([
            'actor_user_id' => $initiator->id,
            'event' => 'transfer.booked',
            'auditable_type' => Transfer::class,
            'auditable_id' => $transfer->id,
            'ip_address' => $ipAddress,
            'context' => [
                'from_account_id' => $fromAccount->id,
                'to_account_id' => $toAccount->id,
                'amount' => $amount,
                'currency_code' => $fromAccount->currency_code,
            ],
        ]);

        // Applica commissione di transazione (se configurata)
        $fee = \App\Models\TransactionFee::calculate($kind, $amount);
        if ($fee > 0) {
            $systemAccount = \App\Models\Account::systemAccount();
            if ($systemAccount) {
                try {
                    $this->bookFee($fromAccount, $systemAccount, $fee, $kind, $transfer);
                } catch (\Throwable) {
                    // fee non bloccante
                }
            }
        }

        // Applica eventuale cashback (fire-and-forget, risolto lazily per evitare dipendenza circolare)
        app(\App\Services\CashbackService::class)->applyIfEligible($transfer);

        return $transfer->load(['ledgerEntries', 'fromAccount', 'toAccount', 'initiator']);
    }

    private function assertAuthorizedInitiator(User $initiator, Account $fromAccount): void
    {
        if (! $initiator->is_active) {
            throw new RuntimeException('Initiator is not active.');
        }

        if ($initiator->is_super_admin) {
            return;
        }

        if (! $initiator->canSendFromAccount($fromAccount)) {
            throw new RuntimeException('Initiator is not allowed to operate on this account.');
        }
    }

    private function assertAuthorizedReceiver(User $initiator, Account $toAccount): void
    {
        if (! $initiator->is_active) {
            throw new RuntimeException('Initiator is not active.');
        }

        if ($initiator->is_super_admin) {
            return;
        }

        if (! $initiator->canReceiveIntoAccount($toAccount)) {
            throw new RuntimeException('Initiator is not allowed to request payment for this account.');
        }
    }

    private function assertAccountsOperational(Account $fromAccount, Account $toAccount): void
    {
        if ($fromAccount->status !== 'active' || $toAccount->status !== 'active') {
            throw new RuntimeException('Both accounts must be active.');
        }

        if ($fromAccount->owner_type === 'company' && $fromAccount->company?->status !== 'active') {
            throw new RuntimeException('The source company must be active.');
        }

        if ($toAccount->owner_type === 'company' && $toAccount->company?->status !== 'active') {
            throw new RuntimeException('The destination company must be active.');
        }
    }

    private function assertTransferWithinLimits(Account $account, int $amount, ?User $initiator = null): void
    {
        $creditLimit = CreditLimit::query()
            ->where('account_id', $account->id)
            ->where('status', 'active')
            ->latest('id')
            ->first();

        if ($initiator?->is_super_admin) {
            return;
        }

        $limits = $initiator?->effectiveTransferLimits() ?? [];
        $projectedBalance = $account->available_balance - $amount;
        $creditExposureLimit = max(0, (int) ($creditLimit?->credit_limit ?? 0));

        if ($creditExposureLimit > 0 && $projectedBalance < -$creditExposureLimit) {
            throw new RuntimeException('Transfer exceeds the allowed credit exposure.');
        }

        $effectiveNegativeBalanceLimit = max(0, (int) ($limits['negative_balance_limit'] ?? 0));
        if ($creditExposureLimit === 0 && $projectedBalance < -$effectiveNegativeBalanceLimit) {
            throw new RuntimeException('Transfer exceeds the allowed negative balance.');
        }

        $circuitCapacityLimit = $limits['circuit_capacity_limit'] ?? null;
        if ($circuitCapacityLimit !== null && $amount > $circuitCapacityLimit) {
            throw new RuntimeException('Transfer exceeds the circuit capacity limit.');
        }

        $singleTransferLimit = $limits['per_movement_limit'] ?? $account->spending_limit ?? $creditLimit?->single_transfer_limit;
        if ($singleTransferLimit !== null && $amount > $singleTransferLimit) {
            throw new RuntimeException('Transfer exceeds the single transfer limit.');
        }

        $dailyLimit = $limits['daily_transaction_limit'] ?? $account->daily_outgoing_limit ?? $creditLimit?->daily_outgoing_limit;
        if ($dailyLimit !== null && $initiator !== null) {
            $startOfDay = CarbonImmutable::now()->startOfDay();
            $endOfDay = CarbonImmutable::now()->endOfDay();

            $outgoingToday = Transfer::query()
                ->where('from_account_id', $account->id)
                ->where('initiated_by', $initiator->id)
                ->where('status', 'booked')
                ->whereBetween('booked_at', [$startOfDay, $endOfDay])
                ->sum('amount');

            if (($outgoingToday + $amount) > $dailyLimit) {
                throw new RuntimeException('Transfer exceeds the daily outgoing limit.');
            }
        }

        $monthlyLimit = $limits['monthly_transaction_limit'] ?? null;
        if ($monthlyLimit !== null && $initiator !== null) {
            $startOfMonth = CarbonImmutable::now()->startOfMonth();
            $endOfMonth = CarbonImmutable::now()->endOfMonth();

            $outgoingMonth = Transfer::query()
                ->where('from_account_id', $account->id)
                ->where('initiated_by', $initiator->id)
                ->where('status', 'booked')
                ->whereBetween('booked_at', [$startOfMonth, $endOfMonth])
                ->sum('amount');

            if (($outgoingMonth + $amount) > $monthlyLimit) {
                throw new RuntimeException('Transfer exceeds the monthly outgoing limit.');
            }
        }
    }
    private function bookFee(
        \App\Models\Account $fromAccount,
        \App\Models\Account $systemAccount,
        int $fee,
        string $kind,
        \App\Models\Transfer $parentTransfer
    ): void {
        $idempotencyKey = 'fee_' . $parentTransfer->uuid;

        // Evita doppia commissione
        if (\App\Models\Transfer::where('idempotency_key', $idempotencyKey)->exists()) {
            return;
        }

        \DB::transaction(function () use ($fromAccount, $systemAccount, $fee, $kind, $parentTransfer, $idempotencyKey) {
            $transfer = \App\Models\Transfer::create([
                'uuid'             => \Illuminate\Support\Str::uuid()->toString(),
                'from_account_id'  => $fromAccount->id,
                'to_account_id'    => $systemAccount->id,
                'amount'           => $fee,
                'currency_code'    => $fromAccount->currency_code ?? 'KY',
                'kind'             => 'portal_fee',
                'status'           => 'booked',
                'reference'        => 'FEE-' . strtoupper(\Illuminate\Support\Str::random(8)),
                'description'      => 'Commissione su ' . $kind,
                'idempotency_key'  => $idempotencyKey,
                'booked_at'        => now(),
                'related_transfer_id' => $parentTransfer->id,
            ]);

            $fromAccount->decrement('available_balance', $fee);
            $systemAccount->increment('available_balance', $fee);

            \App\Models\LedgerEntry::create(['transfer_id' => $transfer->id, 'account_id' => $fromAccount->id, 'type' => 'debit',  'amount' => $fee]);
            \App\Models\LedgerEntry::create(['transfer_id' => $transfer->id, 'account_id' => $systemAccount->id, 'type' => 'credit', 'amount' => $fee]);
        });
    }

}
