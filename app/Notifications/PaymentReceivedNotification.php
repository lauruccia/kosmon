<?php

namespace App\Notifications;

use App\Notifications\Concerns\RespectsNotificationPreferences;

use App\Models\Account;
use App\Models\Transfer;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\BroadcastMessage;

class PaymentReceivedNotification extends Notification
{
    use RespectsNotificationPreferences;

    public function __construct(
        public readonly Transfer $transfer,
        public readonly Account  $fromAccount,
        public readonly Account  $toAccount,
    ) {}

    public function via(object $notifiable): array
    {
        return $this->resolveChannels($notifiable, 'payment_received', ['database', 'broadcast'], ['database']);
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

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage([
            'type'             => 'payment_received',
            'icon'             => '💸',
            'title'            => 'Pagamento ricevuto',
            'body'             => sprintf(
                'Hai ricevuto %s KY da %s.',
                ky_format($this->transfer->amount),
                $this->fromAccount->display_name,
            ),
            'amount'           => $this->transfer->amount,
            'amount_formatted' => ky_format($this->transfer->amount),
            'from_name'        => $this->fromAccount->display_name,
            'new_balance'      => $this->toAccount->available_balance,
            'link'             => route('portal.movements'),
        ]);
    }
}
