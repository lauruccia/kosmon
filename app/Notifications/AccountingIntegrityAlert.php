<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AccountingIntegrityAlert extends Notification
{
    public function __construct(private readonly array $errors) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('[KMONEY] ⚠️ Anomalie contabili rilevate')
            ->greeting('Allerta contabile')
            ->line('La verifica notturna degli invarianti contabili ha rilevato ' . count($this->errors) . ' anomalie.')
            ->line('**Dettaglio anomalie:**');

        foreach ($this->errors as $error) {
            $mail->line('• ' . $error);
        }

        return $mail
            ->line('Verificare immediatamente i conti coinvolti e contattare il team tecnico.')
            ->action('Vai al pannello admin', url('/admin'));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title'   => 'Anomalie contabili rilevate',
            'message' => count($this->errors) . ' invarianti violati. Verificare immediatamente.',
            'errors'  => $this->errors,
            'type'    => 'accounting_integrity_alert',
        ];
    }
}
