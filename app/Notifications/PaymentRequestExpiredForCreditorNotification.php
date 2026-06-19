<?php

namespace App\Notifications;

use App\Models\PaymentRequest;
use App\Notifications\Concerns\RespectsNotificationPreferences;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Notifica al CREDITORE (to_account owner) che la richiesta è scaduta senza pagamento.
 * Include CTA "Reinvia".
 */
class PaymentRequestExpiredForCreditorNotification extends Notification
{
    use RespectsNotificationPreferences;

    public function __construct(
        public readonly PaymentRequest $paymentRequest,
    ) {}

    public function via(object $notifiable): array
    {
        return $this->resolveChannels($notifiable, 'payment_request_expired', ['mail', 'database'], ['mail', 'database']);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $amount  = ky_format($this->paymentRequest->amount);
        $debtor  = $this->paymentRequest->fromAccount?->display_name ?? 'il debitore';

        return (new MailMessage)
            ->subject("Richiesta di {$amount} KY scaduta senza pagamento")
            ->greeting('Richiesta scaduta')
            ->line("La richiesta di **{$amount} KY** inviata a **{$debtor}** è scaduta senza essere pagata.")
            ->action('Invia una nuova richiesta', route('portal.receive.form'))
            ->line('Puoi inviare una nuova richiesta in qualsiasi momento.');
    }

    public function toArray(object $notifiable): array
    {
        $amount = ky_format($this->paymentRequest->amount);
        $debtor = $this->paymentRequest->fromAccount?->display_name ?? 'il debitore';

        return [
            'icon'  => '❌',
            'title' => 'Richiesta scaduta senza pagamento',
            'body'  => "La richiesta di {$amount} KY a {$debtor} è scaduta. Puoi reinviarla.",
            'link'  => route('portal.receive.form'),
        ];
    }
}
