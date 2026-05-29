<?php

namespace App\Notifications;

use App\Models\Account;
use App\Models\Transfer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class CreditNoteIssuedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Transfer  $creditNote,
        public readonly Account   $fromAccount,
        public readonly Account   $toAccount,
        public readonly ?Transfer $originalTransfer = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $body = sprintf(
            'Hai ricevuto una nota di credito di %s KY da %s.',
            number_format($this->creditNote->amount, 2, ',', '.'),
            $this->fromAccount->display_name,
        );

        if ($this->originalTransfer) {
            $body .= ' Riferimento originale: ' . $this->originalTransfer->reference . '.';
        }

        return [
            'icon'  => '📋',
            'title' => 'Nota di credito ricevuta',
            'body'  => $body,
            'link'  => route('portal.movements'),
        ];
    }
}
