<?php

namespace App\Notifications;

use App\Models\ApiToken;
use App\Notifications\Concerns\RespectsNotificationPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ApiTokenNewIpNotification extends Notification implements ShouldQueue
{
    use Queueable;
    use RespectsNotificationPreferences;

    public function __construct(
        public readonly ApiToken $token,
        public readonly string $newIp,
        public readonly string $previousIp = '',
    ) {}

    public function via(object $notifiable): array
    {
        return $this->resolveChannels($notifiable, 'api_token_security', ['mail', 'database'], ['mail', 'database']);
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Token API usato da nuovo IP — KMoney')
            ->greeting('Ciao ' . $notifiable->name . ',')
            ->line("Il token API **"{$this->token->name}"** (prefisso: `{$this->token->token_prefix}...`) è stato utilizzato da un indirizzo IP diverso dal solito.")
            ->line("**Nuovo IP:** {$this->newIp}")
            ->when($this->previousIp, fn ($m) => $m->line("**IP precedente:** {$this->previousIp}"))
            ->line('**Data e ora:** ' . now()->format('d/m/Y \a\l\l\e H:i'))
            ->line("Se si tratta di un cambiamento atteso (es. cambio server), puoi ignorare questa email.")
            ->action('Gestisci i tuoi token API', route('portal.api-tokens.index'))
            ->line("Se non riconosci questo utilizzo, revoca subito il token e verifica le integrazioni.")
            ->salutation('Il team KMoney');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'Token API usato da nuovo IP',
            'body'  => "Il token \"{$this->token->name}\" è stato usato dall'IP {$this->newIp}.",
            'url'   => route('portal.api-tokens.index'),
            'type'  => 'api_token_new_ip',
        ];
    }
}
