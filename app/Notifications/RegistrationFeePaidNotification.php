<?php

namespace App\Notifications;

use App\Models\RegistrationFeePayment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * La quota di iscrizione e' saldata (01/09/2026).
 *
 * Serve per due motivi diversi, e il secondo e' quello vero:
 *
 * 1. e' la ricevuta. Chi paga 30 euro vuole qualcosa di scritto, e finora non
 *    riceveva niente: l'unico segnale era il banner rosso che spariva.
 * 2. e' il momento in cui il conto si riapre. Fino a un istante prima non
 *    poteva inviare KY, incassare o comprare; dirglielo qui evita che se ne
 *    accorga per caso tre giorni dopo.
 *
 * Il testo cambia con la strada scelta, perche' il risultato e' diverso: in
 * euro il conto ha RICEVUTO i KY, in KY e' andato sotto. Dire la stessa cosa
 * nei due casi vorrebbe dire che in uno dei due e' sbagliata.
 */
class RegistrationFeePaidNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly RegistrationFeePayment $payment) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $importo = (int) $this->payment->ky_amount;

        $mail = (new MailMessage)
            ->subject('Quota di iscrizione KMoney saldata')
            ->greeting('Ciao ' . $notifiable->name . '!')
            ->line('La tua quota di iscrizione al circuito KMoney risulta saldata.');

        // QUANTI KY SIANO TORNATI INDIETRO LO DICE IL MOVIMENTO, non l'importo
        // della quota (04/09/2026): la restituzione la decide l'admin e puo'
        // essere zero, meno o piu' di quanto e' stato pagato. Leggere
        // l'impostazione di oggi direbbe il falso a chi ha pagato ieri, e
        // ky_amount direbbe il falso a chiunque abbia una restituzione diversa
        // dall'importo.
        if ($this->payment->isPaidInEuro()) {
            $restituiti = (int) ($this->payment->transfer?->amount ?? 0);

            $mail->line('**Importo:** € ' . number_format($this->payment->amount_eur, 2, ',', '.')
                . ' (' . $this->metodoLeggibile() . ')');

            if ($restituiti > 0) {
                $mail->line('Sul tuo conto sono stati accreditati **' . ky_format($restituiti) . ' KY**.');
            }
        } else {
            $mail->line('**Importo:** ' . ky_format($importo) . ' KY, addebitati sul tuo conto.')
                ->line('Il saldo è sceso di ' . ky_format($importo) . ' KY. Lo recuperi invitando altre persone nel circuito: ogni persona, agente o attività che entra grazie a te ti fa incassare un bonus in KY.');
        }

        return $mail
            ->line('Da adesso puoi inviare KY, incassare e acquistare nel negozio.')
            ->action('Vai al tuo conto', url('/dashboard'));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'   => 'registration_fee_paid',
            'title'  => 'Quota di iscrizione saldata',
            'body'   => $this->payment->isPaidInEuro()
                ? ((int) ($this->payment->transfer?->amount ?? 0) > 0
                    ? 'Quota saldata: sul tuo conto sono stati accreditati ' . ky_format((int) $this->payment->transfer->amount) . ' KY.'
                    : 'Quota di iscrizione saldata.')
                : 'Quota saldata con il saldo KY: ' . ky_format($this->payment->ky_amount) . ' KY addebitati.',
            'url'    => '/movimenti',
            'amount' => (int) $this->payment->ky_amount,
        ];
    }

    private function metodoLeggibile(): string
    {
        return RegistrationFeePayment::METHODS[$this->payment->payment_method] ?? $this->payment->payment_method;
    }
}
