<?php

namespace App\Notifications;

use App\Models\Order;
use App\Notifications\Concerns\RespectsNotificationPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A CHI HA COMPRATO: il pacco è partito (fase C, 27/08/2026).
 *
 * È la notizia che chi compra aspetta davvero, ed è anche il motivo per cui
 * nella fase B abbiamo chiesto corriere e tracking al venditore: senza, questa
 * mail direbbe soltanto "è partito" e non servirebbe a niente.
 */
class OrderShippedNotification extends Notification
{
    use Queueable;
    use RespectsNotificationPreferences;

    public function __construct(public readonly Order $order)
    {
    }

    public function via(object $notifiable): array
    {
        return $this->resolveChannels($notifiable, 'order_shipped', ['database', 'mail'], ['database', 'mail']);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('Ordine ' . $this->order->numero . ' spedito')
            ->greeting("Ciao {$notifiable->name},")
            ->line('**' . $this->order->summary_title . '** è partito.');

        if ($this->order->tracking_code) {
            $mail->line($this->order->carrier
                ? 'Corriere **' . $this->order->carrier . '**, codice di tracciamento **' . $this->order->tracking_code . '**.'
                : 'Codice di tracciamento: **' . $this->order->tracking_code . '**.');
        } else {
            // Senza tracking non si promette quello che non si sa: il venditore
            // non l'ha scritto, ed e' l'unico che potrebbe saperlo.
            $mail->line('Il venditore non ha indicato un codice di tracciamento. Se ti serve, puoi chiederglielo dalla pagina dell\'ordine.');
        }

        if ($this->order->richiedeSpedizione()) {
            $mail->line('Arriva a: ' . $this->order->shipping_address
                . ', ' . $this->order->shipping_postal_code . ' ' . $this->order->shipping_city . '.');
        }

        return $mail->action('Vedi il tuo ordine', route('portal.orders.show', $this->order));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'          => 'order_shipped',
            'order_uuid'    => $this->order->uuid,
            'numero'        => $this->order->numero,
            'titolo'        => $this->order->summary_title,
            'carrier'       => $this->order->carrier,
            'tracking_code' => $this->order->tracking_code,
            'link'          => route('portal.orders.show', $this->order),
        ];
    }
}
