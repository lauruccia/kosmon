<?php

namespace App\Notifications;

use App\Notifications\Concerns\RespectsNotificationPreferences;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Inviata all'agente quando il ricalcolo notturno lo retrocede di qualifica
 * (es. punti scaduti nel ledger o requisiti di struttura venuti meno).
 * Introdotta col sistema di retrocessione del 2026-07-13.
 */
class MlmRankDemotedNotification extends Notification
{
    use RespectsNotificationPreferences;

    private const RANK_LABELS = [
        'start' => 'Start',
        'basic' => 'Basic',
        'key' => 'Key',
        'senior' => 'Senior',
        'top' => 'Top',
        'supervisor' => 'SuperVisor',
        'manager' => 'Manager',
    ];

    public function __construct(
        private readonly string $previousRank,
        private readonly string $newRank,
    ) {}

    public function via(object $notifiable): array
    {
        return $this->resolveChannels($notifiable, 'mlm_agent_status', ['mail', 'database'], ['mail', 'database']);
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('La tua qualifica KNM è cambiata')
            ->greeting('Ciao ' . $notifiable->name . ',')
            ->line('La tua qualifica è passata da ' . $this->label($this->previousRank) . ' a ' . $this->label($this->newRank) . '.')
            ->line('Questo succede quando alcuni punti raggiungono la loro scadenza o i requisiti di struttura non sono più soddisfatti. Nessun guadagno già maturato viene toccato.')
            ->line('Nel portale trovi la checklist con ciò che ti manca per recuperare la qualifica.')
            ->action('Vai alla mia struttura', route('portal.mlm.struttura'))
            ->salutation('Il team KMoney');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'icon'  => '📉',
            'title' => 'Qualifica aggiornata: ' . $this->label($this->newRank),
            'body'  => 'La tua qualifica è passata da ' . $this->label($this->previousRank) . ' a ' . $this->label($this->newRank) . ' per punti scaduti o requisiti non più soddisfatti.',
            'link'  => route('portal.mlm.struttura'),
        ];
    }

    private function label(string $rank): string
    {
        return self::RANK_LABELS[$rank] ?? ucfirst($rank);
    }
}
