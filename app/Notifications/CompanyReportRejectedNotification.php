<?php

namespace App\Notifications;

use App\Models\CompanyReport;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Avvisa il CLIENTE segnalante che l'azienda da lui segnalata non ha
 * portato a un contratto (feature "segnalazione azienda", 29/07/2026 —
 * vedi CompanyReportService::markRejected()). Include la nota lasciata
 * dall'agente, se presente.
 */
class CompanyReportRejectedNotification extends Notification
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
        return (new MailMessage)
            ->subject('Segnalazione azienda non andata a buon fine: ' . $this->report->company_name)
            ->greeting('Ciao ' . $notifiable->name . ',')
            ->line('Purtroppo la segnalazione dell\'azienda "' . $this->report->company_name . '" non ha portato alla firma di un contratto.')
            ->when($this->report->agent_notes, fn (MailMessage $mail) => $mail->line('Nota dell\'agente: ' . $this->report->agent_notes))
            ->line('Continua a segnalarci le attività dove vorresti spendere i tuoi KY: ogni segnalazione ci aiuta a far crescere il circuito.')
            ->action('Segnala un\'altra azienda', route('portal.company-reports.index'))
            ->salutation('Il team KMoney');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'icon'  => '❌',
            'title' => 'Segnalazione non andata a buon fine',
            'body'  => 'La segnalazione di "' . $this->report->company_name . '" non ha portato alla firma di un contratto.',
            'link'  => route('portal.company-reports.index'),
        ];
    }
}
