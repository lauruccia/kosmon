<?php

namespace App\Notifications;

use App\Models\Order;
use App\Notifications\Concerns\RespectsNotificationPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A CHI HA COMPRATO: il venditore ha annullato l'ordine (giro 2, 27/08/2026).
 *
 * È l'unica notizia di questo blocco che il compratore NON si aspetta: gli
 * altri avvisi seguono una sua azione, questo gli arriva addosso. Per questo
 * dice sempre tre cose nell'ordine — che è annullato, perché, e che i soldi
 * sono già tornati sul suo conto. La terza è quella che evita la telefonata.
 */
class OrderCancelledNotification extends Notification
{
    use Queueable;
    use RespectsNotificationPreferences;

    public function __construct(
        public readonly Order $order,
        public readonly int $rimborsoKy = 0,
    ) {
    }

    public function via(object $notifiable): array
    {
        return $this->resolveChannels($notifiable, 'order_cancelled', ['database', 'mail'], ['database', 'mail']);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('Ordine ' . $this->order->numero . ' annullato')
            ->greeting("Ciao {$notifiable->name},")
            ->line('**' . $this->order->summary_title . '** è stato annullato da '
                . ($this->order->company?->name ?? 'il venditore') . '.');

        if (filled($this->order->cancel_reason)) {
            $mail->line('Motivo indicato: *' . $this->order->cancel_reason . '*');
        }

        if ($this->rimborsoKy > 0) {
            $mail->line('Ti sono stati restituiti **' . ky_format($this->rimborsoKy)
                . ' KY**: li trovi già sul tuo conto, nei movimenti.');
        }

        // La quota in euro non passa dal circuito: dirlo qui evita che il
        // compratore la dia per persa o la reclami al posto sbagliato.
        if ($this->order->hasEuroQuota() && $this->order->payment?->status === \App\Models\MarketplaceOrderPayment::STATUS_PAID) {
            $mail->line('La quota di ' . number_format($this->order->total_eur / 100, 2, ',', '.')
                . ' € che avevi già versato non passa dal circuito: te la restituirà direttamente il venditore.');
        }

        return $mail->action('Vedi l\'ordine', route('portal.orders.show', $this->order));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'        => 'order_cancelled',
            'order_uuid'  => $this->order->uuid,
            'numero'      => $this->order->numero,
            'titolo'      => $this->order->summary_title,
            'motivo'      => $this->order->cancel_reason,
            'rimborso_ky' => $this->rimborsoKy,
            'link'        => route('portal.orders.show', $this->order),
        ];
    }
}
