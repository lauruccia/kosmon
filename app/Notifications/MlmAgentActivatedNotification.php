<?php

namespace App\Notifications;

use App\Notifications\Concerns\RespectsNotificationPreferences;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Inviata all'utente non appena il contratto di nomina ad agente viene
 * firmato e mlm_role diventa 'agente'.
 */
class MlmAgentActivatedNotification extends Notification
{
    use RespectsNotificationPreferences;

    public function via(object $notifiable): array
    {
        return $this->resolveChannels($notifiable, 'mlm_agent_status', ['mail', 'database'], ['mail', 'database']);
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Sei ufficialmente Agente KNM!')
            ->greeting('Benvenuto nel programma agenti KNM, ' . $notifiable->name . '!')
            ->line('Hai firmato il contratto di nomina e da ora sei un Agente KNM a tutti gli effetti.')
            ->line('Puoi iniziare subito a invitare clienti e altri agenti dalla sezione "I miei inviti" del portale.')
            ->action('Vai alla mia struttura', route('portal.mlm.struttura'))
            ->salutation('Il team KMoney');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'icon'  => '🎉',
            'title' => 'Sei agente KNM!',
            'body'  => 'Il contratto è stato firmato: da ora puoi invitare clienti e altri agenti.',
            'link'  => route('portal.mlm.struttura'),
        ];
    }
}
