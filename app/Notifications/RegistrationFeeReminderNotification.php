<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sollecito della quota di iscrizione, UNA VOLTA SOLA
 * (Console\Commands\RemindRegistrationFees, 01/09/2026).
 *
 * Il caso che chiude: una persona si registra, vede il banner rosso, rimanda,
 * e non ci torna piu'. Dal suo punto di vista non e' successo niente; dal
 * nostro e' un conto aperto che non puo' fare niente e che nessuno ha mai
 * richiamato. La mail di benvenuto e' arrivata quando la quota era l'ultimo
 * dei suoi pensieri: questa arriva quando ha avuto il tempo di guardarsi in
 * giro.
 *
 * Il tono e' diverso da RegistrationFeeRequestedNotification di proposito:
 * quella annuncia un blocco che nasce in quel momento, questa ricorda una cosa
 * lasciata a meta'. Chiamarle allo stesso modo vorrebbe dire scrivere una mail
 * che non e' giusta per nessuno dei due casi.
 */
class RegistrationFeeReminderNotification extends Notification implements ShouldQueue
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
            ->subject('La tua iscrizione a KMoney è rimasta a metà')
            ->greeting('Ciao ' . $notifiable->name . '!')
            ->line('Il tuo conto KMoney è aperto, ma la quota di iscrizione non risulta ancora saldata: finché resta così puoi entrare e vedere il tuo conto, ma non puoi inviare KY, incassare o acquistare nel negozio.')
            ->line('**Importo:** € ' . number_format($this->amountCents / 100, 2, ',', '.') . ' (oppure ' . ky_format($this->amountCents) . ' KY)')
            ->line('' . (\App\Models\SystemSetting::userLimitDefaults()->registrationFeeKyCredit() > 0
                ? 'Se la paghi in euro ricevi ' . ky_format(\App\Models\SystemSetting::userLimitDefaults()->registrationFeeKyCredit()) . ' KY sul tuo conto. '
                : '') . 'Se la paghi con il saldo KY il conto va sotto di quell\'importo, e lo recuperi invitando altre persone nel circuito.')
            ->action('Salda la quota', url('/quota-iscrizione'))
            ->line('Questo è l\'unico promemoria che riceverai: se hai cambiato idea non devi fare niente.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'   => 'registration_fee_reminder',
            'title'  => 'Quota di iscrizione ancora da saldare',
            'body'   => 'Il tuo conto resta limitato finché non salda la quota di ' . ky_format($this->amountCents) . ' KY.',
            'url'    => '/quota-iscrizione',
            'amount' => $this->amountCents,
        ];
    }
}
