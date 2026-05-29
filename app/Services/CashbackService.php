<?php

namespace App\Services;

use App\Models\Account;
use App\Models\CashbackRule;
use App\Models\Transfer;
use App\Notifications\CashbackReceivedNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CashbackService
{
    public function __construct(
        private readonly TransferBookingService $booking,
    ) {}

    /**
     * Valuta e applica il cashback per un transfer appena completato.
     * Chiamato dopo ogni booking andato a buon fine.
     * Se nessuna regola si applica, non fa nulla.
     */
    public function applyIfEligible(Transfer $transfer): void
    {
        // Solo trasferimenti booked
        if ($transfer->status !== 'booked') {
            return;
        }

        // Non applicare cashback su trasferimenti già cashback (evita loop)
        if ($transfer->kind === 'portal_cashback') {
            return;
        }

        // Il cashback va al pagante (from_account) — necessario per il targeting
        $beneficiary = Account::find($transfer->from_account_id);
        if (! $beneficiary) {
            return;
        }

        // Carica relazioni utili al targeting
        $beneficiary->loadMissing('ownerUser');

        $rules = CashbackRule::where('is_active', true)->get();

        $bestCashback = 0;
        foreach ($rules as $rule) {
            // Verifica targeting (azienda / privato / utente specifico)
            if (! $rule->appliesTo($beneficiary)) {
                continue;
            }
            $amount = $rule->calculateCashback($transfer->amount, $transfer->kind ?? '');
            $bestCashback = max($bestCashback, $amount);
        }

        if ($bestCashback <= 0) {
            return;
        }

        // Conto di sistema come mittente del cashback
        $systemAccount = Account::systemAccount();
        if (! $systemAccount) {
            Log::warning('CashbackService: nessun conto di sistema trovato, cashback non erogato.');
            return;
        }

        // Verifica che il conto di sistema abbia saldo sufficiente
        if ($systemAccount->available_balance < $bestCashback) {
            Log::warning('CashbackService: saldo conto sistema insufficiente per cashback.', [
                'needed'    => $bestCashback,
                'available' => $systemAccount->available_balance,
            ]);
            return;
        }

        try {
            // Usa il proprietario del conto sistema come initiator
            $systemUser = $systemAccount->ownerUser
                ?? $systemAccount->company?->users()->orderBy('id')->first();

            if (! $systemUser) {
                Log::warning('CashbackService: nessun utente associato al conto sistema.');
                return;
            }

            $cashbackTransfer = $this->booking->book([
                'initiated_by'    => $systemUser->id,
                'from_account_id' => $systemAccount->id,
                'to_account_id'   => $beneficiary->id,
                'amount'          => $bestCashback,
                'description'     => 'Cashback su pagamento #' . strtoupper(substr($transfer->uuid ?? (string) $transfer->id, 0, 8)),
                'kind'            => 'portal_cashback',
                'idempotency_key' => 'cashback_' . $transfer->uuid,
            ]);

            // Notifica al beneficiario
            $owner = $beneficiary->ownerUser;
            if ($owner) {
                $owner->notify(new CashbackReceivedNotification($cashbackTransfer, $bestCashback));
            }
        } catch (\Throwable $e) {
            // Il cashback fallisce silenziosamente — non blocca il pagamento principale
            Log::error('CashbackService: errore erogazione cashback', [
                'transfer_id' => $transfer->id,
                'error'       => $e->getMessage(),
            ]);
        }
    }
}
