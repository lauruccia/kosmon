<?php

namespace App\Notifications;

use App\Notifications\Concerns\RespectsNotificationPreferences;

use App\Models\LoginLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class NewIpLoginNotification extends Notification implements ShouldQueue
{
    use RespectsNotificationPreferences;

    use Queueable;

    public function __construct(public readonly LoginLog $log) {}

    public function via(object $notifiable): array
    {
        return $this->resolveChannels($notifiable, 'new_ip_login', ['mail'], ['mail']);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $ip      = $this->log->ip_address;
        $country = $this->log->country ?? 'N/D';
        $city    = $this->log->city ?? 'N/D';
        $browser = $this->log->browser ?? 'N/D';
        $os      = $this->log->os ?? 'N/D';
        $date    = $this->log->logged_in_at
            ? $this->log->logged_in_at->format('d/m/Y \a\l\l\e H:i')
            : now()->format('d/m/Y \a\l\l\e H:i');

        return (new MailMessage)
            ->subject('Accesso da un nuovo indirizzo IP — KMoney')
            ->greeting('Ciao ' . $notifiable->name . ',')
            ->line("Abbiamo rilevato un accesso al tuo account KMoney da un indirizzo IP mai visto prima.")
            ->line("**IP:** {$ip}")
            ->line("**Posizione:** {$city}, {$country}")
            ->line("**Browser:** {$browser} su {$os}")
            ->line("**Data e ora:** {$date}")
            ->line("Se sei stato tu, puoi ignorare questa email.")
            ->action('Controlla i tuoi accessi', url('/sessioni'))
            ->line("Se non riconosci questo accesso, ti consigliamo di cambiare immediatamente la password.")
            ->salutation('Il team KMoney');
    }
}
