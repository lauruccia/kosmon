<?php

namespace App\Services;

use App\Models\KyCardPurchase;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Accredito dei KY di una KYCard acquistata (emissione dal conto madre verso
 * il conto del cliente), con punti MLM e notifica.
 *
 * Estratto da KyCardController::creditKy() il 22/10/2026, SENZA cambiare la
 * logica, per poterlo usare anche dagli abbonamenti (rate e rinnovo mensile):
 * il controller ora delega qui. L'unica aggiunta e' il parametro opzionale
 * $mlmDepositCents: la base su cui calcolare i punti/commissioni MLM, quando
 * non coincide con quanto e' stato incassato (prima rata di un acquisto
 * rateale: i punti si assegnano sul prezzo intero della card).
 */
class KyCardCreditService
{
    public function credit(KyCardPurchase $purchase, ?int $mlmDepositCents = null): void
    {
        // (B) Guard idempotenza: se l'accredito e' gia' avvenuto non rifare nulla.
        if ($purchase->isCompleted() || $purchase->transfer_id) {
            // I KY ci sono gia'. I punti MLM pero' potrebbero mancare: se la
            // richiesta che ha creato il transfer si e' interrotta prima di
            // assegnarli, oggi non li recupererebbe piu' nessuno (l'admin che
            // rilancia l'accredito si fermava proprio qui). La chiamata e'
            // idempotente sulla sorgente, quindi non puo' creare doppioni.
            $this->awardMlmDepositPoints($purchase, $mlmDepositCents);

            return;
        }

        try {
            $systemAccount = \App\Models\Account::systemAccount();
            if (!$systemAccount) {
                Log::error('KyCard credit failed: system account not found', ['purchase' => $purchase->uuid]);
                $purchase->update(['status' => 'failed']);
                return;
            }

            $amount         = (int) $purchase->ky_amount;
            $idempotencyKey = 'kycard_' . $purchase->uuid;

            // Creazione diretta (bypass check stato azienda e limiti):
            // il cliente ha gia' pagato in euro, l'accredito KY e' dovuto.

            // Vero solo per la richiesta che ha DAVVERO registrato il transfer.
            // Nella corsa webhook Stripe + pagina success entrambe entrano qui:
            // il lock sul conto madre le mette in fila e la seconda trova il
            // transfer gia' scritto, ma il suo `return` esce dalla CLOSURE, non
            // dal metodo — l'esecuzione riprendeva sotto e arrivava lo stesso
            // ai punti MLM, assegnati cosi' DUE volte (qualifica gonfiata e
            // base commissionabile pagata due volte). Fino al 28/08 non poteva
            // succedere: il webhook rispondeva 419 e la strada era una sola.
            $accreditoCreatoQui = false;

            \Illuminate\Support\Facades\DB::transaction(function () use ($systemAccount, $purchase, $amount, $idempotencyKey, &$accreditoCreatoQui) {

                // (A) Lock pessimistico: blocco prima il conto madre — questo serializza
                // tutti gli accrediti KYCard concorrenti ed evita lost-update sul saldo KNM.
                $fromAccount = \App\Models\Account::whereKey($systemAccount->id)->lockForUpdate()->first();

                // (B) Se il transfer esiste gia' (es. corsa webhook Stripe + pagina success),
                // allinea lo stato senza riaccreditare.
                $existing = \App\Models\Transfer::where('idempotency_key', $idempotencyKey)->first();
                if ($existing) {
                    $purchase->update([
                        'status'       => 'completed',
                        'transfer_id'  => $existing->id,
                        'completed_at' => $existing->booked_at ?? $existing->created_at,
                    ]);
                    return;
                }

                $toAccount = \App\Models\Account::whereKey($purchase->account_id)->lockForUpdate()->first();

                $bookedAt           = \Carbon\CarbonImmutable::now();
                $debitBalanceAfter  = $fromAccount->available_balance - $amount;
                $creditBalanceAfter = $toAccount->available_balance + $amount;

                $fromAccount->forceFill(['available_balance' => $debitBalanceAfter])->save();
                $toAccount->forceFill(['available_balance'   => $creditBalanceAfter])->save();

                $superAdminId = User::where('is_super_admin', true)->value('id') ?? 1;

                $transfer = \App\Models\Transfer::create([
                    'initiated_by'    => $purchase->confirmed_by ?? $superAdminId,
                    'from_account_id' => $fromAccount->id,
                    'to_account_id'   => $toAccount->id,
                    'amount'          => $amount,
                    'currency_code'   => $fromAccount->currency_code ?? 'KY',
                    'status'          => 'booked',
                    'kind'            => 'kycard_topup',
                    'idempotency_key' => $idempotencyKey,
                    'description'     => 'Ricarica KYCard: ' . ($purchase->kyCard->name ?? 'Card #' . $purchase->ky_card_id),
                    'booked_at'       => $bookedAt,
                ]);

                $accreditoCreatoQui = true;

                \App\Models\LedgerEntry::create([
                    'transfer_id'  => $transfer->id,
                    'account_id'   => $fromAccount->id,
                    'direction'    => 'debit',
                    'amount'       => $amount,
                    'balance_after'=> $debitBalanceAfter,
                    'posted_at'    => $bookedAt,
                    'meta'         => ['counterparty_account_id' => $toAccount->id],
                ]);

                \App\Models\LedgerEntry::create([
                    'transfer_id'  => $transfer->id,
                    'account_id'   => $toAccount->id,
                    'direction'    => 'credit',
                    'amount'       => $amount,
                    'balance_after'=> $creditBalanceAfter,
                    'posted_at'    => $bookedAt,
                    'meta'         => ['counterparty_account_id' => $fromAccount->id],
                ]);

                $purchase->update([
                    'status'       => 'completed',
                    'transfer_id'  => $transfer->id,
                    'completed_at' => $bookedAt,
                ]);

                // (D) AuditLog dell'emissione dal conto madre.
                \App\Models\AuditLog::create([
                    'actor_user_id'  => $purchase->confirmed_by ?? $superAdminId,
                    'event'          => 'kycard.credited',
                    'auditable_type' => \App\Models\Transfer::class,
                    'auditable_id'   => $transfer->id,
                    'ip_address'     => request()->ip(),
                    'context'        => [
                        'purchase_uuid'   => $purchase->uuid,
                        'ky_card_id'      => $purchase->ky_card_id,
                        'from_account_id' => $fromAccount->id,
                        'to_account_id'   => $toAccount->id,
                        'amount'          => $amount,
                        'payment_method'  => $purchase->payment_method,
                    ],
                ]);
            });

            $purchase->refresh();

            if ($accreditoCreatoQui) {
                $this->awardMlmDepositPoints($purchase, $mlmDepositCents);
            }

            try {
                if ($purchase->isCompleted()) {
                    $purchase->user->notify(new \App\Notifications\KyCardCredited($purchase));
                }
            } catch (\Exception) {}

        } catch (\Exception $e) {
            Log::error('KyCard credit failed', ['purchase' => $purchase->uuid, 'error' => $e->getMessage()]);
            // (B) Non sovrascrivere uno stato 'completed' gia' raggiunto da una corsa concorrente.
            if (!optional($purchase->fresh())->isCompleted()) {
                $purchase->update(['status' => 'failed']);
            }
        }
    }

