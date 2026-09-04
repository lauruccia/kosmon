<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * L'admin ha messo la quota di iscrizione in carico a un utente
 * (RegistrationFeeService::requestFrom, 01/09/2026).
 *
 * Non e' una notifica di cortesia: da questo momento l'utente non puo' piu'
 * pagare, incassare o comprare finche' non salda, e deve sapere subito
 * perche' — trovarsi i bottoni spenti senza spiegazioni e' il modo piu'
 * veloce per riempire l'assistenza di telefonate.
 */
class RegistrationFeeRequestedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $amountCents) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Quota di iscrizione KMoney da saldare — ' . ky_format($this->amountCents) . ' KY')
            ->greeting('Ciao ' . $notifiable->name . '!')
            ->line('Per completare la tua adesione al circuito KMoney resta da saldare la quota di iscrizione.')
            ->line('**Importo:** € ' . number_format($this->amountCents / 100, 2, ',', '.') . ' (oppure ' . ky_format($this->amountCents) . ' KY)')
            ->line('' . (\App\Models\SystemSetting::userLimitDefaults()->registrationFeeKyCredit() > 0
                ? 'Se la paghi in euro ricevi ' . ky_format(\App\Models\SystemSetting::userLimitDefaults()->registrationFeeKyCredit()) . ' KY sul tuo conto. '
                : '') . 'Se la paghi con il saldo KY, il conto va sotto di quell\'importo e lo recuperi invitando altre persone nel circuito.')
            ->action('Salda la quota', url('/quota-iscrizione'))
            ->line('Fino al pagamento puoi entrare e vedere il tuo conto, ma non puoi inviare KY, incassare o acquistare nel negozio.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'   => 'registration_fee_requested',
            'title'  => 'Quota di iscrizione da saldare',
            'body'   => 'Ti è stata richiesta la quota di iscrizione di ' . ky_format($this->amountCents) . ' KY.',
            'url'    => '/quota-iscrizione',
            'amount' => $this->amountCents,
        ];
    }
}
