<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\NettingProposal;
use App\Models\Transfer;
use App\Models\User;
use App\Notifications\NettingAcceptedNotification;
use App\Notifications\NettingProposedNotification;
use App\Notifications\NettingRejectedNotification;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class NettingService
{
    // No external booking service needed — we create transfers directly
    // like PaymentPlanService does, to bypass user authorization checks
    // (the net transfer is system-initiated on behalf of both parties).

    /**
     * Propone una compensazione crediti incrociati.
     *
     * @param  int    $proposerAccountId    Conto del proponente
     * @param  int    $counterpartyAccountId Conto della controparte
     * @param  int[]  $proposerTransferIds   Transfer IDs (crediti del proposer verso counterparty)
     * @param  int[]  $counterpartyTransferIds Transfer IDs (crediti del counterparty verso proposer)
     * @param  int    $proposedBy           Utente che propone
     * @param  string|null $description
     * @param  string|null $ipAddress
     */
    public function propose(
        int    $proposerAccountId,
        int    $counterpartyAccountId,
        array  $proposerTransferIds,
        array  $counterpartyTransferIds,
        int    $proposedBy,
        ?string $description = null,
        ?string $ipAddress = null,
    ): NettingProposal {
        return DB::transaction(function () use (
            $proposerAccountId, $counterpartyAccountId,
            $proposerTransferIds, $counterpartyTransferIds,
            $proposedBy, $description, $ipAddress,
        ) {
            $proposer      = Account::lockForUpdate()->findOrFail($proposerAccountId);
            $counterparty  = Account::lockForUpdate()->findOrFail($counterpartyAccountId);
            $proposerUser  = User::findOrFail($proposedBy);

            if ($proposer->id === $counterparty->id) {
                throw new RuntimeException('Non puoi compensare con te stesso.');
            }

            if (empty($proposerTransferIds) && empty($counterpartyTransferIds)) {
                throw new RuntimeException('Seleziona almeno un trasferimento da compensare.');
            }

            // Valida trasferimenti lato proposer: devono essere incassi in sospeso verso il proposer
            $proposerTransfers = $this->validateNettingTransfers(
                $proposerTransferIds,
                toAccountId: $proposerAccountId,
                fromAccountId: $counterpartyAccountId,
            );

            // Valida trasferimenti lato counterparty: devono essere incassi in sospeso verso il counterparty
            $counterpartyTransfers = $this->validateNettingTransfers(
                $counterpartyTransferIds,
                toAccountId: $counterpartyAccountId,
                fromAccountId: $proposerAccountId,
            );

            $proposerTotal      = $proposerTransfers->sum('amount');
            $counterpartyTotal  = $counterpartyTransfers->sum('amount');

            $netAmount  = abs($proposerTotal - $counterpartyTotal);
            $netPayerId = null;

            if ($netAmount > 0) {
                $netPayerId = $proposerTotal < $counterpartyTotal
                    ? $proposerAccountId
                    : $counterpartyAccountId;
            }

            $proposal = NettingProposal::create([
                'proposer_account_id'       => $proposerAccountId,
                'counterparty_account_id'   => $counterpartyAccountId,
                'proposer_transfer_ids'     => $proposerTransferIds,
                'counterparty_transfer_ids' => $counterpartyTransferIds,
                'proposer_total'            => $proposerTotal,
                'counterparty_total'        => $counterpartyTotal,
                'currency_code'             => $proposer->currency_code ?? 'KY',
                'net_payer_account_id'      => $netPayerId,
                'net_amount'               => $netAmount,
                'description'              => $description,
                'status'                   => 'pending',
                'proposed_by'              => $proposedBy,
                'expires_at'               => now()->addDays(7),
            ]);

            AuditLog::create([
                'actor_user_id'  => $proposedBy,
                'event'          => 'netting.proposed',
                'auditable_type' => NettingProposal::class,
                'auditable_id'   => $proposal->id,
                'ip_address'     => $ipAddress,
                'context'        => [
                    'netting_proposal_id'     => $proposal->id,
                    'counterparty_account_id' => $counterpartyAccountId,
                    'proposer_total'          => $proposerTotal,
                    'counterparty_total'      => $counterpartyTotal,
                    'net_amount'              => $netAmount,
                ],
            ]);

            // Notifica alla controparte
            $counterpartyOwner = $counterparty->ownerUser ?? $counterparty->company?->users()->first();
            if ($counterpartyOwner) {
                $counterpartyOwner->notify(new NettingProposedNotification($proposal));
            }

            return $proposal;
        });
    }

    /**
     * Accetta la proposta di compensazione.
     * Cancella i trasferimenti in sospeso e genera un unico trasferimento netto (se net_amount > 0).
     */
    public function accept(
        NettingProposal $proposal,
        int             $actionedBy,
        ?string         $ipAddress = null,
    ): NettingProposal {
        return DB::transaction(function () use ($proposal, $actionedBy, $ipAddress) {
            // Ricarica con lock
            $proposal = NettingProposal::lockForUpdate()->findOrFail($proposal->id);
            $proposal->load(['proposerAccount', 'counterpartyAccount']);

            if (! $proposal->isPending()) {
                throw new RuntimeException('La proposta non è più in attesa.');
            }

            if ($proposal->isExpired()) {
                $proposal->update(['status' => 'expired']);
                throw new RuntimeException('La proposta è scaduta.');
            }

            $actionUser = User::findOrFail($actionedBy);

            // Cancella tutti i trasferimenti in sospeso inclusi nella compensazione
            $allTransferIds = array_merge(
                $proposal->proposer_transfer_ids ?? [],
                $proposal->counterparty_transfer_ids ?? [],
            );

            if (! empty($allTransferIds)) {
                Transfer::whereIn('id', $allTransferIds)
                        ->where('status', 'pending')
                        ->update([
                            'status'     => 'cancelled',
                            'updated_at' => now(),
                        ]);
            }

            $netTransferId = null;

            // Se c'è un saldo netto, genera un unico trasferimento
            if ($proposal->net_amount > 0 && $proposal->net_payer_account_id) {
                $netPayerAccount    = Account::findOrFail($proposal->net_payer_account_id);
                $netReceiverAccount = $proposal->net_payer_account_id === $proposal->proposer_account_id
                    ? $proposal->counterpartyAccount
                    : $proposal->proposerAccount;

                // Verifica saldo
                if (
                    ! $netPayerAccount->allow_negative_balance &&
                    $netPayerAccount->available_balance < $proposal->net_amount
                ) {
                    throw new RuntimeException(
                        'Saldo insufficiente per il pagamento netto di ' .
                        ky_format($proposal->net_amount) . ' KY.'
                    );
                }

                $bookedAt           = \Carbon\CarbonImmutable::now();
                $debitBalanceAfter  = $netPayerAccount->available_balance - $proposal->net_amount;
                $creditBalanceAfter = $netReceiverAccount->available_balance + $proposal->net_amount;

                $netPayerAccount->forceFill(['available_balance'   => $debitBalanceAfter])->save();
                $netReceiverAccount->forceFill(['available_balance' => $creditBalanceAfter])->save();

                $netTransfer = \App\Models\Transfer::create([
                    'initiated_by'    => $actionedBy,
                    'from_account_id' => $netPayerAccount->id,
                    'to_account_id'   => $netReceiverAccount->id,
                    'amount'          => $proposal->net_amount,
                    'currency_code'   => $proposal->currency_code,
                    'status'          => 'booked',
                    'kind'            => 'portal_netting',
                    'idempotency_key' => 'netting-' . $proposal->uuid,
                    'description'     => 'Saldo netto compensazione #' . $proposal->id .
                                        ($proposal->description ? ' — ' . $proposal->description : ''),
                    'booked_at'       => $bookedAt,
                ]);

                \App\Models\LedgerEntry::create([
                    'transfer_id'   => $netTransfer->id,
                    'account_id'    => $netPayerAccount->id,
                    'direction'     => 'debit',
                    'amount'        => $proposal->net_amount,
                    'balance_after' => $debitBalanceAfter,
                    'posted_at'     => $bookedAt,
                    'meta'          => ['netting_proposal_id' => $proposal->id, 'counterparty_account_id' => $netReceiverAccount->id],
                ]);

                \App\Models\LedgerEntry::create([
                    'transfer_id'   => $netTransfer->id,
                    'account_id'    => $netReceiverAccount->id,
                    'direction'     => 'credit',
                    'amount'        => $proposal->net_amount,
                    'balance_after' => $creditBalanceAfter,
                    'posted_at'     => $bookedAt,
                    'meta'          => ['netting_proposal_id' => $proposal->id, 'counterparty_account_id' => $netPayerAccount->id],
                ]);

                $netTransferId = $netTransfer->id;
            }

            $proposal->update([
                'status'          => 'accepted',
                'net_transfer_id' => $netTransferId,
                'actioned_by'     => $actionedBy,
                'actioned_at'     => now(),
            ]);

            AuditLog::create([
                'actor_user_id'  => $actionedBy,
                'event'          => 'netting.accepted',
                'auditable_type' => NettingProposal::class,
                'auditable_id'   => $proposal->id,
                'ip_address'     => $ipAddress,
                'context'        => [
                    'netting_proposal_id' => $proposal->id,
                    'net_transfer_id'     => $netTransferId,
                    'net_amount'          => $proposal->net_amount,
                ],
            ]);

            // Notifica al proposer
            $proposerOwner = $proposal->proposerAccount->ownerUser ??
                             $proposal->proposerAccount->company?->users()->first();
            if ($proposerOwner) {
                $proposerOwner->notify(new NettingAcceptedNotification($proposal));
            }

            return $proposal->fresh(['proposerAccount', 'counterpartyAccount', 'netTransfer']);
        });
    }

    /**
     * Rifiuta la proposta — i trasferimenti originali restano pending.
     */
    public function reject(
        NettingProposal $proposal,
        int             $actionedBy,
        ?string         $ipAddress = null,
    ): NettingProposal {
        return DB::transaction(function () use ($proposal, $actionedBy, $ipAddress) {
            $proposal = NettingProposal::lockForUpdate()->findOrFail($proposal->id);
            $proposal->load(['proposerAccount']);

            if (! $proposal->isPending()) {
                throw new RuntimeException('La proposta non è più in attesa.');
            }

            $proposal->update([
                'status'      => 'rejected',
                'actioned_by' => $actionedBy,
                'actioned_at' => now(),
            ]);

            AuditLog::create([
                'actor_user_id'  => $actionedBy,
                'event'          => 'netting.rejected',
                'auditable_type' => NettingProposal::class,
                'auditable_id'   => $proposal->id,
                'ip_address'     => $ipAddress,
                'context'        => ['netting_proposal_id' => $proposal->id],
            ]);

            // Notifica al proposer
            $proposerOwner = $proposal->proposerAccount->ownerUser ??
                             $proposal->proposerAccount->company?->users()->first();
            if ($proposerOwner) {
                $proposerOwner->notify(new NettingRejectedNotification($proposal));
            }

            return $proposal;
        });
    }

    /**
     * Carica i trasferimenti pending tra due account per costruire la UI di proposta.
     * Restituisce [proposerCredits, counterpartyCredits].
     */
    public function getMutualPendingTransfers(
        Account $proposerAccount,
        Account $counterpartyAccount,
    ): array {
        // Crediti del proposer: richieste di pagamento che il counterparty deve al proposer
        $proposerCredits = Transfer::query()
            ->where('to_account_id', $proposerAccount->id)
            ->where('from_account_id', $counterpartyAccount->id)
            ->where('status', 'pending')
            ->whereIn('kind', ['portal_collection_request', 'portal_payment'])
            ->orderBy('created_at')
            ->get();

        // Crediti del counterparty: richieste di pagamento che il proposer deve al counterparty
        $counterpartyCredits = Transfer::query()
            ->where('to_account_id', $counterpartyAccount->id)
            ->where('from_account_id', $proposerAccount->id)
            ->where('status', 'pending')
            ->whereIn('kind', ['portal_collection_request', 'portal_payment'])
            ->orderBy('created_at')
            ->get();

        return [$proposerCredits, $counterpartyCredits];
    }

    // ── Private helpers ────────────────────────────────────────────────────────

    private function validateNettingTransfers(
        array $ids,
        int   $toAccountId,
        int   $fromAccountId,
    ): \Illuminate\Database\Eloquent\Collection {
        if (empty($ids)) {
            return Transfer::whereIn('id', [])->get();
        }

        $transfers = Transfer::whereIn('id', $ids)
                             ->where('status', 'pending')
                             ->whereIn('kind', ['portal_collection_request', 'portal_payment'])
                             ->where('to_account_id', $toAccountId)
                             ->where('from_account_id', $fromAccountId)
                             ->get();

        if ($transfers->count() !== count($ids)) {
            throw new RuntimeException(
                'Alcuni trasferimenti selezionati non sono validi o non sono più in attesa.'
            );
        }

        return $transfers;
    }
}