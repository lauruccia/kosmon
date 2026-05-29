<?php

namespace App\Notifications;

use App\Models\NettingProposal;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class NettingAcceptedNotification extends Notification implements ShouldQueue
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
        $counterpartyName = $this->proposal->counterpartyAccount?->display_name ?? 'La controparte';

        $body = $counterpartyName . ' ha accettato la compensazione #' . $this->proposal->id . '.';
        if ($this->proposal->net_amount > 0) {
            $body .= ' Saldo netto di ' . number_format($this->proposal->net_amount, 2, ',', '.') . ' KY già contabilizzato.';
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
}
