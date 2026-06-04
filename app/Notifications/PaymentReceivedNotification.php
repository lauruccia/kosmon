<?php

namespace App\Notifications;

use App\Notifications\Concerns\RespectsNotificationPreferences;

use App\Models\Account;
use App\Models\Transfer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class PaymentReceivedNotification extends Notification implements ShouldQueue
{
    use RespectsNotificationPreferences;

    use Queueable;

    public function __construct(
        public readonly Transfer $transfer,
        public readonly Account  $fromAccount,
        public readonly Account  $toAccount,
    ) {}

    public function via(object $notifiable): array
    {
        return $this->resolveChannels($notifiable, 'payment_received', ['database'], ['database']);
    }

    public function toArray(object $notifiable): array
    {
        return [
            'icon'  => '💸',
            'title' => 'Pagamento ricevuto',
            'body'  => sprintf(
                'Hai ricevuto %s KY da %s.',
                ky_format($this->transfer->amount),
                $this->fromAccount->display_name,
            ),
            'link'  => route('portal.movements'),
        ];
    }
}
