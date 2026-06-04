<?php

namespace App\Notifications;

use App\Models\Account;
use App\Models\Transfer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class PaymentRequestConfirmedNotification extends Notification implements ShouldQueue
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
            'icon'  => '✅',
            'title' => 'Richiesta di pagamento confermata',
            'body'  => sprintf(
                '%s ha confermato il pagamento di %s KY.',
                $this->fromAccount->display_name,
                ky_format($this->transfer->amount),
            ),
            'link'  => route('portal.movements'),
        ];
    }
}
