<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Alert al backoffice sui pagamenti ricorrenti KYCard: rata rimasta insoluta
 * dopo tutti i tentativi, contestazione (chargeback) aperta dal titolare
 * della carta, o un'anomalia che richiede un controllo a mano.
 */
class KyCardSubscriptionAdminAlert extends Notification
{
    public function __construct(
        private readonly string $title,
        private readonly array $lines = [],
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('[KMONEY] ' . $this->title)
            ->greeting('Pagamenti KYCard');

        foreach ($this->lines as $line) {
            $mail->line($line);
        }

        return $mail->action('Vai al pannello admin', url('/admin/ky-cards/ordini'));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'    => 'kycard_subscription_admin_alert',
            'title'   => $this->title,
            'message' => implode(' — ', $this->lines),
            'url'     => '/admin/ky-cards/ordini',
        ];
    }
}
