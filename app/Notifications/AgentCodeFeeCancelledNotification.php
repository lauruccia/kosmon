<?php

namespace App\Notifications;

use App\Models\AgentCodeFeePayment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * L'admin ha annullato una quota per il codice agente gia' saldata
 * (AgentCodeFeeService::cancel, 01/09/2026).
 *
 * DUE TESTI DIVERSI, e la differenza non e' cosmetica:
 *   · pagata in KY  -> il movimento e' stato stornato, i KY sono tornati sul
 *                      conto ed e' un fatto gia' avvenuto;
 *   · pagata in euro -> non c'era nessun movimento da stornare (i 480 in euro
 *                      non accreditano KY), quindi il rimborso e' una cosa
 *                      che deve ancora succedere, a mano. Promettere uno
 *                      storno che non c'e' stato sarebbe la bugia peggiore
 *                      da mandare per email.
 */
class AgentCodeFeeCancelledNotification extends Notification implements ShouldQueue
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
            ->subject('Quota per il codice agente annullata')
            ->greeting('Ciao ' . $notifiable->name . '!')
            ->line('Il pagamento della tua quota per il codice agente è stato annullato dal circuito.');

        if ($this->payment->isPaidInEuro()) {
            $mail->line('**Importo:** ' . number_format($this->payment->amount_eur_cents / 100, 2, ',', '.') . ' €')
                 ->line('Il rimborso, se previsto, viene disposto separatamente dall\'amministrazione: non è automatico.');
        } else {
            $mail->line('**Importo:** ' . ky_format($this->payment->ky_amount) . ' KY')
                 ->line('Il movimento è stato stornato: i KY sono tornati sul tuo conto.');
        }

        return $mail
            ->line('La quota risulta di nuovo da saldare.')
            ->action('Vai alla quota', url('/mlm/quota-codice'))
            ->line('Se non ti aspettavi questo messaggio, contatta l\'assistenza del circuito.');
    }

    public function toArray(object $notifiable): array
    {
        $importo = $this->payment->isPaidInEuro()
            ? number_format($this->payment->amount_eur_cents / 100, 2, ',', '.') . ' €'
            : ky_format($this->payment->ky_amount) . ' KY';

        return [
            'type'       => 'agent_code_fee_cancelled',
            'title'      => 'Quota codice agente annullata',
            'body'       => 'Il pagamento di ' . $importo . ' è stato annullato: la quota è di nuovo da saldare.',
            'url'        => '/mlm/quota-codice',
            'amount'     => (int) $this->payment->amount_eur_cents,
            'payment_id' => $this->payment->uuid,
        ];
    }
}
