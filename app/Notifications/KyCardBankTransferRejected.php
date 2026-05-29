<?php

namespace App\Notifications;

use App\Models\KyCardPurchase;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class KyCardBankTransferRejected extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly KyCardPurchase $purchase) {}

    public function via(object $notifiable): array { return ['mail', 'database']; }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Bonifico KYCard non confermato — ' . ($this->purchase->kyCard->name ?? ''))
            ->greeting('Ciao ' . $notifiable->name . '!')
            ->line('Il bonifico per la tua KYCard non è stato confermato.')
            ->line('**Causale:** ' . $this->purchase->bank_transfer_reference)
            ->when($this->purchase->admin_notes, fn($m) => $m->line('**Motivo:** ' . $this->purchase->admin_notes))
            ->line('Se hai già effettuato il pagamento contatta il supporto allegando la ricevuta.')
            ->action('Torna al portale', url('/ricarica'));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'  => 'kycard_bank_rejected',
            'title' => 'Bonifico KYCard non confermato',
            'body'  => 'Il bonifico per "' . ($this->purchase->kyCard->name ?? '—') . '" è stato rifiutato.',
            'url'   => '/ricarica',
        ];
    }
}
