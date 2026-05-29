<?php

namespace App\Notifications;

use App\Models\PaymentPlan;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class PaymentPlanApprovedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly PaymentPlan $plan) {}

    public function via(object $notifiable): array
    {
        return ['database'];
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
}
