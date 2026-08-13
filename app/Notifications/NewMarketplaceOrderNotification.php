<?php

namespace App\Notifications;

use App\Notifications\Concerns\RespectsNotificationPreferences;

use App\Models\MarketplaceOrderPayment;
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
        // Valorizzato solo se il prodotto ha anche una quota EUR da pagare
        // (vedi ListingController::buy). Se presente, mail e notifica in-app
        // linkano DIRETTAMENTE all'ordine — prima linkavano solo a
        // "/movimenti" e il venditore non aveva nessun modo di raggiungere
        // la pagina da cui confermare un bonifico ricevuto (2026-08-13, vedi
        // anche PaymentController::authorizeViewer).
        public readonly ?MarketplaceOrderPayment $payment = null,
    ) {}

    public function via(object $notifiable): array
    {
        return $this->resolveChannels($notifiable, 'marketplace_order_received', ['database', 'mail'], ['database', 'mail']);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $formatted = ky_format((int) $this->transfer->amount);
        $qtyLabel  = $this->quantity > 1 ? " (x{$this->quantity})" : '';

        $mail = (new MailMessage)
            ->subject("Nuovo ordine ricevuto nello shop: {$this->listingTitle}")
            ->greeting("Ciao {$notifiable->name},")
            ->line("Hai ricevuto un nuovo ordine per **{$this->listingTitle}{$qtyLabel}**.")
            ->line("Importo accreditato: **{$formatted} KY**.")
            ->line("Rif. movimento: {$this->transfer->reference}");

        if ($this->payment) {
            $euroFormatted = number_format($this->payment->amount / 100, 2, ',', '.');

            $mail->line("L'acquirente deve ancora saldare una quota di **{$euroFormatted} €**. Da qui puoi seguire lo stato del pagamento e, in caso di bonifico, confermarne la ricezione.")
                ->action('Vai all\'ordine', $this->actionUrl());
        } else {
            $mail->action('Vai ai movimenti', $this->actionUrl());
        }

        return $mail->line('Contatta l\'acquirente per organizzare consegna/erogazione, se necessario.');
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
            'link'         => $this->actionUrl(),
        ];
    }

    /** Link diretto all'ordine (pagina pagamento EUR) o, in mancanza, al movimento KY. */
    private function actionUrl(): string
    {
        return $this->payment
            ? route('portal.shop.orders.pay', $this->payment)
            : route('portal.movements.show', $this->transfer);
    }
}
