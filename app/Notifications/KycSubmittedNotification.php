<?php

namespace App\Notifications;

use App\Models\Company;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Avvisa un admin (super_admin) che un'azienda ha caricato i suoi documenti
 * KYC ed e' passata in "under_review": da quel momento la pratica sta ferma
 * finche' un admin non la apre. Inviata via email + notifica database (per la
 * campanella del pannello admin), sullo stesso schema di
 * MlmAgentRequestSubmittedNotification.
 *
 * Perche' esiste (04/09/2026): fino a oggi nessuno avvisava nessuno. Il
 * commento "// Prima volta: aggiorna lo stato KYC e notifica l'admin" in
 * OnboardingController::uploadKyc() prometteva questa notifica, ma la riga
 * non era mai stata scritta: le aziende restavano su /benvenuto/attesa a
 * tempo indeterminato e l'unico modo di accorgersene era aprire a mano
 * /admin/kyc. Vedi anche il contatore sulla dashboard admin, che e' la
 * seconda meta' dello stesso rimedio: la notifica avvisa una volta, il
 * contatore resta li' finche' la coda non e' vuota.
 */
class KycSubmittedNotification extends Notification
{
    public function __construct(
        public readonly Company $company,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $documentCount = $this->company->kycDocuments()->count();

        return (new MailMessage)
            ->subject('Nuova azienda da verificare: ' . $this->company->name)
            ->greeting('Nuova verifica KYC in attesa')
            ->line('L\'azienda "' . $this->company->name . '" ha caricato i documenti per la verifica KYC ed e\' in attesa di approvazione.')
            ->when((bool) $this->company->vat_number, fn (MailMessage $mail) => $mail->line('Partita IVA: ' . $this->company->vat_number))
            ->when((bool) $this->company->email, fn (MailMessage $mail) => $mail->line('Email azienda: ' . $this->company->email))
            ->line('Documenti caricati: ' . $documentCount . '.')
            ->action('Apri la pratica', route('admin.kyc.show', $this->company))
            ->line('Finche\' la pratica non viene approvata, l\'azienda resta ferma alla schermata di attesa e non puo\' operare nel circuito.')
            ->salutation('Il team KMoney');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'icon'  => '🪪',
            'title' => 'Nuova azienda da verificare',
            'body'  => $this->company->name . ' ha caricato i documenti KYC ed e\' in attesa di approvazione.',
            'link'  => route('admin.kyc.show', $this->company),
        ];
    }
}
