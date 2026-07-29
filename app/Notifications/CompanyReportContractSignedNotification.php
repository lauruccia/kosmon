<?php

namespace App\Notifications;

use App\Models\CompanyReport;
use App\Models\Transfer;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Avvisa il CLIENTE segnalante che l'agente ha firmato un contratto con
 * l'azienda da lui segnalata (feature "segnalazione azienda", 29/07/2026 —
 * vedi CompanyReportService::markContractSigned()). $bonusTransfer e' il
 * movimento del bonus KY appena accreditato, oppure null se il bonus era
 * disabilitato (importo a 0) — in quel caso si annuncia solo l'esito
 * positivo della segnalazione, senza la riga del bonus.
 */
class CompanyReportContractSignedNotification extends Notification
{
    public function __construct(
        public readonly CompanyReport $report,
        public readonly ?Transfer $bonusTransfer,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('Contratto firmato con ' . $this->report->company_name . '!')
            ->greeting('Ottima notizia, ' . $notifiable->name . '!')
            ->line('L\'azienda che hai segnalato, "' . $this->report->company_name . '", ha firmato un contratto di adesione al circuito KMoney.');

        if ($this->bonusTransfer) {
            $mail->line('Come ringraziamento hai ricevuto un bonus di **' . ky_format($this->bonusTransfer->amount) . ' KY** sul tuo conto.')
                ->line('Rif. movimento: ' . $this->bonusTransfer->reference);
        }

        return $mail->action('Vai ai movimenti', url('/movimenti'))
            ->line('Grazie per aver contribuito a far crescere il circuito KMoney!')
            ->salutation('Il team KMoney');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'icon'  => '🎉',
            'title' => 'Contratto firmato: ' . $this->report->company_name,
            'body'  => $this->bonusTransfer
                ? 'Hai ricevuto un bonus di ' . ky_format($this->bonusTransfer->amount) . ' KY per la tua segnalazione.'
                : 'La tua segnalazione ha portato alla firma di un contratto.',
            'link'  => url('/movimenti'),
        ];
    }
}
