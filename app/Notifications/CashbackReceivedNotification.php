<?php

namespace App\Notifications;

use App\Notifications\Concerns\RespectsNotificationPreferences;

use App\Models\Transfer;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CashbackReceivedNotification extends Notification
{
    use RespectsNotificationPreferences;

    use Queueable;

    public function __construct(
        public readonly Transfer $transfer,
        public readonly int $amount,
    ) {}

    public function via(object $notifiable): array
    {
        return $this->resolveChannels($notifiable, 'cashback_received', ['database', 'mail'], ['database', 'mail']);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $formatted = number_format($this->amount, 2, ',', '.');

        return (new MailMessage)
            ->subject("Hai ricevuto un cashback di {$formatted} KY!")
            ->greeting("Ciao {$notifiable->name},")
            ->line("Hai ricevuto un cashback di **{$formatted} KY** sul tuo conto.")
            ->line("Rif. movimento: {$this->transfer->reference}")
            ->line($this->transfer->description ?? 'Cashback circuito KMoney')
            ->action('Vai ai movimenti', url('/movimenti'))
            ->line('Il cashback e stato accreditato automaticamente sul tuo conto.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'        => 'cashback_received',
            'transfer_id' => $this->transfer->id,
            'reference'   => $this->transfer->reference,
            'amount'      => $this->amount,
            'description' => $this->transfer->description,
            'booked_at'   => $this->transfer->booked_at?->toIso8601String(),
        ];
    }
}
