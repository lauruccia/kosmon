<?php

namespace App\Notifications;

use App\Models\Order;
use App\Notifications\Concerns\RespectsNotificationPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A CHI HA COMPRATO: la quota in euro è ancora da saldare (fase C, 27/08/2026).
 *
 * L'ordine è fermo, e la cosa peggiore è che è fermo in silenzio: i KY sono
 * già usciti dal conto, il venditore aspetta, e chi ha comprato spesso non
 * ricorda che mancava un pezzo. Questo sollecito parte una volta sola, dopo
 * qualche giorno.
 *
 * UNA VOLTA SOLA, e non per pigrizia: un ordine che resta in attesa per un
 * mese genererebbe trenta email identiche, e la trentesima non convince
 * nessuno più della prima — fa solo finire il circuito nello spam.
 */
class OrderEuroQuotaReminderNotification extends Notification
{
    use Queueable;
    use RespectsNotificationPreferences;

    public function __construct(public readonly Order $order)
    {
    }

    public function via(object $notifiable): array
    {
        return $this->resolveChannels($notifiable, 'order_euro_reminder', ['database', 'mail'], ['database', 'mail']);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $euro = number_format($this->order->total_eur / 100, 2, ',', '.');

        $mail = (new MailMessage)
            ->subject('Ordine ' . $this->order->numero . ': manca la quota in euro')
            ->greeting("Ciao {$notifiable->name},")
            ->line('Il tuo ordine **' . $this->order->numero . '** (' . $this->order->summary_title . ') è ancora in attesa.')
            ->line('I KY sono già stati addebitati, ma restano da saldare **' . $euro . ' €**: finché non risultano incassati il venditore non può spedire.');

        if ($this->order->payment) {
            $mail->action('Paga ' . $euro . ' €', route('portal.shop.orders.pay', $this->order->payment));
        }

        return $mail->line('Se hai già pagato con bonifico, il venditore deve solo confermarne la ricezione: in quel caso puoi ignorare questo messaggio.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'       => 'order_euro_reminder',
            'order_uuid' => $this->order->uuid,
            'numero'     => $this->order->numero,
            'total_eur'  => (int) $this->order->total_eur,
            'link'       => $this->order->payment
                ? route('portal.shop.orders.pay', $this->order->payment)
                : route('portal.orders.show', $this->order),
        ];
    }
}
