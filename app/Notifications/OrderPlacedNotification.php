<?php

namespace App\Notifications;

use App\Models\Order;
use App\Notifications\Concerns\RespectsNotificationPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A CHI HA COMPRATO: il suo ordine è stato registrato (fase C, 27/08/2026).
 *
 * Fino a ieri il circuito avvisava soltanto il venditore
 * (`NewMarketplaceOrderNotification`) e chi comprava restava in silenzio: né
 * una conferma, né un numero d'ordine, né la traccia di dove sarebbe arrivata
 * la merce. Amazon ne manda tre, noi ne mandavamo zero.
 *
 * Questa è la prima delle due che Laura ha scelto (la terza, "consegnato", è
 * stata scartata: qui non c'è un corriere che lo conferma, lo segna il
 * venditore a mano, e rischierebbe di arrivare a pacco già in casa).
 */
class OrderPlacedNotification extends Notification
{
    use Queueable;
    use RespectsNotificationPreferences;

    public function __construct(public readonly Order $order)
    {
    }

    public function via(object $notifiable): array
    {
        return $this->resolveChannels($notifiable, 'order_placed', ['database', 'mail'], ['database', 'mail']);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('Ordine ' . $this->order->numero . ' confermato')
            ->greeting("Ciao {$notifiable->name},")
            ->line('Il tuo ordine **' . $this->order->numero . '** è stato registrato.')
            ->line('**' . $this->order->summary_title . '**')
            ->line('Totale: **' . ky_format((int) $this->order->total_ky) . ' KY**'
                . ($this->order->total_eur > 0
                    ? ', più una quota di **' . number_format($this->order->total_eur / 100, 2, ',', '.') . ' €**'
                    : '') . '.');

        if ($this->order->richiedeSpedizione()) {
            $mail->line('Spedizione a: ' . $this->order->shipping_recipient_name
                . ', ' . $this->order->shipping_address
                . ', ' . $this->order->shipping_postal_code . ' ' . $this->order->shipping_city . '.');
        }

        // La quota in euro e' l'unica cosa che resta da fare all'acquirente, e
        // finche' non arriva il venditore non spedisce: va detta chiaro e con
        // il bottone che ci porta, non sepolta in fondo.
        if ($this->order->isInAttesaDiEuro() && $this->order->payment) {
            return $mail
                ->line('**Manca ancora il pagamento della quota in euro**: il venditore preparerà la spedizione appena risulterà saldata.')
                ->action('Paga la quota in euro', route('portal.shop.orders.pay', $this->order->payment))
                ->line('Da "I miei ordini" puoi seguire lo stato in ogni momento.');
        }

        return $mail
            ->action('Vedi il tuo ordine', route('portal.orders.show', $this->order))
            ->line('Ti avviseremo appena il venditore lo avrà spedito.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'       => 'order_placed',
            'order_uuid' => $this->order->uuid,
            'numero'     => $this->order->numero,
            'titolo'     => $this->order->summary_title,
            'total_ky'   => (int) $this->order->total_ky,
            'total_eur'  => (int) $this->order->total_eur,
            'link'       => route('portal.orders.show', $this->order),
        ];
    }
}
