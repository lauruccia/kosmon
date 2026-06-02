<?php

namespace App\Notifications;

use App\Models\NettingProposal;
use App\Notifications\Concerns\RespectsNotificationPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class NettingRejectedNotification extends Notification implements ShouldQueue
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

        return [
            'icon'  => '❌',
            'title' => 'Compensazione rifiutata',
            'body'  => $counterpartyName . ' ha rifiutato la proposta di compensazione #' . $this->proposal->id . '. I tuoi crediti in sospeso rimangono invariati.',
            'link'  => route('portal.netting.show', $this->proposal),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Compensazione rifiutata #' . $this->proposal->id)
            ->greeting('Compensazione rifiutata')
            ->line(($this->proposal->counterpartyAccount?->display_name ?? 'La controparte') . ' ha rifiutato la proposta di compensazione #' . $this->proposal->id . '.')
            ->line('I tuoi crediti in sospeso rimangono invariati.')
            ->action('Visualizza dettagli', route('portal.netting.show', $this->proposal))
            ->salutation('Il team KMoney');
    }
}
