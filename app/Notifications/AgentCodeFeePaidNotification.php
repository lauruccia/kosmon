<?php

namespace App\Notifications;

use App\Models\AgentCodeFeePayment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * La quota per il codice agente e' saldata (02/09/2026).
 *
 * PERCHE' NON C'ERA E DOVEVA ESSERCI. La gemella dei privati
 * (RegistrationFeePaidNotification) esiste dall'01/09 per 30 euro. Qui se ne
 * pagano 480 — sedici volte tanto — e l'unico segnale era la pagina di esito,
 * che l'utente vede solo se non chiude la scheda: chi pagava e chiudeva non
 * riceveva niente, e non aveva nessuna carta in mano.
 *
 * IL TESTO CAMBIA CON LA STRADA, e la differenza non e' cosmetica: in euro il
 * conto non viene toccato affatto (i 480 sono il prezzo del codice, non una
 * ricarica — vedi AgentCodeFeeService), in KY il conto e' andato sotto di 480.
 * Dire la stessa cosa nei due casi vorrebbe dire che in uno dei due e' falsa.
 *
 * L'AZIONE PORTA ALLA FIRMA, non alla dashboard: pagare non fa diventare
 * agenti, e il passo dopo e' uno solo.
 */
class AgentCodeFeePaidNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly AgentCodeFeePayment $payment) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('Quota per il codice agente KNM saldata')
            ->greeting('Ciao ' . $notifiable->name . '!')
            ->line('La quota per il codice agente KNM risulta saldata.');

        if ($this->payment->isPaidInEuro()) {
            $mail->line('**Importo:** € ' . number_format($this->payment->amount_eur, 2, ',', '.')
                . ' (' . $this->metodoLeggibile() . ')')
                ->line('Il tuo saldo KY non è stato toccato: la quota per il codice agente è il prezzo della nomina, non una ricarica.');
        } else {
            $mail->line('**Importo:** ' . ky_format((int) $this->payment->ky_amount) . ' KY, addebitati sul tuo conto.')
                ->line('Il saldo è sceso di ' . ky_format((int) $this->payment->ky_amount) . ' KY, e il tuo massimale è stato aumentato dello stesso importo: il fido che avevi resta intero.');
        }

        return $mail
            ->line('Ora manca solo la firma del contratto di nomina: è quella che ti rende agente a tutti gli effetti.')
            ->action('Firma il contratto di nomina', url('/mlm/contratto-agente'));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'   => 'agent_code_fee_paid',
            'title'  => 'Quota codice agente saldata',
            'body'   => $this->payment->isPaidInEuro()
                ? 'Quota saldata: € ' . number_format($this->payment->amount_eur, 2, ',', '.') . '. Ora puoi firmare il contratto di nomina.'
                : 'Quota saldata con il saldo KY: ' . ky_format((int) $this->payment->ky_amount) . ' KY addebitati. Ora puoi firmare il contratto di nomina.',
            'url'    => '/mlm/contratto-agente',
            'amount' => (int) $this->payment->ky_amount,
        ];
    }

    private function metodoLeggibile(): string
    {
        return AgentCodeFeePayment::METHODS[$this->payment->payment_method] ?? $this->payment->payment_method;
    }
}
