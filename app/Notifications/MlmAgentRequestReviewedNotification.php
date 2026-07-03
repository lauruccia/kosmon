<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Inviata all'utente quando l'admin approva o rifiuta la sua richiesta di
 * diventare Agente KNM.
 */
class MlmAgentRequestReviewedNotification extends Notification
{
    public function __construct(
        public readonly string  $decision, // 'approved' | 'rejected'
        public readonly ?string $reason = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        if ($this->decision === 'approved') {
            return (new MailMessage)
                ->subject('La tua richiesta di diventare Agente KNM è stata approvata')
                ->greeting('Ottime notizie, ' . $notifiable->name . '!')
                ->line('La tua richiesta di diventare Agente KNM è stata approvata.')
                ->line('Per completare l\'attivazione devi firmare digitalmente il contratto di nomina ad agente.')
                ->action('Firma il contratto', route('portal.mlm.agent-contract.show'))
                ->salutation('Il team KMoney');
        }

        return (new MailMessage)
            ->subject('La tua richiesta di diventare Agente KNM')
            ->greeting('Ciao ' . $notifiable->name . ',')
            ->line('Purtroppo la tua richiesta di diventare Agente KNM non è stata approvata al momento.')
            ->when($this->reason, fn ($mail) => $mail->line('Motivo: ' . $this->reason))
            ->line('Puoi ripresentare la richiesta in qualsiasi momento dal tuo profilo.')
            ->action('Vai al portale', route('portal.mlm.agent-request.show'))
            ->salutation('Il team KMoney');
    }

    public function toArray(object $notifiable): array
    {
        if ($this->decision === 'approved') {
            return [
                'icon'  => '✅',
                'title' => 'Richiesta agente approvata',
                'body'  => 'Firma il contratto di nomina per attivare il tuo profilo agente.',
                'link'  => route('portal.mlm.agent-contract.show'),
            ];
        }

        return [
            'icon'  => '❌',
            'title' => 'Richiesta agente non approvata',
            'body'  => $this->reason ?: 'Puoi ripresentare la richiesta in qualsiasi momento.',
            'link'  => route('portal.mlm.agent-request.show'),
        ];
    }
}
