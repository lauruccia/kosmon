<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail as BaseVerifyEmail;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\URL;

class VerifyEmailNotification extends BaseVerifyEmail
{
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = $this->verificationUrl($notifiable);

        $expiresInMinutes = Config::get('auth.verification.expire', 60);

        return (new MailMessage)
            ->subject('Conferma il tuo indirizzo email — KMoney')
            ->view('emails.verify-email', [
                'user'             => $notifiable,
                'url'              => $url,
                'expiresInMinutes' => $expiresInMinutes,
            ]);
    }

    /**
     * Genera l'URL firmato e temporaneo per la verifica dell'email.
     */
    protected function verificationUrl($notifiable): string
    {
        return URL::temporarySignedRoute(
            'verification.verify',
            Carbon::now()->addMinutes(Config::get('auth.verification.expire', 60)),
            [
                'id'   => $notifiable->getKey(),
                'hash' => sha1($notifiable->getEmailForVerification()),
            ]
        );
    }
}
