<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ContractOtpNotification extends Notification
{

    public function __construct(
        public readonly string $otp,
        public readonly string $companyName,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Codice di firma contratto KMoney — ' . $this->otp)
            ->greeting('Firma del Contratto di Adesione')
            ->line('Hai richiesto di firmare digitalmente il Contratto di Adesione al circuito KMoney per **' . $this->companyName . '**.')
            ->line('Il tuo codice OTP è:')
            ->line('## ' . $this->otp)
            ->line('Il codice è valido per **15 minuti**. Inseriscilo nella pagina di firma per completare la procedura.')
            ->line('Se non hai richiesto questo codice, ignora questa email.')
            ->salutation('Il team KMoney');
    }
}
