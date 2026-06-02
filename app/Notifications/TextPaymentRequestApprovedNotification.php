<?php

namespace App\Notifications;

use App\Models\TextPaymentRequest;
use App\Notifications\Concerns\RespectsNotificationPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TextPaymentRequestApprovedNotification extends Notification implements ShouldQueue
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

        return [
            'icon'  => '✅',
            'title' => 'Richiesta di pagamento approvata',
            'body'  => $payerName . ' ha approvato e pagato la tua richiesta di ' . $this->request->formattedAmount() . '.',
            'link'  => route('portal.text-requests.show', $this->request),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Richiesta di pagamento approvata — ' . $this->request->formattedAmount())
            ->greeting('Richiesta approvata e pagata')
            ->line(($this->request->toAccount?->display_name ?? 'Il debitore') . ' ha approvato e pagato la tua richiesta di ' . $this->request->formattedAmount() . '.')
            ->action('Visualizza dettagli', route('portal.text-requests.show', $this->request))
            ->salutation('Il team KMoney');
    }
}
