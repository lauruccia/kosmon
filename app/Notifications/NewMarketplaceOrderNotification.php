<?php

namespace App\Notifications;

use App\Notifications\Concerns\RespectsNotificationPreferences;

use App\Models\Transfer;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Notifica al venditore quando un prodotto dello shop viene acquistato tramite
 * il bottone "Acquista" (ListingController::buy). Il transfer ha kind
 * portal_marketplace_order e listing_id valorizzato.
 */
class NewMarketplaceOrderNotification extends Notification
{
    use RespectsNotificationPreferences;

    use Queueable;

    public function __construct(
        public readonly Transfer $transfer,
        public readonly string $listingTitle,
        public readonly int $quantity,
    ) {}

    public function via(object $notifiable): array
    {
        return $this->resolveChannels($notifiable, 'marketplace_order_received', ['database', 'mail'], ['database', 'mail']);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $formatted = ky_format((int) $this->transfer->amount);
        $qtyLabel  = $this->quantity > 1 ? " (x{$this->quantity})" : '';

        return (new MailMessage)
            ->subject("Nuovo ordine ricevuto nello shop: {$this->listingTitle}")
            ->greeting("Ciao {$notifiable->name},")
            ->line("Hai ricevuto un nuovo ordine per **{$this->listingTitle}{$qtyLabel}**.")
            ->line("Importo accreditato: **{$formatted} KY**.")
            ->line("Rif. movimento: {$this->transfer->reference}")
            ->action('Vai ai movimenti', url('/movimenti'))
            ->line('Contatta l\'acquirente per organizzare consegna/erogazione, se necessario.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'         => 'marketplace_order_received',
            'transfer_id'  => $this->transfer->id,
            'reference'    => $this->transfer->reference,
            'listing_id'   => $this->transfer->listing_id,
            'listing_title'=> $this->listingTitle,
            'quantity'     => $this->quantity,
            'amount'       => (int) $this->transfer->amount,
            'booked_at'    => $this->transfer->booked_at?->toIso8601String(),
        ];
    }
}
