<?php

namespace App\Notifications;

use App\Models\NettingProposal;
use App\Notifications\Concerns\RespectsNotificationPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class NettingAcceptedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly NettingProposal $proposal,
    ) {}

    use RespectsNotificationPreferences;

    public function via(object $notifiable): array
    {
        return $this->resolveChannels($notifiable, 'netting_accepted', ['database', 'mail'], ['database', 'mail']);
    }

    public function toArray(object $notifiable): array
    {
        $counterpartyName = $this->proposal->counterpartyAccount?->display_name ?? 'La controparte';

        $body = $counterpartyName . ' ha accettato la compensazione #' . $this->proposal->id . '.';
        if ($this->proposal->net_amount > 0) {
            $body .= ' Saldo netto di ' . ky_format($this->proposal->net_amount) . ' KY già contabilizzato.';
        } else {
            $body .= ' Tutti i crediti incrociati sono stati compensati.';
        }

        return [
            'icon'  => '✅',
            'title' => 'Compensazione accettata',
            'body'  => $body,
            'link'  => route('portal.netting.show', $this->proposal),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Compensazione accettata #' . $this->proposal->id)
            ->greeting('Compensazione accettata')
            ->line(($this->proposal->counterpartyAccount?->display_name ?? 'La controparte') . ' ha accettato la compensazione #' . $this->proposal->id . '.')
            ->line($this->proposal->net_amount > 0 ? 'Saldo netto di ' . ky_format($this->proposal->net_amount) . ' KY contabilizzato.' : 'Tutti i crediti incrociati sono stati compensati.')
            ->action('Visualizza compensazione', route('portal.netting.show', $this->proposal))
            ->salutation('Il team KMoney');
    }
}
