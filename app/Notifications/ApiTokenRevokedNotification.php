<?php

namespace App\Notifications;

use App\Models\ApiToken;
use App\Notifications\Concerns\RespectsNotificationPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ApiTokenRevokedNotification extends Notification implements ShouldQueue
{
    use Queueable;
    use RespectsNotificationPreferences;

    public function __construct(
        public readonly ApiToken $token,
        public readonly string $revokedByIp = '',
    ) {}

    public function via(object $notifiable): array
    {
        return $this->resolveChannels($notifiable, 'api_token_security', ['mail', 'database'], ['mail', 'database']);
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Token API revocato — KMoney')
            ->greeting('Ciao ' . $notifiable->name . ',')
            ->line("Il token API **\"{$this->token->name}\"** (prefisso: `{$this->token->token_prefix}...`) è stato revocato.")
            ->line('**Data e ora:** ' . now()->format('d/m/Y \a\l\l\e H:i'))
            ->when($this->revokedByIp, fn ($m) => $m->line("**IP operazione:** {$this->revokedByIp}"))
            ->line('Tutte le integrazioni che usavano questo token hanno immediatamente perso accesso.')
            ->action('Gestisci i tuoi token API', route('portal.api-tokens.index'))
            ->line("Se non sei stato tu a revocare questo token, cambia subito la password del tuo account.")
            ->salutation('Il team KMoney');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title'   => 'Token API revocato',
            'body'    => "Il token \"{$this->token->name}\" ({$this->token->token_prefix}...) è stato revocato.",
            'url'     => route('portal.api-tokens.index'),
            'type'    => 'api_token_revoked',
        ];
    }
}
