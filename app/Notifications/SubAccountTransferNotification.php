<?php

namespace App\Notifications;

use App\Models\Account;
use App\Models\Transfer;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Notifica al titolare del conto padre ogni volta che un sottoconto
 * effettua un pagamento. Viene inviata al owner_user dell'account padre.
 */
class SubAccountTransferNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Transfer $transfer,
        public readonly Account  $subAccount,
        public readonly Account  $parentAccount,
        public readonly User     $initiator,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $subName    = $this->subAccount->account_name ?? $this->subAccount->display_name;
        $toName     = $this->transfer->toAccount?->company?->name
                   ?? $this->transfer->toAccount?->display_name
                   ?? '—';
        $amount     = ky_format($this->transfer->amount);
        $initiator  = $this->initiator->name;

        return (new MailMessage)
            ->subject("Pagamento da sottoconto «{$subName}»: {$amount} KY")
            ->greeting("Ciao {$notifiable->name},")
            ->line("Il sottoconto **«{$subName}»** ha effettuato un pagamento.")
            ->line("**Importo:** {$amount} KY")
            ->line("**Destinatario:** {$toName}")
            ->line("**Operato da:** {$initiator}")
            ->when($this->transfer->description, fn ($m) =>
                $m->line("**Causale:** {$this->transfer->description}")
            )
            ->action('Vedi movimenti', route('portal.movements'))
            ->line("Puoi vedere tutti i movimenti dei tuoi sottoconti dalla sezione Movimenti del portale.");
    }

    public function toArray(object $notifiable): array
    {
        $subName = $this->subAccount->account_name ?? $this->subAccount->display_name;
        $toName  = $this->transfer->toAccount?->company?->name
                ?? $this->transfer->toAccount?->display_name
                ?? '—';

        return [
            'type'           => 'subaccount_transfer',
            'message'        => "Pagamento da «{$subName}» a {$toName}: " . ky_format($this->transfer->amount) . ' KY',
            'sub_account_id' => $this->subAccount->id,
            'transfer_id'    => $this->transfer->id,
            'amount'         => $this->transfer->amount,
            'initiator_name' => $this->initiator->name,
            'action_url'     => route('portal.movements'),
        ];
    }
}
