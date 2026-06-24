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
use App\Exceptions\Financial\CircuitCapacityExceededException;
use App\Exceptions\Financial\CreditExposureExceededException;
use App\Exceptions\Financial\DailyLimitExceededException;
use App\Exceptions\Financial\MonthlyLimitExceededException;
use App\Exceptions\Financial\NegativeBalanceLimitExceededException;
use App\Exceptions\Financial\SingleTransferLimitExceededException;
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

        // ── Controllo blocco temporaneo anti-frode ────────────────────────────
        // (eseguito FUORI dalla transazione per non sprecare lock)
        $this->assertNotAnomalousActivity($fromAccountId, $initiatedBy, $ipAddress, $attributes);

        try {
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
                throw new RuntimeException('I due conti devono usare la stessa valuta.');
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
        } catch (RuntimeException $e) {
            // Logga ogni tentativo di trasferimento fallito (saldo insufficiente,
            // limiti superati, account sospeso, ecc.) in AuditLog, FUORI dalla
            // transazione fallita così il log viene sempre persistito.
            $this->recordRejectedAttempt($attributes, $e->getMessage());
            throw $e;
        }
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
                throw new RuntimeException('I due conti devono usare la stessa valuta.');
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
        $this->assertNotAnomalousActivity((int) $transfer->from_account_id, $confirmedBy, $ipAddress, ['amount' => $transfer->amount]);

        return DB::transaction(function () use ($transfer, $confirmedBy, $ipAddress) {
            $pendingTransfer = Transfer::query()
                ->with(['fromAccount.company', 'fromAccount.ownerUser', 'toAccount.company', 'toAccount.ownerUser', 'ledgerEntries'])
                ->lockForUpdate()
                ->findOrFail($transfer->id);

            if ($pendingTransfer->status !== 'pending') {
                throw new RuntimeException('Solo le richieste in attesa possono essere confermate.');
            }

            $confirmer = User::query()->with(['company', 'managedAccount', 'roles.permissions'])->findOrFail($confirmedBy);
            $fromAccount = Account::query()->with(['company', 'ownerUser', 'parentAccount'])->lockForUpdate()->findOrFail($pendingTransfer->from_account_id);
            $toAccount = Account::query()->with(['company', 'ownerUser'])->lockForUpdate()->findOrFail($pendingTransfer->to_account_id);

            $this->assertAccountsOperational($fromAccount, $toAccount);
            $this->assertAuthorizedInitiator($confirmer, $fromAccount);

            if ($fromAccount->currency_code !== $toAccount->currency_code) {
                throw new RuntimeException('I due conti devono usare la stessa valuta.');
            }

            $this->assertTransferWithinLimits($fromAccount, (int) $pendingTransfer->amount, $confirmer);

            $bookedAt = CarbonImmutable::now();
            $debitBalanceAfter = $fromAccount->available_balance - (int) $pendingTransfer->amount;
            $creditBalanceAfter = $toAccount->available_balance + (int) $pendingTransfer->amount;

            $fromAccount->forceFill(['available_balance' => $debitBalanceAfter])->save();
            $toAccount->forceFill(['available_balance' => $creditBalanceAfter])->save();

            $pendingTransfer->forceFill([
                // initiated_by rimane invariato: conserva chi ha CREATO la richiesta.
                // confirmed_by registra chi l'ha CONFERMATA (colonna aggiunta con migration 2026_06_05_100000).
                'confirmed_by' => $confirmer->id,
                'status'       => 'booked',
                'booked_at'    => $bookedAt,
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

            $pendingTransfer->load(['ledgerEntries', 'fromAccount', 'toAccount', 'initiator', 'confirmer']);

            // Fee e cashback eseguiti DOPO il commit della transazione principale:
            // - riduce la finestra di lock sul conto sistema
            // - garantisce che vengano eseguiti solo se il transfer è effettivamente confermato
            $transferSnapshot = $pendingTransfer;
            $fromAccountId    = $fromAccount->id;
            $kind             = $pendingTransfer->kind ?? 'portal_collection_request';
            $fee              = \App\Models\TransactionFee::calculate($kind, (int) $pendingTransfer->amount);

            DB::afterCommit(function () use ($transferSnapshot, $fromAccountId, $kind, $fee): void {
                if ($fee > 0) {
                    $systemAccount = \App\Models\Account::systemAccount();
                    if ($systemAccount) {
                        $fromAccountFresh = \App\Models\Account::find($fromAccountId);
                        if ($fromAccountFresh) {
                            try {
                                $this->bookFee($fromAccountFresh, $systemAccount, $fee, $kind, $transferSnapshot);
                            } catch (\Throwable $e) {
                                \Illuminate\Support\Facades\Log::error('fee.booking_failed', [
                                    'transfer_id' => $transferSnapshot->id,
                                    'error'       => $e->getMessage(),
                                ]);
                            }
                        }
                    }
                }

                try {
                    app(\App\Services\CashbackService::class)->applyIfEligible($transferSnapshot);
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::error('cashback.apply_failed', [
                        'transfer_id' => $transferSnapshot->id,
                        'error'       => $e->getMessage(),
                    ]);
                }
            });

            return $pendingTransfer;
        });
    }

    public function rejectRequest(Transfer $transfer, int $rejectedBy, ?string $ipAddress = null): Transfer
    {
        return DB::transaction(function () use ($transfer, $rejectedBy, $ipAddress) {
            $pendingTransfer = Transfer::query()->lockForUpdate()->findOrFail($transfer->id);

            if ($pendingTransfer->status !== 'pending') {
                throw new RuntimeException('Solo le richieste in attesa possono essere rifiutate.');
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
        ?string $idempotencyKey = null,
    ): Transfer {
        $this->assertNotAnomalousActivity($fromAccountId, $initiatedBy, $ipAddress, ['amount' => $amount]);

        return DB::transaction(function () use ($fromAccountId, $toAccountId, $amount, $initiatedBy, $description, $originalTransferId, $ipAddress, $idempotencyKey) {
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
                'idempotency_key'      => $idempotencyKey ?? (string) \Illuminate\Support\Str::uuid(),
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

    public function refundMerchant(Transfer $originalTransfer, int $refundAmount, int $initiatedBy, ?string $description = null, ?string $ipAddress = null, ?string $idempotencyKey = null): Transfer
    {
        // Il merchant che emette il rimborso è il toAccount del movimento originale
        $this->assertNotAnomalousActivity((int) $originalTransfer->to_account_id, $initiatedBy, $ipAddress, ['amount' => $refundAmount]);

        return DB::transaction(function () use ($originalTransfer, $refundAmount, $initiatedBy, $description, $ipAddress, $idempotencyKey) {
            // Reload with lock
            $original = Transfer::query()
                ->with(['fromAccount.company', 'fromAccount.ownerUser', 'toAccount.company', 'toAccount.ownerUser', 'toAccount.parentAccount'])
                ->lockForUpdate()
                ->findOrFail($originalTransfer->id);

            if ($original->status !== 'booked') {
                throw new RuntimeException('Solo i movimenti contabilizzati possono essere rimborsati.');
            }

            $refundableKinds = ['portal_payment', 'portal_payment_request', 'portal_collection_request', 'trade_payment', 'nfc_card', 'code'];
            if (! in_array($original->kind, $refundableKinds, true)) {
                throw new RuntimeException('Questo tipo di movimento non è rimborsabile dal portale.');
            }

            // Sum of already booked refunds pointing to this transfer
            $alreadyRefunded = Transfer::query()
                ->where('reversed_transfer_id', $original->id)
                ->where('status', 'booked')
                ->sum('amount');

            $maxRefundable = (int) $original->amount - (int) $alreadyRefunded;

            if ($refundAmount <= 0 || $refundAmount > $maxRefundable) {
                throw new RuntimeException(
                    'Importo non valido. Puoi rimborsare al massimo ' . ky_format($maxRefundable) . ' KY.'
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
                'idempotency_key'      => $idempotencyKey ?? (string) \Illuminate\Support\Str::uuid(),
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

    /**
     * Verifica che non ci siano segnali di attività anomala:
     *  1. Account bloccato temporaneamente (locked_until nel futuro)
     *  2. Più di 3 tentativi falliti negli ultimi 5 minuti → blocco automatico 30 min
     */
    private function assertNotAnomalousActivity(
        int $fromAccountId,
        int $initiatedBy,
        ?string $ipAddress,
        array $attributes,
    ): void {
        // 1. Verifica blocco temporaneo
        $account = \App\Models\Account::find($fromAccountId);
        if ($account && $account->isTemporarilyLocked()) {
            $until = $account->locked_until->format('H:i');
            AuditLog::create([
                'actor_user_id'  => $initiatedBy ?: null,
                'event'          => 'transfer.blocked',
                'auditable_type' => \App\Models\Account::class,
                'auditable_id'   => $fromAccountId,
                'ip_address'     => $ipAddress,
                'context'        => [
                    'reason'         => 'account_locked',
                    'locked_until'   => $account->locked_until->toIso8601String(),
                    'from_account_id'=> $fromAccountId,
                    'amount'         => $attributes['amount'] ?? null,
                ],
            ]);
            throw new RuntimeException(
                "Il conto è temporaneamente bloccato per sicurezza fino alle {$until}. Contatta l'assistenza se ritieni sia un errore."
            );
        }

        // 2. Conta i tentativi falliti recenti
        $recentFailures = AuditLog::where('actor_user_id', $initiatedBy)
            ->where('event', 'transfer.rejected')
            ->where('created_at', '>=', now()->subMinutes(5))
            ->count();

        if ($recentFailures >= 3) {
            // Blocca il conto per 30 minuti
            if ($account) {
                $account->lockTemporarily(30);
            }

            AuditLog::create([
                'actor_user_id'  => $initiatedBy ?: null,
                'event'          => 'account.auto_locked',
                'auditable_type' => \App\Models\Account::class,
                'auditable_id'   => $fromAccountId,
                'ip_address'     => $ipAddress,
                'context'        => [
                    'reason'          => 'too_many_failures',
                    'failures_count'  => $recentFailures,
                    'window_minutes'  => 5,
                    'locked_minutes'  => 30,
                    'from_account_id' => $fromAccountId,
                ],
            ]);

            throw new RuntimeException(
                'Troppi tentativi falliti in poco tempo. Il conto è stato bloccato temporaneamente per 30 minuti. Contatta l\'assistenza se hai bisogno di sblocco immediato.'
            );
        }
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
            throw new RuntimeException("L'importo del trasferimento deve essere maggiore di zero.");
        }

        if ($fromAccountId === $toAccountId) {
            throw new RuntimeException('Il conto mittente e il conto destinatario devono essere diversi.');
        }

        if ($initiatedBy <= 0) {
            throw new RuntimeException('Utente iniziatore non specificato.');
        }

        if ($idempotencyKey === '') {
            throw new RuntimeException('Chiave di idempotenza obbligatoria.');
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
            $transfer->load(['ledgerEntries', 'fromAccount', 'toAccount', 'initiator']);

            // Notifica al titolare del conto padre
            $this->notifyParentOfSubAccountTransfer($transfer, $fromAccount, $parentAccount, $initiator);

            // Fee e cashback dopo il commit (conto padre paga la fee, come per trasferimenti normali)
            $kind            = $transfer->kind;
            $fee             = \App\Models\TransactionFee::calculate($kind, $amount);
            $parentAccountId = $parentAccount->id;

            DB::afterCommit(function () use ($transfer, $parentAccountId, $kind, $fee): void {
                if ($fee > 0) {
                    $systemAccount = \App\Models\Account::systemAccount();
                    if ($systemAccount) {
                        $parentFresh = \App\Models\Account::find($parentAccountId);
                        if ($parentFresh) {
                            try {
                                $this->bookFee($parentFresh, $systemAccount, $fee, $kind, $transfer);
                            } catch (\Throwable $e) {
                                \Illuminate\Support\Facades\Log::error('fee.booking_failed', [
                                    'transfer_id' => $transfer->id,
                                    'error'       => $e->getMessage(),
                                ]);
                            }
                        }
                    }
                }

                try {
                    app(\App\Services\CashbackService::class)->applyIfEligible($transfer);
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::error('cashback.apply_failed', [
                        'transfer_id' => $transfer->id,
                        'error'       => $e->getMessage(),
                    ]);
                }
            });

            return $transfer;
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

        $transferLoaded = $transfer->load(['ledgerEntries', 'fromAccount', 'toAccount', 'initiator']);

        // Fee e cashback dopo il commit: riduce lock sul conto sistema e isola gli errori
        $kind          = $transfer->kind;
        $fee           = \App\Models\TransactionFee::calculate($kind, $amount);
        $fromAccountId = $fromAccount->id;

        DB::afterCommit(function () use ($transfer, $fromAccountId, $kind, $fee): void {
            if ($fee > 0) {
                $systemAccount = \App\Models\Account::systemAccount();
                if ($systemAccount) {
                    $fromAccountFresh = \App\Models\Account::find($fromAccountId);
                    if ($fromAccountFresh) {
                        try {
                            $this->bookFee($fromAccountFresh, $systemAccount, $fee, $kind, $transfer);
                        } catch (\Throwable $e) {
                            \Illuminate\Support\Facades\Log::error('fee.booking_failed', [
                                'transfer_id' => $transfer->id,
                                'error'       => $e->getMessage(),
                            ]);
                        }
                    }
                }
            }

            try {
                app(\App\Services\CashbackService::class)->applyIfEligible($transfer);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error('cashback.apply_failed', [
                    'transfer_id' => $transfer->id,
                    'error'       => $e->getMessage(),
                ]);
            }
        });

        return $transferLoaded;
    }

    private function assertAuthorizedInitiator(User $initiator, Account $fromAccount): void
    {
        if (! $initiator->is_active) {
            throw new RuntimeException('Il tuo account non è attivo.');
        }

        if ($initiator->is_super_admin) {
            return;
        }

        // Broker autorizzato se e' il broker assegnato all'azienda del conto
        if ($initiator->hasRole('broker') && $fromAccount->company?->broker_user_id === $initiator->id) {
            return;
        }

        if (! $initiator->canSendFromAccount($fromAccount)) {
            throw new RuntimeException('Non sei autorizzato a operare su questo conto.');
        }
    }

    private function assertAuthorizedReceiver(User $initiator, Account $toAccount): void
    {
        if (! $initiator->is_active) {
            throw new RuntimeException('Il tuo account non è attivo.');
        }

        if ($initiator->is_super_admin) {
            return;
        }

        if (! $initiator->canReceiveIntoAccount($toAccount)) {
            throw new RuntimeException('Non sei autorizzato a richiedere pagamenti su questo conto.');
        }
    }

    private function assertAccountsOperational(Account $fromAccount, Account $toAccount): void
    {
        if ($fromAccount->status !== 'active' || $toAccount->status !== 'active') {
            throw new RuntimeException('Entrambi i conti devono essere attivi per procedere.');
        }

        if ($fromAccount->owner_type === 'company' && $fromAccount->company?->status !== 'active') {
            throw new RuntimeException("L'azienda mittente non è attiva nel circuito.");
        }

        if ($toAccount->owner_type === 'company' && $toAccount->company?->status !== 'active') {
            throw new RuntimeException("L'azienda destinataria non è attiva nel circuito.");
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
            throw new CreditExposureExceededException($creditExposureLimit, $account->available_balance, $amount);
        }

        $effectiveNegativeBalanceLimit = max(0, (int) ($limits['negative_balance_limit'] ?? 0));
        if ($creditExposureLimit === 0 && $projectedBalance < -$effectiveNegativeBalanceLimit) {
            throw new NegativeBalanceLimitExceededException($effectiveNegativeBalanceLimit, $account->available_balance, $amount);
        }

        $circuitCapacityLimit = $limits['circuit_capacity_limit'] ?? null;
        if ($circuitCapacityLimit !== null && $amount > $circuitCapacityLimit) {
            throw new CircuitCapacityExceededException((int) $circuitCapacityLimit, $amount);
        }

        // Limite singolo trasferimento: configurazione utente → account → fido → default globale sicuro.
        // Il default globale (200.000 centesimi = 2.000 KY) previene svuotamenti accidentali
        // su conti senza limiti espliciti configurati dall'admin.
        // Viene letto da SystemSetting.default_per_movement_limit, con hard fallback a 200.000.
        $systemDefaultLimit = \App\Models\SystemSetting::userLimitDefaults()->default_per_movement_limit ?? 200000;
        $singleTransferLimit = $limits['per_movement_limit']
            ?? $account->spending_limit
            ?? $creditLimit?->single_transfer_limit
            ?? (int) $systemDefaultLimit;
        if ($amount > $singleTransferLimit) {
            throw new SingleTransferLimitExceededException((int) $singleTransferLimit, $amount);
        }

        $dailyLimit = $limits['daily_transaction_limit'] ?? $account->daily_outgoing_limit ?? $creditLimit?->daily_outgoing_limit;
        if ($dailyLimit !== null) {
            $startOfDay = CarbonImmutable::now()->startOfDay();
            $endOfDay   = CarbonImmutable::now()->endOfDay();

            // Conteggio per from_account_id: il limite è del conto, non dell'utente che lo opera.
            // Un'azienda con più gestori non può moltiplicare il limite giornaliero.
            // Nessuna cache: il pattern Cache::remember(60)+forget() annullava il beneficio
            // e in burst concorrenti poteva far sforare il limite.
            $outgoingToday = Transfer::query()
                ->where('from_account_id', $account->id)
                ->where('status', 'booked')
                ->whereBetween('booked_at', [$startOfDay, $endOfDay])
                ->sum('amount');

            if (($outgoingToday + $amount) > $dailyLimit) {
                throw new DailyLimitExceededException((int) $dailyLimit, (int) $outgoingToday, $amount);
            }
        }

        $monthlyLimit = $limits['monthly_transaction_limit'] ?? null;
        if ($monthlyLimit !== null) {
            $startOfMonth = CarbonImmutable::now()->startOfMonth();
            $endOfMonth   = CarbonImmutable::now()->endOfMonth();

            $outgoingMonth = Transfer::query()
                ->where('from_account_id', $account->id)
                ->where('status', 'booked')
                ->whereBetween('booked_at', [$startOfMonth, $endOfMonth])
                ->sum('amount');

            if (($outgoingMonth + $amount) > $monthlyLimit) {
                throw new MonthlyLimitExceededException((int) $monthlyLimit, (int) $outgoingMonth, $amount);
            }
        }
    }
    /**
     * Registra la commissione (portal_fee) contestualmente al pagamento principale.
     *
     * ⚠️  COLLO DI BOTTIGLIA A VOLUME ELEVATO — LEGGERE PRIMA DI MODIFICARE
     * ─────────────────────────────────────────────────────────────────────────
     * Ogni chiamata a bookFee() esegue lockForUpdate() sul conto sistema (Cassa
     * Circuito KMoney). Con pochi pagamenti concorrenti questo non è un problema,
     * ma al crescere dei volumi tutti i thread si accodan su quella singola riga,
     * serializzando di fatto tutti i pagamenti.
     *
     * Stesso problema esiste in CashbackService::applyIfEligible().
     *
     * SOLUZIONE CONSIGLIATA (da attivare quando i pagamenti/minuto superano ~50):
     *
     *   1. Creare una tabella `pending_system_credits` con le colonne:
     *      (transfer_id, amount, kind, created_at)
     *
     *   2. Sostituire bookFee() e applyIfEligible() con un semplice INSERT
     *      in quella tabella (nessun lock, nessun aggiornamento saldo).
     *
     *   3. Creare un job `FlushSystemCredits` (schedulato ogni 10-30 secondi)
     *      che aggrega le righe pendenti con una singola transazione:
     *        - lockForUpdate() sul conto sistema UNA sola volta
     *        - UPDATE saldo += SUM(amount) in batch
     *        - Inserisce i Transfer e LedgerEntry aggregati
     *        - Elimina le righe processate
     *
     *   Impatto: il lock sul conto sistema scende da N volte/secondo a 1 volta
     *   ogni 10-30 secondi, eliminando la serializzazione.
     *
     *   Il saldo del conto sistema risulterà "in ritardo" di max 30 secondi,
     *   accettabile per fee interne non visibili all'utente.
     * ─────────────────────────────────────────────────────────────────────────
     */
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

        // NOTA sulla transazione annidata: questo metodo viene chiamato dall'interno di
        // bookSettledTransfer() che è già dentro DB::transaction(). Laravel gestisce le
        // transazioni annidate tramite savepoint (InnoDB), quindi:
        //   - se bookFee() lancia eccezione → solo il savepoint interno viene annullato
        //   - la transazione esterna (e il transfer principale) rimane valida
        // Questo è il comportamento voluto: la fee è best-effort e non deve bloccare il pagamento.
        \DB::transaction(function () use ($fromAccount, $systemAccount, $fee, $kind, $parentTransfer, $idempotencyKey) {
            // Ricarica con lock per aggiornare i saldi in sicurezza
            $payer  = \App\Models\Account::lockForUpdate()->findOrFail($fromAccount->id);
            $system = \App\Models\Account::lockForUpdate()->findOrFail($systemAccount->id);

            $bookedAt          = \Carbon\CarbonImmutable::now();
            $debitBalanceAfter  = $payer->available_balance - $fee;
            $creditBalanceAfter = $system->available_balance + $fee;

            $payer->forceFill(['available_balance'  => $debitBalanceAfter])->save();
            $system->forceFill(['available_balance' => $creditBalanceAfter])->save();

            $transfer = \App\Models\Transfer::create([
                'from_account_id'     => $payer->id,
                'to_account_id'       => $system->id,
                'amount'              => $fee,
                'currency_code'       => $payer->currency_code ?? 'KY',
                'kind'                => 'portal_fee',
                'status'              => 'booked',
                'description'        => 'Commissione su ' . $kind,
                'idempotency_key'    => $idempotencyKey,
                'booked_at'          => $bookedAt,
                'related_transfer_id' => $parentTransfer->id,
            ]);

            \App\Models\LedgerEntry::create([
                'transfer_id'   => $transfer->id,
                'account_id'    => $payer->id,
                'direction'     => 'debit',
                'amount'        => $fee,
                'balance_after' => $debitBalanceAfter,
                'posted_at'     => $bookedAt,
                'meta'          => ['fee_for_transfer_id' => $parentTransfer->id],
            ]);

            \App\Models\LedgerEntry::create([
                'transfer_id'   => $transfer->id,
                'account_id'    => $system->id,
                'direction'     => 'credit',
                'amount'        => $fee,
                'balance_after' => $creditBalanceAfter,
                'posted_at'     => $bookedAt,
                'meta'          => ['fee_for_transfer_id' => $parentTransfer->id],
            ]);
        });
    }

    /**
     * Invia notifica al titolare del conto padre quando un sottoconto effettua un pagamento.
     */
    private function notifyParentOfSubAccountTransfer(
        Transfer $transfer,
        Account  $subAccount,
        Account  $parentAccount,
        User     $initiator,
    ): void {
        // Recupera il proprietario del conto padre
        $ownerUser = $parentAccount->ownerUser;

        if ($ownerUser === null && $parentAccount->company_id !== null) {
            // Fallback: proprietario del conto principale (root) dell'azienda;
            // in mancanza, il primo utente collegato all'azienda.
            // (Prima: $company->owner, relazione inesistente => sempre null.)
            $company   = $parentAccount->company;
            $ownerUser = $company?->accounts()
                    ->whereNull('parent_account_id')
                    ->first()?->ownerUser
                ?? $company?->users()->first();
        }

        if ($ownerUser === null || $ownerUser->id === $initiator->id) {
            return;
        }

        $ownerUser->notify(new \App\Notifications\SubAccountTransferNotification(
            transfer: $transfer,
            subAccount: $subAccount,
            parentAccount: $parentAccount,
            initiator: $initiator,
        ));
    }
}
