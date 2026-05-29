<?php

namespace App\Notifications;

use App\Models\NettingProposal;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class NettingProposedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly NettingProposal $proposal,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $proposerName = $this->proposal->proposerAccount?->display_name ?? 'Un azienda';
        $netLabel = $this->proposal->net_amount > 0
            ? ' Saldo netto: ' . number_format($this->proposal->net_amount, 2, ',', '.') . ' KY.'
            : ' I crediti si pareggiano perfettamente.';

        return [
            'icon'  => '🔄',
            'title' => 'Proposta di compensazione',
            'body'  => $proposerName . ' ha proposto una compensazione crediti incrociati.' . $netLabel,
            'link'  => route('portal.netting.show', $this->proposal),
        ];
    }
}
