<?php

namespace App\Notifications;

use App\Models\Account;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SubAccountAccessGranted extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Account $subAccount,
        public readonly User $grantedBy,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $accountName = $this->subAccount->account_name ?? $this->subAccount->display_name;
        $ownerName   = $this->subAccount->parentAccount?->company?->name ?? $this->grantedBy->name;
        $url = route('subaccount.invitation.accept-existing', $this->subAccount->id);

        return (new MailMessage)
            ->subject("Accesso sottoconto: \"{$accountName}\"")
            ->greeting("Ciao {$notifiable->name}!")
            ->line("{$ownerName} ti ha assegnato la gestione del sottoconto **\"{$accountName}\"**.")
            ->action('Accetta accesso', $url)
            ->line("Dopo aver accettato potrai passare a questo sottoconto dal tuo portale KMoney.");
    }

    public function toArray(object $notifiable): array
    {
        return [
            'message'        => "Accesso al sottoconto \"{$this->subAccount->account_name}\" da parte di {$this->grantedBy->name}.",
            'sub_account_id' => $this->subAccount->id,
            'action_url'     => route('subaccount.invitation.accept-existing', $this->subAccount->id),
        ];
    }
}
