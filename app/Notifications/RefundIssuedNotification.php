<?php

namespace App\Notifications;

use App\Models\Account;
use App\Models\Transfer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class RefundIssuedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Transfer $refundTransfer,
        public readonly Transfer $originalTransfer,
        public readonly Account  $fromAccount,
        public readonly Account  $toAccount,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'icon'  => '↩️',
            'title' => 'Rimborso ricevuto',
            'body'  => sprintf(
                'Hai ricevuto un rimborso di %s KY da %s (rif. %s).',
                number_format($this->refundTransfer->amount, 2, ',', '.'),
                $this->fromAccount->display_name,
                $this->originalTransfer->reference,
            ),
            'link'  => route('portal.movements'),
        ];
    }
}
