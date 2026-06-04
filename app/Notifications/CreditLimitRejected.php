<?php

namespace App\Notifications;

use App\Models\CreditLimitRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CreditLimitRejected extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly CreditLimitRequest $creditRequest) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $amount = ky_format($this->creditRequest->requested_amount);

        return (new MailMessage)
            ->subject('[KMoney] Richiesta fido non approvata')
            ->greeting('Aggiornamento sulla tua richiesta fido')
            ->line("La tua richiesta di fido per **{$amount} KY** non è stata approvata.")
            ->when($this->creditRequest->admin_note, fn($m) => $m->line("Motivazione: *{$this->creditRequest->admin_note}*"))
            ->action('Torna al tuo conto', route('portal.dashboard'))
            ->line('Puoi presentare una nuova richiesta in qualsiasi momento o contattare il tuo operatore di riferimento.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'    => 'credit_limit_rejected',
            'message' => "Richiesta fido rifiutata ({$this->creditRequest->requested_amount} KY)",
            'url'     => route('portal.fido'),
            'credit_limit_request_id' => $this->creditRequest->id,
        ];
    }
}
