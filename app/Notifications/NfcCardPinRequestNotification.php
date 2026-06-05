<?php

namespace App\Notifications;

use App\Models\Company;
use App\Models\NfcCardAuthSession;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class NfcCardPinRequestNotification extends Notification
{
    public function __construct(
        public readonly NfcCardAuthSession $session,
        public readonly ?Company           $merchant,
        public readonly string             $signedUrl,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'broadcast', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $merchantName = $this->merchant?->name ?? 'Un commerciante';
        $amount       = ky_format($this->session->amount);

        return (new MailMessage)
            ->subject("Richiesta di pagamento: {$amount} KY da {$merchantName}")
            ->greeting('Ciao!')
            ->line("{$merchantName} ha richiesto un pagamento di **{$amount} KY** tramite la tua card NFC.")
            ->line('Clicca il pulsante qui sotto per confermare o rifiutare il pagamento.')
            ->action('Conferma pagamento', $this->signedUrl)
            ->line('Il link è valido per 10 minuti. Se non hai richiesto questo pagamento, ignora questa email.')
            ->salutation('Il team KMoney');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'icon'       => '💳',
            'title'      => 'Richiesta di pagamento',
            'body'       => sprintf(
                '%s richiede %s KY. Tocca per confermare.',
                $this->merchant?->name ?? 'Un commerciante',
                ky_format($this->session->amount),
            ),
            // link permanente: funziona sempre dalla campanella (sessione autenticata)
            'link'       => route('portal.nfc-cards.index'),
            // signed_url: usato dal listener web push per apertura diretta anche senza sessione
            'signed_url' => $this->signedUrl,
            'expires_at' => $this->session->expires_at->toISOString(),
        ];
    }
}
