<?php

namespace App\Notifications;

use App\Models\Account;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MonthlyStatementNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Account $account,
        public readonly array   $data,
    ) {}

    public function via(object $notifiable): array
    {
        $prefs = $notifiable->notification_preferences ?? [];
        $channels = $prefs['monthly_statement'] ?? ['mail'];
        return array_values(array_intersect(['mail', 'database'], $channels));
    }

    public function toMail(object $notifiable): MailMessage
    {
        $branding  = \App\Models\SystemSetting::branding();
        $name      = $this->account->company?->name ?? $notifiable->name;
        $month     = $this->data['month_label'];
        $income    = ky_format($this->data['income']);
        $expense   = ky_format($this->data['expense']);
        $balance   = ky_format($this->data['balance']);
        $dueSoon   = $this->data['due_installments'];

        $mail = (new MailMessage)
            ->subject("[{$branding->circuit_name}] Resoconto {$month} per {$name}")
            ->greeting("Ciao {$name},")
            ->line("Ecco il riepilogo del tuo conto per **{$month}**:")
            ->line("**Saldo attuale:** {$balance} KY")
            ->line("**Entrate del mese:** +{$income} KY")
            ->line("**Uscite del mese:** -{$expense} KY");

        if ($dueSoon > 0) {
            $mail->line("**Rate in scadenza (prossimi 7gg):** {$dueSoon}");
        }

        return $mail
            ->action('Vai al portale', route('portal.dashboard'))
            ->line('Per disattivare questa email, modifica le preferenze notifiche nel portale.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'icon'  => '📊',
            'title' => 'Resoconto mensile ' . $this->data['month_label'],
            'body'  => sprintf('Saldo: %s KY | Entrate: +%s KY | Uscite: -%s KY',
                ky_format($this->data['balance']),
                ky_format($this->data['income']),
                ky_format($this->data['expense'])),
            'link'  => route('portal.movements'),
        ];
    }
}
