<?php

namespace App\Notifications;

use App\Models\KyCardPurchase;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class KyCardCredited extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly KyCardPurchase $purchase) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Ricarica KMoney completata — +' . number_format($this->purchase->ky_amount, 0, ',', '.') . ' KY')
            ->greeting('Ciao ' . $notifiable->name . '!')
            ->line('La tua ricarica KMoney è andata a buon fine.')
            ->line('**Card acquistata:** ' . ($this->purchase->kyCard->name ?? '—'))
            ->line('**Pagato:** € ' . number_format($this->purchase->price_eur, 2, ',', '.'))
            ->line('**KY accreditati:** +' . number_format($this->purchase->ky_amount, 0, ',', '.') . ' KY')
            ->action('Vai al tuo conto', url('/'))
            ->line('I KY sono già disponibili sul tuo conto KMoney.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'       => 'kycard_credited',
            'title'      => '+' . number_format($this->purchase->ky_amount, 0, ',', '.') . ' KY accreditati',
            'body'       => 'Ricarica KYCard "' . ($this->purchase->kyCard->name ?? '—') . '" completata.',
            'url'        => '/movimenti',
            'amount'     => $this->purchase->ky_amount,
            'purchase_id' => $this->purchase->uuid,
        ];
    }
}
