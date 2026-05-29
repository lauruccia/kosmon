<?php

namespace App\Notifications;

use App\Models\PaymentPlan;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class PaymentPlanRejectedNotification extends Notification implements ShouldQueue
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

        return [
            'icon'  => '❌',
            'title' => 'Piano rateale rifiutato',
            'body'  => $counterparty . ' ha rifiutato la tua proposta di piano rateale da ' . $total . ' KY. Puoi proporne uno nuovo con condizioni diverse.',
            'link'  => route('portal.payment-plans.index'),
        ];
    }
}
