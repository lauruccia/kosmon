<?php

namespace App\Notifications;

use App\Models\Account;
use App\Models\Transfer;
use Illuminate\Notifications\Notification;

/**
 * La richiesta di incasso e' scaduta senza risposta.
 *
 * Va a chi l'ha MANDATA (il creditore): e' l'unico che stava aspettando
 * qualcosa. Al debitore non si scrive niente — sarebbe una email per dirgli
 * che non deve piu' fare una cosa che non ha fatto.
 */
class PaymentRequestExpiredNotification extends Notification
{
    public function __construct(
        public readonly Transfer $transfer,
        public readonly Account  $fromAccount,
        public readonly Account  $toAccount,
        public readonly int      $giorni,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'icon'  => '⌛',
            'title' => 'Richiesta di incasso scaduta',
            'body'  => sprintf(
                'La tua richiesta di %s KY a %s e\' scaduta dopo %d giorni senza risposta. Puoi rifarla quando vuoi.',
                ky_format($this->transfer->amount),
                $this->fromAccount->display_name,
                $this->giorni,
            ),
            'link'  => route('portal.movements'),
        ];
    }
}
