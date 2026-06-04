<?php

namespace App\Notifications;

use App\Models\Company;
use App\Models\NfcCardAuthSession;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class NfcCardPinRequestNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly NfcCardAuthSession $session,
        public readonly ?Company           $merchant,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'icon'  => '💳',
            'title' => 'Autorizza pagamento Card NFC',
            'body'  => sprintf(
                '%s richiede %s KY. Apri l\'app e inserisci il PIN per autorizzare.',
                $this->merchant?->name ?? 'Un commerciante',
                ky_format($this->session->amount),
            ),
            'link'  => route('nfc.card.authorize', $this->session->nonce),
        ];
    }
}
