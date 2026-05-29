<?php

namespace App\Notifications;

use App\Models\NettingProposal;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class NettingRejectedNotification extends Notification implements ShouldQueue
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

        return [
            'icon'  => '❌',
            'title' => 'Compensazione rifiutata',
            'body'  => $counterpartyName . ' ha rifiutato la proposta di compensazione #' . $this->proposal->id . '. I tuoi crediti in sospeso rimangono invariati.',
            'link'  => route('portal.netting.show', $this->proposal),
        ];
    }
}
