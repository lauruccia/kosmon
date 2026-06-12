<?php

namespace App\Notifications;

use App\Models\ScheduledPayment;
use App\Notifications\Concerns\RespectsNotificationPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ScheduledPaymentExecutedNotification extends Notification implements ShouldQueue
{
    use Queueable;
    use RespectsNotificationPreferences;

    public function __construct(
        public readonly ScheduledPayment $payment,
        public readonly bool $isRecipient = false,
    ) {}

    public function via(object $notifiable): array
    {
        return $this->resolveChannels($notifiable, 'scheduled_payment', ['database', 'mail'], ['database', 'mail']);
    }

    public function toMail(object $notifiable): MailMessage
    {
        if ($this->isRecipient) {
            $senderName = $this->payment->fromAccount?->company?->name
                ?? $this->payment->fromAccount?->display_name
                ?? 'Un\'azienda';

            return (new MailMessage)
                ->subject('Pagamento programmato ricevuto - KMoney')
                ->greeting('Ciao ' . $notifiable->name . ',')
                ->line('Hai ricevuto ' . $this->payment->formattedAmount() . ' da ' . $senderName . ' tramite un pagamento programmato.')
                ->action('Vedi i movimenti', route('portal.movements'))
                ->salutation('Il team KMoney');
        }

        $recipientName = $this->payment->toAccount?->company?->name
            ?? $this->payment->toAccount?->display_name
            ?? 'il destinatario';

        return (new MailMessage)
            ->subject('Pagamento programmato eseguito - KMoney')
            ->greeting('Ciao ' . $notifiable->name . ',')
            ->line('Il tuo pagamento programmato di ' . $this->payment->formattedAmount() . ' a ' . $recipientName . ' e\' stato eseguito.')
            ->action('Vedi i movimenti', route('portal.movements'))
            ->salutation('Il team KMoney');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title'      => $this->isRecipient ? 'Pagamento programmato ricevuto' : 'Pagamento programmato eseguito',
            'body'       => $this->isRecipient
                ? 'Hai ricevuto ' . $this->payment->formattedAmount() . ' tramite pagamento programmato.'
                : 'Il tuo pagamento programmato di ' . $this->payment->formattedAmount() . ' e\' stato eseguito.',
            'url'        => route('portal.movements'),
            'type'       => 'scheduled_payment_executed',
            'payment_id' => $this->payment->id,
        ];
    }
}
