<?php

namespace App\Jobs;

use App\Events\PaymentRequestUpdated;
use App\Models\PaymentRequest;
use App\Models\User;
use App\Notifications\PaymentRequestExpiredForCreditorNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Segna come "expired" tutte le PaymentRequest pending la cui scadenza e' passata.
 * Schedulato ogni minuto.
 */
class ExpirePaymentRequests implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        // Recupera le richieste da scadere per poter fare broadcast
        $expiring = PaymentRequest::query()
            ->where('status', 'pending')
            ->where('expires_at', '<=', now())
            ->with(['toAccount.company', 'toAccount.ownerUser', 'fromAccount'])
            ->get();

        foreach ($expiring as $pr) {
            $pr->update(['status' => 'expired']);

            // Notifica real-time al merchant (aggiorna UI senza polling)
            broadcast(new PaymentRequestUpdated($pr));

            // Notifica il creditore (to_account owner) che la richiesta è scaduta
            $toAccount = $pr->toAccount;
            if ($toAccount) {
                $creditor = $toAccount->ownerUser
                    ?? User::where('company_id', $toAccount->company_id)
                           ->where('account_holder_type', 'owner')
                           ->first();
                $creditor?->notify(new PaymentRequestExpiredForCreditorNotification($pr));
            }
        }
    }
}
