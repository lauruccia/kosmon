<?php

namespace App\Notifications\Concerns;

use App\Models\Company;
use App\Models\CompanyReport;
use App\Models\User;
use App\Notifications\CompanyReportSubmittedNotification;
use App\Notifications\KycSubmittedNotification;
use App\Notifications\MlmAgentRequestSubmittedNotification;

/**
 * Helper condiviso per avvisare gli admin (super_admin) di eventi che
 * richiedono la loro attenzione. Usato dal flusso "richiesta agente KNM"
 * e, dal 29/07/2026, dal flusso "segnalazione azienda" (vedi
 * CompanyReportService::submit()) — per quest'ultimo gli admin sono
 * sempre e solo in copia/visibilita', mai un gate di approvazione.
 *
 * Dal 04/09/2026 c'e' anche il flusso KYC (vedi notifyAdminsOfKycSubmission()),
 * e quello invece e' un vero gate: se nessun admin apre la pratica, l'azienda
 * resta bloccata sulla schermata di attesa.
 */
class NotifiesAdmins
{
    public static function notifyAdminsOfMlmAgentRequest(User $requester): void
    {
        $admins = User::where('is_super_admin', true)->where('is_active', true)->get();

        $notification = new MlmAgentRequestSubmittedNotification($requester);

        foreach ($admins as $admin) {
            if ($admin->email) {
                $admin->notify($notification);
            }
        }
    }

    /**
     * Un'azienda ha caricato i primi documenti KYC ed e' passata in
     * "under_review". A differenza degli altri due eventi qui gli admin NON
     * sono in copia: sono l'unico anello che puo' sbloccare l'azienda, che
     * fino all'approvazione non entra nel portale. Va chiamata solo sul
     * passaggio pending -> under_review, non a ogni documento caricato.
     */
    public static function notifyAdminsOfKycSubmission(Company $company): void
    {
        $admins = User::where('is_super_admin', true)->where('is_active', true)->get();

        $notification = new KycSubmittedNotification($company);

        foreach ($admins as $admin) {
            if ($admin->email) {
                $admin->notify($notification);
            }
        }
    }

    public static function notifyAdminsOfCompanyReport(CompanyReport $report): void
    {
        $admins = User::where('is_super_admin', true)->where('is_active', true)->get();

        $notification = new CompanyReportSubmittedNotification($report);

        foreach ($admins as $admin) {
            if ($admin->email) {
                $admin->notify($notification);
            }
        }
    }
}
