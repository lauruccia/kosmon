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
    use RespectsNotificationPreferences;

    public function __construct(
        public readonly PaymentPlan $plan,
        public readonly bool        $isApprover = false,
    ) {}

    public function via(object $notifiable): array
    {
        return $this->resolveChannels($notifiable, 'payment_plan_approved', ['database', 'mail'], ['database', 'mail']);
    }

    public function toArray(object $notifiable): array
    {
        $total = ky_format($this->plan->total_amount);
        $count = $this->plan->installments_count;
        $freq  = $this->plan->frequencyLabel();

        if ($this->isApprover) {
            $counterparty = $this->plan->proposerAccount()?->display_name ?? 'La controparte';
            $body = 'Hai accettato il piano rateale di ' . $counterparty . ': ' . $total . ' KY in ' . $count . ' rate ' . $freq . 'i. I pagamenti partiranno alla prima scadenza.';
        } else {
            $counterparty = $this->plan->counterpartyAccount()?->display_name ?? 'La controparte';
            $body = $counterparty . ' ha accettato il tuo piano rateale di ' . $total . ' KY in ' . $count . ' rate ' . $freq . 'i. I pagamenti partiranno alla prima scadenza.';
        }

        return [
            'icon'  => '✅',
            'title' => 'Piano rateale approvato',
            'body'  => $body,
            'link'  => route('portal.payment-plans.show', $this->plan),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $total = ky_format($this->plan->total_amount);
        $count = $this->plan->installments_count;
        $freq  = $this->plan->frequencyLabel();
        $first = $this->plan->first_due_date?->format('d/m/Y') ?? '—';

        if ($this->isApprover) {
            $counterparty = $this->plan->proposerAccount()?->display_name ?? 'La controparte';
            $intro = 'Hai accettato il piano rateale di ' . $counterparty . ': ' . $total . ' KY in ' . $count . ' rate ' . $freq . 'i.';
        } else {
            $counterparty = $this->plan->counterpartyAccount()?->display_name ?? 'La controparte';
            $intro = $counterparty . ' ha accettato il tuo piano rateale di ' . $total . ' KY in ' . $count . ' rate ' . $freq . 'i.';
        }

        return (new MailMessage)
            ->subject('Piano rateale approvato — ' . $total . ' KY')
            ->greeting('Piano rateale approvato ✅')
            ->line($intro)
            ->line('Prima rata: **' . $first . '**.')
            ->action('Visualizza piano', route('portal.payment-plans.show', $this->plan))
            ->salutation('Il team KMoney');
    }
}
