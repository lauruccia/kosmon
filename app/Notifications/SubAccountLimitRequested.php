<?php

namespace App\Notifications;

use App\Models\SubAccountLimitRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Inviata al titolare del conto padre quando un gestore di sottoconto
 * invia una richiesta di aumento limite o sforamento.
 */
class SubAccountLimitRequested extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly SubAccountLimitRequest $limitRequest,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $subName    = $this->limitRequest->subAccount->account_name
                   ?? $this->limitRequest->subAccount->display_name;
        $requester  = $this->limitRequest->requestedBy->name;
        $typeLabel  = $this->limitRequest->typeLabel();
        $amount     = ky_format($this->limitRequest->requested_amount);
        $url        = route('portal.accounts.structure');

        return (new MailMessage)
            ->subject("Richiesta da sottoconto «{$subName}»: {$typeLabel}")
            ->greeting("Ciao {$notifiable->name},")
            ->line("{$requester} ha inviato una richiesta dal sottoconto **«{$subName}»**.")
            ->line("**Tipo:** {$typeLabel}")
            ->line("**Importo richiesto:** {$amount} KY")
            ->line("**Motivazione:** {$this->limitRequest->reason}")
            ->action('Approva o rifiuta', $url)
            ->line("Accedi al portale per approvare o rifiutare la richiesta.");
    }

    public function toArray(object $notifiable): array
    {
        $subName = $this->limitRequest->subAccount->account_name
                ?? $this->limitRequest->subAccount->display_name;

        return [
            'type'             => 'subaccount_limit_requested',
            'message'          => "Richiesta da «{$subName}»: " . $this->limitRequest->typeLabel() . ' — ' . ky_format($this->limitRequest->requested_amount) . ' KY',
            'limit_request_id' => $this->limitRequest->id,
            'sub_account_id'   => $this->limitRequest->sub_account_id,
            'action_url'       => route('portal.accounts.structure'),
        ];
    }
}
