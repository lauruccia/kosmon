<?php

namespace App\Notifications;

use App\Models\RegistrationFeePayment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * L'admin ha annullato una quota di iscrizione gia' saldata
 * (RegistrationFeeService::cancel, 01/09/2026).
 *
 * L'utente si ritrova il movimento stornato e la quota di nuovo da pagare:
 * sono due cambiamenti sul suo conto che non ha chiesto lui, e vanno detti.
 */
class RegistrationFeeCancelledNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly RegistrationFeePayment $payment) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Quota di iscrizione KMoney annullata')
            ->greeting('Ciao ' . $notifiable->name . '!')
            ->line('Il pagamento della tua quota di iscrizione è stato annullato dal circuito e il movimento è stato stornato.')
            ->line('**Importo:** ' . ky_format($this->payment->ky_amount) . ' KY')
            ->line('La quota risulta di nuovo da saldare.')
            ->action('Vai alla quota', url('/quota-iscrizione'))
            ->line('Se non ti aspettavi questo messaggio, contatta l\'assistenza del circuito.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'       => 'registration_fee_cancelled',
            'title'      => 'Quota di iscrizione annullata',
            'body'       => 'Il pagamento di ' . ky_format($this->payment->ky_amount) . ' KY è stato stornato: la quota è di nuovo da saldare.',
            'url'        => '/quota-iscrizione',
            'amount'     => $this->payment->ky_amount,
            'payment_id' => $this->payment->uuid,
        ];
    }
}
