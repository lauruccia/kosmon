<?php

namespace App\Notifications;

use App\Models\Account;
use App\Models\Transfer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class PaymentRequestRejectedNotification extends Notification implements ShouldQueue
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
            'icon'  => '❌',
            'title' => 'Richiesta di pagamento rifiutata',
            'body'  => sprintf(
                '%s ha rifiutato la tua richiesta di %s KY.',
                $this->fromAccount->display_name,
                number_format($this->transfer->amount, 2, ',', '.'),
            ),
            'link'  => route('portal.movements'),
        ];
    }
}
