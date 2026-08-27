<?php

namespace App\Notifications;

use App\Models\OrderReturnRequest;
use App\Notifications\Concerns\RespectsNotificationPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A CHI HA COMPRATO: il venditore ha risposto alla richiesta di reso
 * (giro 2, 27/08/2026).
 *
 * Una notifica sola per le due risposte, di proposito: chi la riceve sta
 * aspettando l'esito di UNA pratica, e spezzarla in due classi identiche
 * significherebbe solo doverle tenere allineate per sempre.
 */
class OrderReturnDecidedNotification extends Notification
{
    use Queueable;
    use RespectsNotificationPreferences;

    public function __construct(
        public readonly OrderReturnRequest $richiesta,
        public readonly int $rimborsoKy = 0,
    ) {
    }

    public function via(object $notifiable): array
    {
        return $this->resolveChannels($notifiable, 'order_return_decided', ['database', 'mail'], ['database', 'mail']);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $order = $this->richiesta->order;
        $accettato = $this->richiesta->status === OrderReturnRequest::STATUS_ACCEPTED;

        $mail = (new MailMessage)
            ->subject('Reso ' . ($accettato ? 'accettato' : 'rifiutato') . ' — ordine ' . $order->numero)
            ->greeting("Ciao {$notifiable->name},")
            ->line($accettato
                ? 'Il venditore ha **accettato** il reso di ' . $order->summary_title . '.'
                : 'Il venditore ha **rifiutato** il reso di ' . $order->summary_title . '.');

        if (filled($this->richiesta->decision_note)) {
            $mail->line(($accettato ? 'Nota del venditore: *' : 'Motivo del rifiuto: *')
                . $this->richiesta->decision_note . '*');
        }

        if ($accettato && $this->rimborsoKy > 0) {
            $mail->line('Ti sono stati restituiti **' . ky_format($this->rimborsoKy)
                . ' KY**: li trovi già sul tuo conto.');
        }

        if (! $accettato) {
            // Non è un vicolo cieco, e dirlo è quello che tiene la lite dentro
            // il circuito invece che su una recensione.
            $mail->line('Se non sei d\'accordo, puoi scrivere all\'assistenza del circuito citando il numero d\'ordine.');
        }

        return $mail->action('Vedi l\'ordine', route('portal.orders.show', $order));
    }

    public function toArray(object $notifiable): array
    {
        $order = $this->richiesta->order;

        return [
            'type'        => 'order_return_decided',
            'esito'       => $this->richiesta->status,
            'order_uuid'  => $order->uuid,
            'numero'      => $order->numero,
            'titolo'      => $order->summary_title,
            'nota'        => $this->richiesta->decision_note,
            'rimborso_ky' => $this->rimborsoKy,
            'link'        => route('portal.orders.show', $order),
        ];
    }
}
