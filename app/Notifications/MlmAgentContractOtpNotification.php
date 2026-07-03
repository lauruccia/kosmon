<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MlmAgentContractOtpNotification extends Notification
{
    public function __construct(
        public readonly string $otp,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Codice di firma contratto Agente KNM — ' . $this->otp)
            ->greeting('Firma del Contratto di Nomina ad Agente KNM')
            ->line('Hai richiesto di firmare digitalmente il contratto di nomina ad Agente KNM.')
            ->line('Il tuo codice OTP è:')
            ->line('## ' . $this->otp)
            ->line('Il codice è valido per **15 minuti**. Inseriscilo nella pagina di firma per completare la procedura.')
            ->line('Se non hai richiesto questo codice, ignora questa email.')
            ->salutation('Il team KMoney');
    }
}
