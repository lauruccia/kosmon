<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Esonero dalla quota per il codice agente, e sua revoca
 * (AgentCodeFeeService::waive / revokeWaiver, 01/09/2026).
 *
 * UNA CLASSE SOLA PER I DUE VERSI: sono lo stesso fatto letto avanti e
 * indietro, e tenerli insieme e' l'unico modo perche' i due testi restino
 * coerenti quando uno dei due cambia.
 */
class AgentCodeFeeWaiverNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param int         $amountCents importo esonerato (o rimesso in carico)
     * @param bool        $revoked     true = la quota torna dovuta
     * @param string|null $reason      il motivo scritto dall'admin, all'esonero
     */
    public function __construct(
        public readonly int $amountCents,
        public readonly bool $revoked = false,
        public readonly ?string $reason = null,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $importo = number_format($this->amountCents / 100, 2, ',', '.') . ' €';

        if ($this->revoked) {
            return (new MailMessage)
                ->subject('Quota per il codice agente: di nuovo da saldare')
                ->greeting('Ciao ' . $notifiable->name . '!')
                ->line('L\'esonero dalla quota per il codice agente è stato revocato: la quota di ' . $importo . ' è di nuovo da saldare prima della firma del contratto di nomina.')
                ->action('Vai alla quota', url('/mlm/quota-codice'))
                ->line('Per qualsiasi chiarimento, contatta l\'assistenza del circuito.');
        }

        $mail = (new MailMessage)
            ->subject('Quota per il codice agente: non devi pagarla')
            ->greeting('Ciao ' . $notifiable->name . '!')
            ->line('Il circuito ti ha esonerato dalla quota di ' . $importo . ' per il codice agente: non devi pagare niente.');

        if ($this->reason !== null && $this->reason !== '') {
            $mail->line('**Motivo:** ' . $this->reason);
        }

        return $mail
            ->line('Puoi procedere subito con la firma del contratto di nomina.')
            ->action('Firma il contratto', url('/mlm/contratto-agente'))
            ->line('A presto!');
    }

    public function toArray(object $notifiable): array
    {
        $importo = number_format($this->amountCents / 100, 2, ',', '.') . ' €';

        return $this->revoked
            ? [
                'type'   => 'agent_code_fee_waiver_revoked',
                'title'  => 'Quota codice agente di nuovo da saldare',
                'body'   => 'L\'esonero è stato revocato: la quota di ' . $importo . ' torna da pagare.',
                'url'    => '/mlm/quota-codice',
                'amount' => $this->amountCents,
            ]
            : [
                'type'   => 'agent_code_fee_waived',
                'title'  => 'Quota codice agente esonerata',
                'body'   => 'Non devi pagare la quota di ' . $importo . ': puoi firmare subito il contratto di nomina.',
                'url'    => '/mlm/contratto-agente',
                'amount' => $this->amountCents,
            ];
    }
}
