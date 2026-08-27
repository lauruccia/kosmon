<?php

namespace App\Notifications;

use App\Models\OrderReturnRequest;
use App\Notifications\Concerns\RespectsNotificationPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A CHI VENDE: un cliente ha chiesto un reso (giro 2, 27/08/2026).
 *
 * Questa notifica è l'unico motivo per cui il venditore aprirà la pagina: una
 * pratica aperta e non vista è un cliente che aspetta e che, non ricevendo
 * risposta, scriverà all'assistenza del circuito. Per questo il canale email è
 * acceso di default anche se il venditore ha già la pastiglia in pagina.
 */
class OrderReturnRequestedNotification extends Notification
{
    use Queueable;
    use RespectsNotificationPreferences;

    public function __construct(public readonly OrderReturnRequest $richiesta)
    {
    }

    public function via(object $notifiable): array
    {
        return $this->resolveChannels($notifiable, 'order_return_requested', ['database', 'mail'], ['database', 'mail']);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $order = $this->richiesta->order;

        return (new MailMessage)
            ->subject('Richiesta di reso sull\'ordine ' . $order->numero)
            ->greeting("Ciao {$notifiable->name},")
            ->line('Un cliente ha chiesto di restituire **' . $order->summary_title . '**.')
            ->line('Motivo indicato: *' . $this->richiesta->reason . '*')
            ->line('Se accetti, i ' . ky_format($order->total_ky)
                . ' KY tornano al cliente e la merce rientra nel tuo magazzino. Se rifiuti, devi scrivere il perché: lo leggerà lui.')
            ->action('Rispondi alla richiesta', route('portal.sales.show', $order));
    }

    public function toArray(object $notifiable): array
    {
        $order = $this->richiesta->order;

        return [
            'type'         => 'order_return_requested',
            'order_uuid'   => $order->uuid,
            'numero'       => $order->numero,
            'titolo'       => $order->summary_title,
            'richiesta_id' => $this->richiesta->id,
            'motivo'       => $this->richiesta->reason,
            'link'         => route('portal.sales.show', $order),
        ];
    }
}
