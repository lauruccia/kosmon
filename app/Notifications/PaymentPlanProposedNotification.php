<?php

namespace App\Notifications;

use App\Models\PaymentPlan;
use App\Notifications\Concerns\RespectsNotificationPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PaymentPlanProposedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly PaymentPlan $plan) {}

    use RespectsNotificationPreferences;

    public function via(object $notifiable): array
    {
        return $this->resolveChannels($notifiable, 'payment_plan_proposed', ['database', 'mail'], ['database', 'mail']);
    }

    public function toArray(object $notifiable): array
    {
        $proposer = $this->plan->proposerAccount()?->display_name ?? 'Un\'azienda';
        $total    = ky_format($this->plan->total_amount);
        $count    = $this->plan->installments_count;
        $freq     = $this->plan->frequencyLabel();

        if ($this->plan->initiator_role === 'creditor') {
            $body = $proposer . ' ti propone di pagare ' . $total . ' KY in ' . $count . ' rate ' . $freq . 'i. Accetta o rifiuta la proposta.';
        } else {
            $body = $proposer . ' chiede di pagare ' . $total . ' KY in ' . $count . ' rate ' . $freq . 'i. Accetta o rifiuta la proposta.';
        }

        return [
            'icon'  => '📅',
            'title' => 'Proposta piano rateale',
            'body'  => $body,
            'link'  => route('portal.payment-plans.show', $this->plan),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Proposta piano rateale da ' . ($this->plan->proposerAccount()?->display_name ?? 'Un azienda'))
            ->greeting('Piano rateale proposto')
            ->line(($this->plan->proposerAccount()?->display_name ?? 'Un azienda') . ' propone di pagare ' . ky_format($this->plan->total_amount) . ' KY in ' . $this->plan->installments_count . ' rate.')
            ->action('Visualizza proposta', route('portal.payment-plans.show', $this->plan))
            ->salutation('Il team KMoney');
    }
}
