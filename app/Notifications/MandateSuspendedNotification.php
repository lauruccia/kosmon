<?php

namespace App\Notifications;

use App\Models\PaymentMandate;
use App\Notifications\Concerns\RespectsNotificationPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "Attività insolita: ho sospeso gli addebiti automatici."
 *
 * Questa invece arriva anche per email, perché è un evento di sicurezza e
 * potrebbe essere il primo segnale che qualcosa non va. L'utente non deve fare
 * niente per rimettere le cose a posto: può continuare a comprare confermando
 * ogni acquisto, oppure riattivare l'autorizzazione dal portale.
 */
class MandateSuspendedNotification extends Notification implements ShouldQueue
{
    use Queueable;
    use RespectsNotificationPreferences;

    public function __construct(public readonly PaymentMandate $mandate)
    {
    }

    public function via(object $notifiable): array
    {
        return $this->resolveChannels($notifiable, 'mandate_security', ['mail', 'database'], ['mail', 'database']);
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Attività insolita su ' . $this->appName() . ' — KMoney')
            ->greeting('Ciao ' . $notifiable->name . ',')
            ->line('Abbiamo contato molti pagamenti automatici in poco tempo da **' . $this->appName() . '**, '
                . 'e per sicurezza abbiamo sospeso gli addebiti senza conferma.')
            ->line('**Nessun pagamento è stato annullato** e il tuo conto è al sicuro: da adesso, '
                . 'semplicemente, ogni acquisto ti verrà chiesto di confermarlo.')
            ->action('Vedi le app collegate', route('portal.connected-apps.index'))
            ->line('Se non riconosci questi pagamenti, revoca l\'autorizzazione dalla pagina qui sopra '
                . 'e contatta il supporto.')
            ->salutation('Il team KMoney');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'Addebiti automatici sospesi',
            'body'  => 'Attività insolita su ' . $this->appName()
                . ': gli addebiti automatici sono stati sospesi. Da ora ogni acquisto va confermato.',
            'url'   => route('portal.connected-apps.index'),
            'type'  => 'mandate_suspended',
        ];
    }

    private function appName(): string
    {
        foreach ((array) config('oauth.clients', []) as $client) {
            if (($client['client_id'] ?? null) === $this->mandate->client_id) {
                return (string) ($client['name'] ?? $this->mandate->client_id);
            }
        }

        return $this->mandate->client_id;
    }
}
