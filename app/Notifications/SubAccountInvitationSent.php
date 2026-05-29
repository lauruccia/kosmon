<?php

namespace App\Notifications;

use App\Models\Account;
use App\Models\SubAccountInvitation;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SubAccountInvitationSent extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly SubAccountInvitation $invitation,
        public readonly Account $subAccount,
        public readonly User $invitedBy,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $accountName = $this->subAccount->account_name ?? $this->subAccount->display_name;
        $ownerName   = $this->subAccount->parentAccount?->company?->name
            ?? $this->invitedBy->name;
        $url = route('subaccount.invitation.show', $this->invitation->token);

        return (new MailMessage)
            ->subject("Invito: gestisci il sottoconto \"{$accountName}\"")
            ->greeting("Ciao!")
            ->line("{$ownerName} ti ha invitato a gestire il sottoconto **\"{$accountName}\"** nel circuito KMoney.")
            ->line("Tramite questo sottoconto potrai effettuare pagamenti in KY nei limiti stabiliti.")
            ->action('Accetta invito e registrati', $url)
            ->line("L'invito scade il " . $this->invitation->expires_at->format('d/m/Y') . '.')
            ->line("Se non conosci {$ownerName} o hai ricevuto questo messaggio per errore, puoi ignorarlo.");
    }
}
