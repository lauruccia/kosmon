<?php

namespace App\Notifications;

use App\Models\CompanyReport;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Avvisa che un cliente ha segnalato una nuova azienda (feature
 * "segnalazione azienda" richiesta da Laura il 29/07/2026, vedi
 * CompanyReportService::submit()). Usata per DUE destinatari diversi con
 * lo stesso contenuto: l'agente di riferimento del cliente (che deve
 * valutare la segnalazione) e, in copia/visibilita', tutti gli admin
 * (vedi NotifiesAdmins::notifyAdminsOfCompanyReport()) — il link punta
 * sempre alla pagina agente per semplicita', coerente con l'istruzione di
 * non creare link diversi per destinatario.
 */
class CompanyReportSubmittedNotification extends Notification
{
    public function __construct(
        public readonly CompanyReport $report,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $reporter = $this->report->reporter;
        $agent = $this->report->agent;

        $contactParts = array_filter([
            $this->report->contact_name,
            $this->report->contact_phone,
            $this->report->contact_email,
        ]);

        return (new MailMessage)
            ->subject('Nuova segnalazione azienda: ' . $this->report->company_name)
            ->greeting('Nuova segnalazione azienda')
            ->line(($reporter->name ?? 'Un cliente') . ' (' . ($reporter->email ?? '—') . ') ha segnalato l\'azienda "' . $this->report->company_name . '"' . ($this->report->company_city ? ' (' . $this->report->company_city . ')' : '') . '.')
            ->when($agent, fn (MailMessage $mail) => $mail->line('Agente di riferimento: ' . $agent->name))
            ->when($this->report->company_sector, fn (MailMessage $mail) => $mail->line('Settore/categoria: ' . $this->report->company_sector))
            ->when($this->report->knowledge_level, fn (MailMessage $mail) => $mail->line('Grado di conoscenza: ' . $this->report->knowledgeLevelLabel()))
            ->when($this->report->company_notes, fn (MailMessage $mail) => $mail->line('Note: ' . $this->report->company_notes))
            ->when(count($contactParts) > 0, fn (MailMessage $mail) => $mail->line('Referente aziendale: ' . implode(' — ', $contactParts)))
            ->action('Vai alle segnalazioni', route('portal.mlm.company-reports.index'))
            ->line('Se riesci a firmare un contratto con questa azienda, segnalalo dal pannello: il cliente riceverà automaticamente il bonus KY previsto.')
            ->salutation('Il team KMoney');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'icon'  => '🏢',
            'title' => 'Nuova segnalazione azienda',
            'body'  => ($this->report->reporter->name ?? 'Un cliente') . ' ha segnalato "' . $this->report->company_name . '".',
            'link'  => route('portal.mlm.company-reports.index'),
        ];
    }
}
