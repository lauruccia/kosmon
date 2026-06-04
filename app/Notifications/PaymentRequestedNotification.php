<?php

namespace App\Notifications;

use App\Models\Account;
use App\Models\Transfer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class PaymentRequestedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Transfer $transfer,
        public readonly Account  $fromAccount,
        public readonly Account  $toAccount,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'icon'  => '📩',
            'title' => 'Richiesta di pagamento ricevuta',
            'body'  => sprintf(
                '%s ti ha richiesto %s KY. Conferma o rifiuta dai movimenti.',
                $this->toAccount->display_name,
                ky_format($this->transfer->amount),
            ),
            'link'  => route('portal.movements'),
        ];
    }
}
