<?php

namespace App\Jobs;

use App\Models\PaymentRequest;
use App\Models\User;
use App\Notifications\PaymentRequestExpiringNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Invia promemoria di scadenza al DEBITORE (from_account owner):
 *   - 24h prima della scadenza (una sola volta)
 *   - 1h prima della scadenza (una sola volta)
 *
 * Schedulato ogni 5 minuti.
 */
class RemindPaymentRequests implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        $now = now();

        // ── Promemoria 24h ────────────────────────────────────────────────────
        $window24Start = $now->copy()->addHours(23)->addMinutes(55);
        $window24End   = $now->copy()->addHours(24)->addMinutes(5);

        PaymentRequest::query()
            ->where('status', 'pending')
            ->whereNull('reminder_24h_sent_at')
            ->whereBetween('expires_at', [$window24Start, $window24End])
            ->with(['fromAccount.company', 'fromAccount.ownerUser', 'toAccount'])
            ->get()
            ->each(function (PaymentRequest $pr): void {
                $this->notifyDebtor($pr, '24h');
                $pr->update(['reminder_24h_sent_at' => now()]);
            });

        // ── Promemoria 1h ─────────────────────────────────────────────────────
        $window1Start = $now->copy()->addMinutes(55);
        $window1End   = $now->copy()->addMinutes(65);

        PaymentRequest::query()
            ->where('status', 'pending')
            ->whereNull('reminder_1h_sent_at')
            ->whereBetween('expires_at', [$window1Start, $window1End])
            ->with(['fromAccount.company', 'fromAccount.ownerUser', 'toAccount'])
            ->get()
            ->each(function (PaymentRequest $pr): void {
                $this->notifyDebtor($pr, '1h');
                $pr->update(['reminder_1h_sent_at' => now()]);
            });
    }

    private function notifyDebtor(PaymentRequest $pr, string $window): void
    {
        $fromAccount = $pr->fromAccount;
        if (! $fromAccount) {
            return;
        }

        // Notifica il proprietario del conto debitore
        $user = $fromAccount->ownerUser
            ?? User::where('company_id', $fromAccount->company_id)
                   ->where('account_holder_type', 'owner')
                   ->first();

        $user?->notify(new PaymentRequestExpiringNotification($pr, $window));
    }
}
