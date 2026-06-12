<?php

namespace App\Notifications;

use App\Models\PaymentRequest;
use App\Notifications\Concerns\RespectsNotificationPreferences;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Notifica al DEBITORE (from_account owner) che la richiesta sta per scadere.
 * Inviata 24h e 1h prima della scadenza.
 */
class PaymentRequestExpiringNotification extends Notification
{
    use RespectsNotificationPreferences;

    public function __construct(
        public readonly PaymentRequest $paymentRequest,
        public readonly string         $window, // '24h' | '1h'
    ) {}

    public function via(object $notifiable): array
    {
        return $this->filteredChannels($notifiable, ['mail', 'database']);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $amount     = ky_format($this->paymentRequest->amount);
        $creditor   = $this->paymentRequest->toAccount?->display_name ?? 'il creditore';
        $expiresAt  = $this->paymentRequest->expires_at?->format('d/m/Y H:i') ?? '—';
        $windowLabel = $this->window === '1h' ? '1 ora' : '24 ore';

        return (new MailMessage)
            ->subject("⏰ Richiesta di {$amount} KY in scadenza tra {$windowLabel}")
            ->greeting('Promemoria pagamento')
            ->line("{$creditor} ti ha richiesto **{$amount} KY**.")
            ->line("La richiesta scade il **{$expiresAt}** (tra {$windowLabel}).")
            ->action('Vai alle richieste', route('portal.requests'))
            ->line('Se non paghi entro la scadenza, la richiesta verrà annullata automaticamente.');
    }

    public function toArray(object $notifiable): array
    {
        $amount      = ky_format($this->paymentRequest->amount);
        $creditor    = $this->paymentRequest->toAccount?->display_name ?? 'il creditore';
        $windowLabel = $this->window === '1h' ? '1 ora' : '24 ore';

        return [
            'icon'  => '⏰',
            'title' => "Richiesta in scadenza tra {$windowLabel}",
            'body'  => "{$creditor} ti ha richiesto {$amount} KY. Scade tra {$windowLabel}.",
            'link'  => route('portal.requests'),
        ];
    }
}
