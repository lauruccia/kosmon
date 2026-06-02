<?php

namespace App\Notifications;

use App\Models\PaymentPlan;
use App\Notifications\Concerns\RespectsNotificationPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PaymentPlanApprovedNotification extends Notification implements ShouldQueue
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
        $count        = $this->plan->installments_count;

        return [
            'icon'  => '✅',
            'title' => 'Piano rateale approvato',
            'body'  => $counterparty . ' ha accettato il tuo piano rateale di ' . $total . ' KY in ' . $count . ' rate. Le rate partiranno dalla prima scadenza.',
            'link'  => route('portal.payment-plans.show', $this->plan),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Piano rateale approvato — ' . number_format($this->plan->total_amount, 2, ',', '.') . ' KY')
            ->greeting('Piano rateale approvato')
            ->line(($this->plan->counterpartyAccount()?->display_name ?? 'La controparte') . ' ha accettato il piano rateale di ' . number_format($this->plan->total_amount, 2, ',', '.') . ' KY in ' . $this->plan->installments_count . ' rate.')
            ->action('Visualizza piano', route('portal.payment-plans.show', $this->plan))
            ->salutation('Il team KMoney');
    }
}
