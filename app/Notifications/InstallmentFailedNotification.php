<?php

namespace App\Notifications;

use App\Models\PaymentPlan;
use App\Models\PaymentPlanInstallment;
use App\Notifications\Concerns\RespectsNotificationPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class InstallmentFailedNotification extends Notification implements ShouldQueue
{
    use Queueable;
    use RespectsNotificationPreferences;

    public function __construct(
        public readonly PaymentPlanInstallment $installment,
        public readonly PaymentPlan            $plan,
        public readonly string                 $reason,
        /** True se il destinatario è il creditore (toAccount), false se è il debitore (fromAccount). */
        public readonly bool                   $isCreditor = false,
    ) {}

    public function via(object $notifiable): array
    {
        return $this->resolveChannels($notifiable, 'installment_failed', ['database', 'mail'], ['database', 'mail']);
    }

    public function toArray(object $notifiable): array
    {
        $num    = $this->installment->installment_number;
        $total  = $this->plan->installments_count;
        $amount = ky_format($this->installment->amount);

        if ($this->isCreditor) {
            $body = sprintf(
                'La rata %d/%d di %s KY da %s non è stata incassata: %s',
                $num,
                $total,
                $amount,
                $this->plan->fromAccount?->display_name ?? 'la controparte',
                $this->reason,
            );
        } else {
            $body = sprintf(
                'Rata %d/%d di %s KY non è stata pagata: %s',
                $num,
                $total,
                $amount,
                $this->reason,
            );
        }

        return [
            'icon'  => '⚠️',
            'title' => 'Rata non processata',
            'body'  => $body,
            'link'  => route('portal.payment-plans.show', $this->plan),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $num    = $this->installment->installment_number;
        $total  = $this->plan->installments_count;
        $amount = ky_format($this->installment->amount);

        if ($this->isCreditor) {
            $subject = 'Rata ' . $num . '/' . $total . ' non incassata';
            $intro   = 'La rata ' . $num . ' di ' . $total . ' da ' . $amount . ' KY (da ' . ($this->plan->fromAccount?->display_name ?? 'la controparte') . ') non è stata incassata.';
        } else {
            $subject = 'Rata ' . $num . '/' . $total . ' non eseguita';
            $intro   = 'La rata ' . $num . ' di ' . $total . ' da ' . $amount . ' KY non è stata pagata automaticamente.';
        }

        return (new MailMessage)
            ->subject($subject)
            ->greeting('⚠️ Rata non processata')
            ->line($intro)
            ->line('Motivo: ' . $this->reason)
            ->action('Visualizza piano rateale', route('portal.payment-plans.show', $this->plan))
            ->salutation('Il team KMoney');
    }
}
