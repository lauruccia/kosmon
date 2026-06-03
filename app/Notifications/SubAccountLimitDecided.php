<?php

namespace App\Notifications;

use App\Models\SubAccountLimitRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Inviata al gestore del sottoconto quando il titolare approva o rifiuta
 * una richiesta di aumento limite / sforamento.
 */
class SubAccountLimitDecided extends Notification implements ShouldQueue
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
        $subName   = $this->limitRequest->subAccount->account_name
                  ?? $this->limitRequest->subAccount->display_name;
        $decider   = $this->limitRequest->decidedBy->name;
        $typeLabel = $this->limitRequest->typeLabel();
        $amount    = ky_format($this->limitRequest->requested_amount);
        $approved  = $this->limitRequest->isApproved();

        $mail = (new MailMessage)
            ->subject(($approved ? '✓ Approvata' : '✗ Rifiutata') . ": richiesta sottoconto «{$subName}»")
            ->greeting("Ciao {$notifiable->name},")
            ->line("{$decider} ha " . ($approved ? '**approvato**' : '**rifiutato**') . " la tua richiesta sul sottoconto **«{$subName}»**.")
            ->line("**Tipo:** {$typeLabel}")
            ->line("**Importo:** {$amount} KY");

        if ($this->limitRequest->decision_note) {
            $mail->line("**Nota:** {$this->limitRequest->decision_note}");
        }

        if ($approved && $this->limitRequest->isTemporaryOverdraft()) {
            $expiry = $this->limitRequest->overdraft_expires_at?->format('d/m/Y H:i');
            $mail->line("Puoi effettuare il pagamento entro le **{$expiry}**. Dopo tale data l'autorizzazione scadrà automaticamente.");
        }

        return $mail->action('Vai al portale', route('portal.pay.form'));
    }

    public function toArray(object $notifiable): array
    {
        $subName  = $this->limitRequest->subAccount->account_name
                 ?? $this->limitRequest->subAccount->display_name;
        $approved = $this->limitRequest->isApproved();
        $verb     = $approved ? 'approvata' : 'rifiutata';

        return [
            'type'             => 'subaccount_limit_decided',
            'message'          => "Richiesta {$verb} per «{$subName}»: " . $this->limitRequest->typeLabel(),
            'limit_request_id' => $this->limitRequest->id,
            'sub_account_id'   => $this->limitRequest->sub_account_id,
            'approved'         => $approved,
            'action_url'       => route('portal.pay.form'),
        ];
    }
}
