<?php

namespace App\Notifications;

use App\Models\TextPaymentRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class TextPaymentRequestRejectedNotification extends Notification implements ShouldQueue
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
        $payerName = $this->request->toAccount?->company?->name
            ?? $this->request->toAccount?->display_name
            ?? 'Il debitore';

        $reason = $this->request->note ? ' Motivo: ' . $this->request->note . '.' : '';

        return [
            'icon'  => '❌',
            'title' => 'Richiesta di pagamento rifiutata',
            'body'  => $payerName . ' ha rifiutato la tua richiesta di ' . $this->request->formattedAmount() . '.' . $reason,
            'link'  => route('portal.text-requests.show', $this->request),
        ];
    }
}
