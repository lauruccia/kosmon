<?php

namespace App\Notifications;

use App\Models\TextPaymentRequest;
use App\Notifications\Concerns\RespectsNotificationPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TextPaymentRequestRejectedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly TextPaymentRequest $request,
    ) {}

    use RespectsNotificationPreferences;

    public function via(object $notifiable): array
    {
        return $this->resolveChannels($notifiable, 'text_request_approved', ['database', 'mail'], ['database', 'mail']);
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

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Richiesta di pagamento rifiutata — ' . $this->request->formattedAmount())
            ->greeting('Richiesta rifiutata')
            ->line(($this->request->toAccount?->display_name ?? 'Il debitore') . ' ha rifiutato la tua richiesta di ' . $this->request->formattedAmount() . '.')
            ->lineIf((bool)$this->request->note, 'Motivo: ' . $this->request->note . '.')
            ->action('Visualizza dettagli', route('portal.text-requests.show', $this->request))
            ->salutation('Il team KMoney');
    }
}
