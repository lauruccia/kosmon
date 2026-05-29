<?php

namespace App\Notifications;

use App\Models\TextPaymentRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class TextPaymentRequestApprovedNotification extends Notification implements ShouldQueue
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

        return [
            'icon'  => '✅',
            'title' => 'Richiesta di pagamento approvata',
            'body'  => $payerName . ' ha approvato e pagato la tua richiesta di ' . $this->request->formattedAmount() . '.',
            'link'  => route('portal.text-requests.show', $this->request),
        ];
    }
}
