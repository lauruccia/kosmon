<?php

namespace App\Notifications;

use App\Models\KyCardSubscription;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Al cliente: l'addebito automatico con carta non e' andato a buon fine.
 * Stripe riprova da solo per un po' di giorni: qui si dice cosa fare per
 * non perdere la rata (aggiornare la carta).
 */
class KyCardSubscriptionPaymentFailed extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly KyCardSubscription $subscription) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    private function label(): string
    {
        $name = $this->subscription->kyCard->name ?? 'KYCard';

        return $this->subscription->isInstallments()
            ? 'rata della ' . $name
            : 'rinnovo mensile della ' . $name;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Pagamento non riuscito — ' . $this->label())
            ->greeting('Ciao ' . $notifiable->name . '!')
            ->line('Non siamo riusciti ad addebitare € ' . number_format($this->subscription->amount_eur, 2, ',', '.') . ' sulla tua carta per la ' . $this->label() . '.')
            ->line('Riproveremo automaticamente nei prossimi giorni. Per evitare che la rata resti insoluta, controlla che la carta sia valida e abbia fondi, oppure aggiornala dalla pagina dei tuoi pagamenti.')
            ->action('Vai ai tuoi pagamenti', url('/ricarica/abbonamenti'));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'  => 'kycard_subscription_payment_failed',
            'title' => 'Pagamento non riuscito',
            'body'  => 'Non siamo riusciti ad addebitare la ' . $this->label() . '. Controlla la tua carta.',
            'url'   => '/ricarica/abbonamenti',
        ];
    }
}
