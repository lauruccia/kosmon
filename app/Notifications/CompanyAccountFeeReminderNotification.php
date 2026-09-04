<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sollecito della quota di apertura conto, UNA VOLTA SOLA
 * (Console\Commands\RemindRegistrationFees, 03/09/2026).
 *
 * QUI IL SOLLECITO PESA PIU' CHE ALTROVE. La quota dei privati ha alle spalle
 * un conto bloccato: prima o poi l'utente ci sbatte contro da solo. Questa no
 * — decisione di Laura del 03/09, l'azienda continua a operare — quindi il
 * banner e questa mail sono gli unici due modi in cui il circuito chiede
 * davvero i 600 euro. Resta comunque una volta sola: la ventesima mail non
 * convince nessuno piu' della prima, fa solo finire il circuito nello spam.
 */
class CompanyAccountFeeReminderNotification extends Notification implements ShouldQueue
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
            ->subject('La quota di apertura del tuo conto KMoney è ancora da saldare')
            ->greeting('Ciao ' . $notifiable->name . '!')
            ->line('Il conto aziendale è aperto e funziona, ma la quota di apertura non risulta ancora saldata.')
            ->line('**Importo:** € ' . number_format($this->amountCents / 100, 2, ',', '.'))
            ->line('È una quota una tantum: si paga una volta sola.')
            ->action('Salda la quota', url('/quota-apertura-conto'))
            ->line('Questo è l\'unico promemoria che riceverai.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'   => 'company_account_fee_reminder',
            'title'  => 'Quota di apertura conto ancora da saldare',
            'body'   => 'La quota di apertura conto di € ' . number_format($this->amountCents / 100, 2, ',', '.') . ' non risulta ancora saldata.',
            'url'    => '/quota-apertura-conto',
            'amount' => $this->amountCents,
        ];
    }
}
