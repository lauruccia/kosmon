<?php

namespace App\Notifications;

use App\Notifications\Concerns\RespectsNotificationPreferences;

use App\Models\ScheduledPayment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class ScheduledPaymentExecutedNotification extends Notification implements ShouldQueue
{
    use RespectsNotificationPreferences;

    use Queueable;

    public function __construct(
        public readonly ScheduledPayment $payment,
        public readonly bool $isRecipient = false,
    ) {}

    public function via(object $notifiable): array
    {
        return $this->resolveChannels($notifiable, 'scheduled_payment', ['database', 'mail'], ['database', 'mail']);
    }

    public function toArray(object $notifiable): array
    {
        if ($this->isRecipient) {
            $senderName = $this->payment->fromAccount?->company?->name
                ?? $this->payment->fromAccount?->display_name
                ?? 'Un\'azienda';
            return [
                'icon'  => '💸',
                'title' => 'Pagamento programmato ricevuto',
                'body'  => 'Hai ricevuto ' . $this->payment->formattedAmount() . ' da ' . $senderName . ' (pagamento programmato).',
                'link'  => route('portal.movements'),
            ];
        }

        return [
            'icon'  => '✅',
            'title' => 'Pagamento programmato eseguito',
            'body'  => 'Il tuo pagamento di ' . $this->payment->formattedAmount() . ' è stato eseguito automaticamente.',
            'link'  => route('portal.scheduled-payments.show', $this->payment),
        ];
    }
}
