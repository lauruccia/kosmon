<?php

namespace App\Notifications;

use App\Models\Account;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SubAccountAccessRevoked extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Account $subAccount,
        public readonly User $revokedBy,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $accountName = $this->subAccount->account_name ?? $this->subAccount->display_name;
        $ownerName   = $this->subAccount->parentAccount?->company?->name ?? $this->revokedBy->name;

        return (new MailMessage)
            ->subject("Accesso revocato: \"{$accountName}\"")
            ->greeting("Ciao {$notifiable->name},")
            ->line("{$ownerName} ha revocato il tuo accesso al sottoconto **\"{$accountName}\"**.")
            ->line("Non potrai piu operare su questo sottoconto dal tuo portale KMoney.");
    }

    public function toArray(object $notifiable): array
    {
        return [
            'message'        => "Accesso al sottoconto \"{$this->subAccount->account_name}\" revocato.",
            'sub_account_id' => $this->subAccount->id,
        ];
    }
}
