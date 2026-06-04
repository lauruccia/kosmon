<?php

namespace App\Notifications;

use App\Models\CreditLimitRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CreditLimitRequested extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly CreditLimitRequest $creditRequest) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $company = $this->creditRequest->account->company;
        $amount  = ky_format($this->creditRequest->requested_amount);

        return (new MailMessage)
            ->subject("[KMoney] Nuova richiesta fido — {$company->name}")
            ->greeting('Richiesta fido in attesa')
            ->line("L'azienda **{$company->name}** ha richiesto un fido di **{$amount} KY**.")
            ->when($this->creditRequest->reason, fn($m) => $m->line("Motivazione: *{$this->creditRequest->reason}*"))
            ->action('Gestisci la richiesta', route('admin.credit-requests.index'))
            ->line('Puoi approvare, modificare o rifiutare la richiesta dal pannello admin.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'    => 'credit_limit_requested',
            'message' => "Richiesta fido da {$this->creditRequest->account->company->name}: {$this->creditRequest->requested_amount} KY",
            'url'     => route('admin.credit-requests.index'),
            'credit_limit_request_id' => $this->creditRequest->id,
        ];
    }
}
