<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Inviata al nuovo agente registrato direttamente da un agente referente
 * dalla propria dashboard (2026-07-28, "gli agenti sotto li registra
 * l'agente referente"). Contiene le credenziali di primo accesso (password
 * generata automaticamente da MlmPortalController::registraAgenteStore()) e
 * ricorda che l'attivazione come agente richiede comunque la firma del
 * contratto di nomina (OTP) — coerente col percorso classico di
 * MlmAgentContractController.
 *
 * 2026-07-31 (richiesta di Laura): oltre al link, l'email contiene ora in
 * allegato il PDF del contratto di nomina già compilato con i dati anagrafici
 * raccolti in fase di registrazione (vedi
 * MlmPortalController::buildAgentContractPreviewPdf()) — non ancora firmato,
 * solo una bozza da leggere con calma prima di firmarla con OTP nell'area
 * riservata.
 *
 * Email di sicurezza/credenziali: NON usa RespectsNotificationPreferences,
 * il destinatario deve poterla ricevere sempre per poter accedere al conto
 * appena creato (a differenza delle notifiche informative opzionali).
 */
class MlmAgentCreatedByReferrerNotification extends Notification
{
    public function __construct(
        public readonly string $temporaryPassword,
        public readonly User $referrerAgent,
        public readonly ?string $contractPdf = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject('Il tuo account KMoney è pronto — completa l\'attivazione come Agente KNM')
            ->greeting('Benvenuto in KMoney, ' . $notifiable->name . '!')
            ->line($this->referrerAgent->name . ' ti ha registrato come nuovo Agente KNM sul circuito KMoney.')
            ->line('Ecco le tue credenziali di primo accesso:')
            ->line('**Email:** ' . $notifiable->email)
            ->line('**Password:** ' . $this->temporaryPassword)
            ->line('Ti consigliamo di cambiarla dal tuo profilo dopo il primo accesso.')
            ->action('Accedi al tuo conto KMoney', route('login'))
            ->line('In allegato trovi il **contratto di nomina ad Agente KNM già compilato** con i dati che ' . $this->referrerAgent->name . ' ha inserito per te: leggilo con calma prima di firmarlo.')
            ->line('Per diventare Agente KNM attivo dovrai firmarlo digitalmente (con codice OTP inviato via email) dalla sezione "Contratto agente" del portale, subito dopo il primo accesso.')
            ->salutation('Il team KMoney');

        if ($this->contractPdf !== null) {
            $message->attachData(
                $this->contractPdf,
                'contratto-nomina-agente-knm.pdf',
                ['mime' => 'application/pdf'],
            );
        }

        return $message;
    }
}
