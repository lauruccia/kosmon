<?php

namespace App\Notifications;

use App\Models\TextPaymentRequest;
use App\Notifications\Concerns\RespectsNotificationPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TextPaymentRequestSentNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly TextPaymentRequest $request,
    ) {}

    use RespectsNotificationPreferences;

    public function via(object $notifiable): array
    {
        return $this->resolveChannels($notifiable, 'text_request_sent', ['database', 'mail'], ['database', 'mail']);
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

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Nuova richiesta di pagamento da ' . ($this->request->fromAccount?->display_name ?? 'Un azienda'))
            ->greeting('Richiesta di pagamento ricevuta')
            ->line(($this->request->fromAccount?->display_name ?? 'Un azienda') . ' ti ha inviato una richiesta di pagamento da ' . $this->request->formattedAmount() . '.')
            ->lineIf((bool)$this->request->due_date, 'Scadenza: ' . optional($this->request->due_date)->format('d/m/Y') . '.')
            ->action('Visualizza richiesta', route('portal.text-requests.show', $this->request))
            ->salutation('Il team KMoney');
    }
}
