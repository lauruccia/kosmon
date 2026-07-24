<?php

namespace App\Notifications;

use App\Models\PlanPayment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PlanUpgradeCompleted extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly PlanPayment $payment) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Upgrade piano completato — ' . ($this->payment->toPlan->name ?? '—'))
            ->greeting('Ciao ' . $notifiable->name . '!')
            ->line('Il pagamento per il passaggio al piano "' . ($this->payment->toPlan->name ?? '—') . '" è andato a buon fine.')
            ->line('**Importo pagato:** € ' . number_format($this->payment->amount_cents / 100, 2, ',', '.'))
            ->line('**Metodo:** ' . strtoupper($this->payment->payment_method))
            ->action('Vai al tuo profilo', route('portal.plan.index'))
            ->line('Il tuo nuovo piano è già attivo nella directory del circuito.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'    => 'plan_upgrade_completed',
            'title'   => 'Piano aggiornato: ' . ($this->payment->toPlan->name ?? '—'),
            'body'    => 'Il pagamento upgrade è stato confermato ed il nuovo piano è attivo.',
            'url'     => '/azienda/piano',
            'payment_uuid' => $this->payment->uuid,
        ];
    }
}
