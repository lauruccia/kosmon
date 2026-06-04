<?php

namespace App\Notifications;

use App\Models\NettingProposal;
use App\Notifications\Concerns\RespectsNotificationPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class NettingProposedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly NettingProposal $proposal,
    ) {}

    use RespectsNotificationPreferences;

    public function via(object $notifiable): array
    {
        return $this->resolveChannels($notifiable, 'netting_proposed', ['database', 'mail'], ['database', 'mail']);
    }

    public function toArray(object $notifiable): array
    {
        $proposerName = $this->proposal->proposerAccount?->display_name ?? 'Un azienda';
        $netLabel = $this->proposal->net_amount > 0
            ? ' Saldo netto: ' . ky_format($this->proposal->net_amount) . ' KY.'
            : ' I crediti si pareggiano perfettamente.';

        return [
            'icon'  => '🔄',
            'title' => 'Proposta di compensazione',
            'body'  => $proposerName . ' ha proposto una compensazione crediti incrociati.' . $netLabel,
            'link'  => route('portal.netting.show', $this->proposal),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Proposta di compensazione crediti — ' . ($this->proposal->proposerAccount?->display_name ?? 'Un azienda'))
            ->greeting('Compensazione crediti incrociati')
            ->line(($this->proposal->proposerAccount?->display_name ?? 'Un azienda') . ' ha proposto una compensazione dei crediti incrociati.')
            ->line($this->proposal->net_amount > 0 ? 'Saldo netto: ' . ky_format($this->proposal->net_amount) . ' KY.' : 'I crediti si pareggiano perfettamente.')
            ->action('Visualizza proposta', route('portal.netting.show', $this->proposal))
            ->salutation('Il team KMoney');
    }
}