    /**
     * MLM: assegna i punti deposito al cliente/agente risolto (fascia EUR).
     *
     * Isolato in try/catch proprio: l'accredito KY e' gia' avvenuto e un
     * errore qui non deve intaccare la risposta all'utente. Saltato
     * interamente se MLM e' disattivato su questa installazione
     * (config('kmoney.mlm_enabled')).
     *
     * Chiamato da UN SOLO punto per ogni accredito riuscito — chi registra il
     * transfer — piu' il recupero sugli acquisti gia' completati. La difesa
     * contro il doppione non e' pero' qui: sta in MlmPointsService (controllo
     * sulla sorgente) e nell'indice UNIQUE del database. Tre livelli, perche'
     * questo e' il solo che una modifica futura del flusso di pagamento
     * potrebbe scavalcare senza accorgersene.
     */
    private function awardMlmDepositPoints(KyCardPurchase $purchase, ?int $mlmDepositCents = null): void
    {
        if (! config('kmoney.mlm_enabled') || ! $purchase->isCompleted()) {
            return;
        }

        try {
            app(\App\Services\MlmPointsService::class)->awardDepositPoints(
                $purchase->user,
                $mlmDepositCents ?? (int) $purchase->price_eur_cents,
                $purchase->transfer_id,
                $purchase->kyCard, // i punti sono definiti sulla card acquistata (22/07)
            );
        } catch (\Exception $mlmException) {
            Log::error('MLM points award failed', ['purchase' => $purchase->uuid, 'error' => $mlmException->getMessage()]);
        }
    }
}
