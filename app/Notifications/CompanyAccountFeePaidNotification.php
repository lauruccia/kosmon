<?php

namespace App\Notifications;

use App\Models\CompanyAccountFeePayment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * La quota di apertura conto e' saldata (03/09/2026).
 *
 * E' la ricevuta di 600 euro: chi li versa deve avere qualcosa di scritto che
 * non sia una pagina web vista una volta sola.
 *
 * IL TESTO CAMBIA CON LA STRADA, e la differenza non e' cosmetica: in euro il
 * conto non viene toccato affatto (i 600 sono il prezzo dell'apertura, non una
 * ricarica), in KY il conto e' andato sotto di 600. Dire la stessa cosa nei due
 * casi vorrebbe dire che in uno dei due e' falsa.
 */
class CompanyAccountFeePaidNotification extends Notification implements ShouldQueue
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
            ->subject('Quota di apertura conto KMoney saldata')
            ->greeting('Ciao ' . $notifiable->name . '!')
            ->line('La quota di apertura del conto aziendale KMoney risulta saldata.');

        if ($this->payment->isPaidInEuro()) {
            $mail->line('**Importo:** € ' . number_format($this->payment->amount_eur, 2, ',', '.')
                . ' (' . $this->metodoLeggibile() . ')');

            // L'accredito in KY, quando il circuito ne ha deciso uno, e' un
            // movimento vero sul conto: la cifra si legge da li' e non da
            // un'impostazione, che nel frattempo puo' essere cambiata.
            $accredito = (int) ($this->payment->transfer?->amount ?? 0);

            if ($accredito > 0) {
                $mail->line('Sul conto sono stati accreditati **' . ky_format($accredito) . ' KY**.');
            } else {
                $mail->line('Il saldo KY del conto non è stato toccato: la quota di apertura è il prezzo del conto, non una ricarica.');
            }
        } else {
            $mail->line('**Importo:** ' . ky_format((int) $this->payment->ky_amount) . ' KY, addebitati sul conto.')
                ->line('Il saldo è sceso di ' . ky_format((int) $this->payment->ky_amount) . ' KY, e il massimale è stato aumentato dello stesso importo: il fido che avevi resta intero.');
        }

        return $mail
            ->line('Grazie: il conto è a posto e non c\'è altro da fare.')
            ->action('Vai al tuo conto', url('/dashboard'));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'   => 'company_account_fee_paid',
            'title'  => 'Quota di apertura conto saldata',
            'body'   => $this->payment->isPaidInEuro()
                ? 'Quota di apertura conto saldata: € ' . number_format($this->payment->amount_eur, 2, ',', '.') . '.'
                    . ((int) ($this->payment->transfer?->amount ?? 0) > 0
                        ? ' Accreditati ' . ky_format((int) $this->payment->transfer->amount) . ' KY sul conto.'
                        : '')
                : 'Quota di apertura conto saldata con il saldo KY: ' . ky_format((int) $this->payment->ky_amount) . ' KY addebitati.',
            'url'    => '/movimenti',
            'amount' => (int) $this->payment->ky_amount,
        ];
    }

    private function metodoLeggibile(): string
    {
        return CompanyAccountFeePayment::METHODS[$this->payment->payment_method] ?? $this->payment->payment_method;
    }
}
