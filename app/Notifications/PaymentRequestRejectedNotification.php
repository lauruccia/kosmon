<?php

namespace App\Notifications;

use App\Models\Account;
use App\Models\Transfer;
use Illuminate\Notifications\Notification;

class PaymentRequestRejectedNotification extends Notification
{
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
                ky_format($this->transfer->amount),
            ),
            'link'  => route('portal.movements'),
        ];
    }
}
