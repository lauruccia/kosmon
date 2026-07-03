<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Avvisa un admin (super_admin) che un utente ha richiesto di diventare
 * Agente KNM. Inviata via email + notifica database (per il pannello admin).
 */
class MlmAgentRequestSubmittedNotification extends Notification
{
    public function __construct(
        public readonly User $requester,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Nuova richiesta per diventare Agente KNM')
            ->greeting('Nuova richiesta agente')
            ->line($this->requester->name . ' (' . $this->requester->email . ') ha richiesto di diventare Agente KNM.')
            ->action('Rivedi la richiesta', route('admin.mlm.requests.index'))
            ->line('Puoi approvare o rifiutare la richiesta dal pannello di amministrazione.')
            ->salutation('Il team KMoney');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'icon'  => '🧑‍💼',
            'title' => 'Nuova richiesta agente KNM',
            'body'  => $this->requester->name . ' ha richiesto di diventare Agente KNM.',
            'link'  => route('admin.mlm.requests.index'),
        ];
    }
}
