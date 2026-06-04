<?php

namespace App\Notifications;

use App\Models\CreditLimitRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CreditLimitApproved extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly CreditLimitRequest $creditRequest) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $amount = ky_format($this->creditRequest->approved_amount);

        return (new MailMessage)
            ->subject('[KMoney] Fido approvato — ' . $amount . ' KY')
            ->greeting('Il tuo fido è stato approvato!')
            ->line("La tua richiesta di fido è stata accettata per **{$amount} KY**.")
            ->when(
                $this->creditRequest->approved_amount != $this->creditRequest->requested_amount,
                fn($m) => $m->line('Nota: l\'importo approvato differisce da quello richiesto.')
            )
            ->when($this->creditRequest->admin_note, fn($m) => $m->line("Nota dall'operatore: *{$this->creditRequest->admin_note}*"))
            ->action('Visualizza il tuo fido', route('portal.fido'))
            ->line('Il fido è operativo da subito sul tuo conto KMoney.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'    => 'credit_limit_approved',
            'message' => "Fido approvato: {$this->creditRequest->approved_amount} KY",
            'url'     => route('portal.fido'),
            'credit_limit_request_id' => $this->creditRequest->id,
        ];
    }
}
