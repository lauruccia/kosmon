<?php

namespace App\Notifications;

use App\Models\PaymentPlan;
use App\Notifications\Concerns\RespectsNotificationPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PaymentPlanRejectedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly PaymentPlan $plan) {}

    use RespectsNotificationPreferences;

    public function via(object $notifiable): array
    {
        return $this->resolveChannels($notifiable, 'payment_plan_approved', ['database', 'mail'], ['database', 'mail']);
    }

    public function toArray(object $notifiable): array
    {
        $counterparty = $this->plan->counterpartyAccount()?->display_name ?? 'La controparte';
        $total        = number_format($this->plan->total_amount, 2, ',', '.');

        return [
            'icon'  => '❌',
            'title' => 'Piano rateale rifiutato',
            'body'  => $counterparty . ' ha rifiutato la tua proposta di piano rateale da ' . $total . ' KY. Puoi proporne uno nuovo con condizioni diverse.',
            'link'  => route('portal.payment-plans.index'),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Piano rateale rifiutato — ' . number_format($this->plan->total_amount, 2, ',', '.') . ' KY')
            ->greeting('Piano rateale rifiutato')
            ->line(($this->plan->counterpartyAccount()?->display_name ?? 'La controparte') . ' ha rifiutato la proposta di piano rateale da ' . number_format($this->plan->total_amount, 2, ',', '.') . ' KY.')
            ->action('Visualizza dettagli', route('portal.payment-plans.index'))
            ->salutation('Il team KMoney');
    }
}
