<?php

namespace App\Notifications;

use App\Notifications\Concerns\RespectsNotificationPreferences;

use App\Models\PaymentPlan;
use App\Models\PaymentPlanInstallment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class InstallmentPaidNotification extends Notification implements ShouldQueue
{
    use RespectsNotificationPreferences;

    use Queueable;

    public function __construct(
        public readonly PaymentPlanInstallment $installment,
        public readonly PaymentPlan            $plan,
    ) {}

    public function via(object $notifiable): array
    {
        return $this->resolveChannels($notifiable, 'installment_paid', ['database'], ['database']);
    }

    public function toArray(object $notifiable): array
    {
        return [
            'icon'  => '📅',
            'title' => 'Rata pagata',
            'body'  => sprintf(
                'Rata %d/%d di %s KY contabilizzata. Piano: %s.',
                $this->installment->installment_number,
                $this->plan->installments_count,
                number_format($this->installment->amount, 2, ',', '.'),
                $this->plan->description ?? 'Piano rateale',
            ),
            'link'  => route('portal.payment-plans.show', $this->plan),
        ];
    }
}
