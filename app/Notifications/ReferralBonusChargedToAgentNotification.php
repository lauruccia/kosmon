<?php

namespace App\Notifications;

use App\Models\Transfer;
use App\Models\User;
use App\Notifications\Concerns\RespectsNotificationPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Notifica all'AGENTE quando il suo conto viene addebitato per pagare il
 * bonus segnalazione (10 KY amico / 50 KY agente) di un proprio cliente
 * (vedi ReferralBonusService::fundingAccountFor(), decisione Laura del
 * 28/07/2026: questi due livelli li paga l'agente di riferimento del
 * cliente segnalante, non il conto madre).
 *
 * L'addebito avviene SEMPRE, anche oltre il fido configurato (l'initiator è
 * un super admin che bypassa il controllo in
 * TransferBookingService::assertTransferWithinLimits()), quindi qui
 * avvisiamo l'agente — soprattutto se il saldo dopo l'addebito è negativo —
 * di ricaricare per coprire l'esposizione.
 *
 * $client è il cliente segnalante (proprio cliente) che ha incassato il
 * bonus, $referredUser è la persona che il cliente ha segnalato.
 */
class ReferralBonusChargedToAgentNotification extends Notification
{
    use RespectsNotificationPreferences;
    use Queueable;

    private const TIER_LABELS = [
        'amico'  => 'amico',
        'agente' => 'agente',
    ];

    public function __construct(
        public readonly Transfer $transfer,
        public readonly User $client,
        public readonly User $referredUser,
        public readonly string $tier,
        public readonly int $amount,
        public readonly int $balanceAfter,
    ) {}

    public function via(object $notifiable): array
    {
        return $this->resolveChannels($notifiable, 'referral_bonus_agent_debit', ['database', 'mail'], ['database', 'mail']);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $formatted = ky_format($this->amount);
        $tierLabel = self::TIER_LABELS[$this->tier] ?? $this->tier;
        $balance   = ky_format($this->balanceAfter);

        $message = (new MailMessage)
            ->subject("Bonus segnalazione del tuo cliente: addebitati {$formatted} KY")
            ->greeting("Ciao {$notifiable->name},")
            ->line("Il tuo cliente **{$this->client->name}** ha segnalato **{$this->referredUser->name}**, che si è registrato/attivato come *{$tierLabel}* nel circuito KMoney.")
            ->line("Come da regolamento, il bonus di **{$formatted} KY** spettante al tuo cliente è stato addebitato dal tuo conto agente.")
            ->line("Rif. movimento: {$this->transfer->reference}")
            ->line("Saldo attuale del tuo conto: **{$balance} KY**.");

        if ($this->balanceAfter < 0) {
            $message->line('Il tuo conto è in scoperto: ricarica per rientrare nel fido concesso.')
                ->action('Ricarica ora', route('portal.ky-cards.index'));
        } else {
            $message->action('Vai ai movimenti', url('/movimenti'));
        }

        return $message;
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'             => 'referral_bonus_agent_debit',
            'transfer_id'      => $this->transfer->id,
            'reference'        => $this->transfer->reference,
            'client_id'        => $this->client->id,
            'client_name'      => $this->client->name,
            'referred_user_id' => $this->referredUser->id,
            'referred_user_name' => $this->referredUser->name,
            'tier'             => $this->tier,
            'amount'           => $this->amount,
            'balance_after'    => $this->balanceAfter,
            'booked_at'        => $this->transfer->booked_at?->toIso8601String(),
        ];
    }
}
