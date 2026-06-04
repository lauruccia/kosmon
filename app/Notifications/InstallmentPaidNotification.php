<?php

namespace App\Notifications;

use App\Notifications\Concerns\RespectsNotificationPreferences;

use App\Models\PaymentPlan;
use App\Models\PaymentPlanInstallment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
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
        return $this->resolveChannels($notifiable, 'installment_paid', ['database', 'mail'], ['database', 'mail']);
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
                ky_format($this->installment->amount),
                $this->plan->description ?? 'Piano rateale',
            ),
            'link'  => route('portal.payment-plans.show', $this->plan),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Rata ' . $this->installment->installment_number . '/' . $this->plan->installments_count . ' eseguita')
            ->greeting('Rata contabilizzata')
            ->line('Rata ' . $this->installment->installment_number . ' di ' . $this->plan->installments_count . ' da ' . ky_format($this->installment->amount) . ' KY contabilizzata con successo.')
            ->lineIf($this->installment->installment_number === $this->plan->installments_count, 'Piano rateale completato!')
            ->action('Visualizza piano', route('portal.payment-plans.show', $this->plan))
            ->salutation('Il team KMoney');
    }
}
