<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Inviata all'utente quando l'admin approva o rifiuta la sua richiesta di
 * diventare Agente KNM.
 */
class MlmAgentRequestReviewedNotification extends Notification
{
    public function __construct(
        public readonly string  $decision, // 'approved' | 'rejected'
        public readonly ?string $reason = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        if ($this->decision === 'approved') {
            // Fin qui la mail diceva «firma il contratto» e il link
            // rimbalzava sulla quota: la persona scopriva di dover pagare
            // solo dopo aver cliccato. Ora la mail dice il passo vero, con
            // l'importo, e punta alla porta unica (02/09/2026).
            $quota = $this->quotaDovuta($notifiable);

            $mail = (new MailMessage)
                ->subject('La tua richiesta di diventare Agente KNM è stata approvata')
                ->greeting('Ottime notizie, ' . $notifiable->name . '!')
                ->line('La tua richiesta di diventare Agente KNM è stata approvata.');

            if ($quota > 0) {
                return $mail
                    ->line('Restano due passi: la quota per il codice agente di '
                        . number_format($quota / 100, 2, ',', '.') . ' €, e poi la firma digitale del contratto di nomina.')
                    ->action('Continua', route('portal.mlm.percorso'))
                    ->salutation('Il team KMoney');
            }

            return $mail
                ->line('Per completare l\'attivazione devi firmare digitalmente il contratto di nomina ad agente.')
                ->action('Firma il contratto', route('portal.mlm.percorso'))
                ->salutation('Il team KMoney');
        }

        return (new MailMessage)
            ->subject('La tua richiesta di diventare Agente KNM')
            ->greeting('Ciao ' . $notifiable->name . ',')
            ->line('Purtroppo la tua richiesta di diventare Agente KNM non è stata approvata al momento.')
            ->when($this->reason, fn ($mail) => $mail->line('Motivo: ' . $this->reason))
            ->line('Puoi ripresentare la richiesta in qualsiasi momento dal tuo profilo.')
            ->action('Vai al portale', route('portal.mlm.agent-request.show'))
            ->salutation('Il team KMoney');
    }

    /**
     * Quanto deve ancora, in centesimi, per il codice agente — zero se non
     * deve niente (interruttore spento, o esonerato).
     *
     * Rilegge l'utente dal database: questa notifica parte subito dopo che
     * l'approvazione ha scritto la quota, e il modello che arriva qui e'
     * quello di prima.
     */
    private function quotaDovuta(object $notifiable): int
    {
        $user = $notifiable instanceof \App\Models\User ? ($notifiable->fresh() ?? $notifiable) : null;

        $quote = app(\App\Services\AgentCodeFeeService::class);

        return $quote->isDueFor($user) ? $quote->amountDueFor($user) : 0;
    }

    public function toArray(object $notifiable): array
    {
        if ($this->decision === 'approved') {
            $quota = $this->quotaDovuta($notifiable);

            return [
                'icon'  => '✅',
                'title' => 'Richiesta agente approvata',
                'body'  => $quota > 0
                    ? 'Salda la quota per il codice agente di ' . number_format($quota / 100, 2, ',', '.') . ' €, poi firma il contratto di nomina.'
                    : 'Firma il contratto di nomina per attivare il tuo profilo agente.',
                'link'  => route('portal.mlm.percorso'),
            ];
        }

        return [
            'icon'  => '❌',
            'title' => 'Richiesta agente non approvata',
            'body'  => $this->reason ?: 'Puoi ripresentare la richiesta in qualsiasi momento.',
            'link'  => route('portal.mlm.agent-request.show'),
        ];
    }
}
