<?php

namespace App\Notifications;

use App\Models\PaymentPlan;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class PaymentPlanProposedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly PaymentPlan $plan) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $proposer = $this->plan->proposerAccount()?->display_name ?? 'Un\'azienda';
        $total    = number_format($this->plan->total_amount, 2, ',', '.');
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
}
