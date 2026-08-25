<?php

namespace App\Notifications;

use App\Models\PaymentMandate;
use App\Models\PaymentMandateCharge;
use App\Notifications\Concerns\RespectsNotificationPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * "Kosmoshop ha addebitato 12,00 KY sul tuo conto."
 *
 * Non è una cortesia: è la metà pratica della promessa "puoi revocare quando
 * vuoi". Per revocare bisogna prima accorgersi, e un addebito che non avvisa
 * nessuno è esattamente quello che un mandato rubato userebbe.
 *
 * Solo database (e quindi push del browser, via SendWebPushAfterNotification):
 * un'email per ogni acquisto sarebbe rumore, e il rumore si smette di leggerlo.
 */
class MandateChargedNotification extends Notification implements ShouldQueue
{
    use Queueable;
    use RespectsNotificationPreferences;

    public function __construct(
        public readonly PaymentMandate $mandate,
        public readonly PaymentMandateCharge $charge,
    ) {}

    public function via(object $notifiable): array
    {
        return $this->resolveChannels($notifiable, 'mandate_charged', ['database'], ['database', 'mail']);
    }

    public function toArray(object $notifiable): array
    {
        $titolo = $this->charge->order_title ? ' — ' . $this->charge->order_title : '';

        return [
            'title' => 'Pagamento automatico eseguito',
            'body'  => ky_format($this->charge->amount) . ' KY addebitati da '
                . $this->appName() . $titolo . '. Se non sei stato tu, revoca subito l\'autorizzazione.',
            'url'   => route('portal.connected-apps.index'),
            'type'  => 'mandate_charged',
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
