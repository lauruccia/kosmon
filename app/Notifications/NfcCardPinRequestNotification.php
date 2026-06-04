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
        public readonly string             $signedUrl,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'icon'  => '💳',
            'title' => 'Richiesta di pagamento',
            'body'  => sprintf(
                '%s richiede %s KY. Tocca per confermare.',
                $this->merchant?->name ?? 'Un commerciante',
                ky_format($this->session->amount),
            ),
            'link'       => $this->signedUrl,
            'expires_at' => $this->session->expires_at->toISOString(),
        ];
    }
}
