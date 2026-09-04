<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * L'admin ha messo la quota di apertura conto in carico a un'azienda
 * (CompanyAccountFeeService::requestFrom, 03/09/2026).
 *
 * A differenza della gemella dei privati, questa NON annuncia un blocco: il
 * conto dell'azienda continua a funzionare (decisione di Laura del 03/09).
 * Dirle che non potra' piu' operare sarebbe falso, e nel giro di un'ora
 * l'assistenza avrebbe le telefonate di chi prova e vede che invece funziona.
 */
class CompanyAccountFeeRequestedNotification extends Notification implements ShouldQueue
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
            ->subject('Quota di apertura conto KMoney da saldare — € ' . number_format($this->amountCents / 100, 2, ',', '.'))
            ->greeting('Ciao ' . $notifiable->name . '!')
            ->line('Per il conto aziendale KMoney risulta da saldare la quota di apertura conto.')
            ->line('**Importo:** € ' . number_format($this->amountCents / 100, 2, ',', '.'))
            ->line('È una quota una tantum: si paga una volta sola, all\'apertura del conto.')
            ->action('Salda la quota', url('/quota-apertura-conto'))
            ->line('Nel frattempo il conto continua a funzionare normalmente.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'   => 'company_account_fee_requested',
            'title'  => 'Quota di apertura conto da saldare',
            'body'   => 'Ti è stata richiesta la quota di apertura conto di € ' . number_format($this->amountCents / 100, 2, ',', '.') . '.',
            'url'    => '/quota-apertura-conto',
            'amount' => $this->amountCents,
        ];
    }
}
