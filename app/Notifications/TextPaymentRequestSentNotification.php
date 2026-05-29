<?php

namespace App\Notifications;

use App\Models\TextPaymentRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class TextPaymentRequestSentNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly TextPaymentRequest $request,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $senderName = $this->request->fromAccount?->company?->name
            ?? $this->request->fromAccount?->display_name
            ?? 'Un\'azienda';

        $amount = $this->request->formattedAmount();
        $due    = $this->request->due_date
            ? ' Scadenza: ' . $this->request->due_date->format('d/m/Y') . '.'
            : '';

        return [
            'icon'  => '📄',
            'title' => 'Nuova richiesta di pagamento',
            'body'  => $senderName . ' ti ha inviato una richiesta di pagamento da ' . $amount . '.' . $due,
            'link'  => route('portal.text-requests.show', $this->request),
        ];
    }
}
