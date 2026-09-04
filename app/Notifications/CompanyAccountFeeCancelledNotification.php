<?php

namespace App\Notifications;

use App\Models\CompanyAccountFeePayment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * L'admin ha annullato una quota di apertura conto gia' saldata
 * (AbstractFeeService::cancel, 03/09/2026).
 *
 * La quota torna da pagare: e' un cambiamento che l'azienda non ha chiesto e
 * va detto. Se era stata pagata in euro non c'e' nessun movimento da stornare
 * — quei soldi restano incassati e il rimborso lo fa una persona a mano — e il
 * testo non promette il contrario.
 */
class CompanyAccountFeeCancelledNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly CompanyAccountFeePayment $payment) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('Quota di apertura conto KMoney annullata')
            ->greeting('Ciao ' . $notifiable->name . '!')
            ->line('Il pagamento della quota di apertura del conto aziendale è stato annullato dal circuito.');

        if ($this->payment->isPaidInEuro()) {
            $mail->line('**Importo:** € ' . number_format($this->payment->amount_eur, 2, ',', '.'))
                ->line('Il saldo KY del conto non era stato toccato e non lo è adesso. Per l\'eventuale rimborso della somma versata ti contatterà l\'assistenza.');
        } else {
            $mail->line('**Importo:** ' . ky_format((int) $this->payment->ky_amount) . ' KY')
                ->line('Il movimento è stato stornato e il fido aggiuntivo tolto.');
        }

        return $mail
            ->line('La quota risulta di nuovo da saldare.')
            ->action('Vai alla quota', url('/quota-apertura-conto'))
            ->line('Se non ti aspettavi questo messaggio, contatta l\'assistenza del circuito.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'       => 'company_account_fee_cancelled',
            'title'      => 'Quota di apertura conto annullata',
            'body'       => 'Il pagamento della quota di apertura conto è stato annullato: la quota è di nuovo da saldare.',
            'url'        => '/quota-apertura-conto',
            'amount'     => (int) $this->payment->ky_amount,
            'payment_id' => $this->payment->uuid,
        ];
    }
}
