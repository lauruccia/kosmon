<?php

namespace App\Notifications;

use App\Notifications\Concerns\RespectsNotificationPreferences;

use App\Models\PaymentPlan;
use App\Models\PaymentPlanInstallment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class InstallmentFailedNotification extends Notification implements ShouldQueue
{
    use RespectsNotificationPreferences;

    use Queueable;

    public function __construct(
        public readonly PaymentPlanInstallment $installment,
        public readonly PaymentPlan            $plan,
        public readonly string                 $reason,
    ) {}

    public function via(object $notifiable): array
    {
        return $this->resolveChannels($notifiable, 'installment_failed', ['database', 'mail'], ['database', 'mail']);
    }

    public function toArray(object $notifiable): array
    {
        return [
            'icon'  => '⚠️',
            'title' => 'Rata non processata',
            'body'  => sprintf(
                'Rata %d/%d di %s KY non è stata pagata: %s',
                $this->installment->installment_number,
                $this->plan->installments_count,
                number_format($this->installment->amount, 2, ',', '.'),
                $this->reason,
            ),
            'link'  => route('portal.payment-plans.show', $this->plan),
        ];
    }
}
