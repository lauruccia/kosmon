<?php

namespace App\Notifications;

use App\Models\Transfer;
use App\Models\User;
use App\Notifications\Concerns\RespectsNotificationPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Notifica al SEGNALANTE quando riceve un bonus KY per una segnalazione
 * (punto 3 del 27/07, vedi ReferralBonusService). $referredUser è la
 * persona segnalata che ha fatto scattare il bonus, $tier è il livello
 * raggiunto ('amico'|'agente'|'attivita').
 */
class ReferralBonusReceivedNotification extends Notification
{
    use RespectsNotificationPreferences;
    use Queueable;

    private const TIER_LABELS = [
        'amico'    => 'amico',
        'agente'   => 'agente',
        'attivita' => 'attività',
    ];

    public function __construct(
        public readonly Transfer $transfer,
        public readonly User $referredUser,
        public readonly string $tier,
        public readonly int $amount,
    ) {}

    public function via(object $notifiable): array
    {
        return $this->resolveChannels($notifiable, 'referral_bonus_received', ['database', 'mail'], ['database', 'mail']);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $formatted  = ky_format($this->amount);
        $tierLabel  = self::TIER_LABELS[$this->tier] ?? $this->tier;

        return (new MailMessage)
            ->subject("Hai ricevuto un bonus segnalazione di {$formatted} KY!")
            ->greeting("Ciao {$notifiable->name},")
            ->line("La persona che hai segnalato, **{$this->referredUser->name}**, si è registrata/attivata come *{$tierLabel}* nel circuito KMoney.")
            ->line("Come ringraziamento hai ricevuto un bonus di **{$formatted} KY** sul tuo conto.")
            ->line("Rif. movimento: {$this->transfer->reference}")
            ->action('Vai ai movimenti', url('/movimenti'))
            ->line('Continua a invitare amici, agenti e attività dalla tua pagina "Invita un amico".');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'              => 'referral_bonus_received',
            'transfer_id'       => $this->transfer->id,
            'reference'         => $this->transfer->reference,
            'referred_user_id'  => $this->referredUser->id,
            'referred_user_name'=> $this->referredUser->name,
            'tier'              => $this->tier,
            'amount'            => $this->amount,
            'booked_at'         => $this->transfer->booked_at?->toIso8601String(),
        ];
    }
}
