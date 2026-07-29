<?php

namespace App\Notifications\Concerns;

use App\Models\CompanyReport;
use App\Models\User;
use App\Notifications\CompanyReportSubmittedNotification;
use App\Notifications\MlmAgentRequestSubmittedNotification;

/**
 * Helper condiviso per avvisare gli admin (super_admin) di eventi che
 * richiedono la loro attenzione. Usato dal flusso "richiesta agente KNM"
 * e, dal 29/07/2026, dal flusso "segnalazione azienda" (vedi
 * CompanyReportService::submit()) — per quest'ultimo gli admin sono
 * sempre e solo in copia/visibilita', mai un gate di approvazione.
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
