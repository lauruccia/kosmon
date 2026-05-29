<?php

namespace App\Notifications;

use App\Models\BalanceAlert;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;

class BalanceThresholdNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly BalanceAlert $alert,
        public readonly int $currentBalance  // centesimi
    ) {}

    public function via(mixed $notifiable): array
    {
        $channels = [];
        if ($this->alert->notify_inapp) {
            $channels[] = 'database';
        }
        if ($this->alert->notify_email) {
            $channels[] = 'mail';
        }
        return $channels ?: ['database'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $threshold = $this->alert->thresholdFormatted();
        $current   = number_format($this->currentBalance / 100, 2, ',', '.') . ' KY';

        return (new MailMessage)
            ->subject("Avviso saldo KY — sotto soglia {$threshold}")
            ->view('emails.balance-alert', [
                'alert'          => $this->alert,
                'threshold'      => $threshold,
                'currentBalance' => $current,
                'notifiable'     => $notifiable,
            ]);
    }

    public function toArray(mixed $notifiable): array
    {
        $threshold = $this->alert->thresholdFormatted();
        $current   = number_format($this->currentBalance / 100, 2, ',', '.') . ' KY';

        return [
            'type'  => 'balance_alert',
            'title' => 'Saldo sotto soglia',
            'body'  => "Il saldo ({$current}) e' sceso sotto la soglia impostata di {$threshold}.",
            'icon'  => 'alert',
            'url'   => route('portal.dashboard'),
        ];
    }
}
