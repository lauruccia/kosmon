<?php

namespace App\Notifications\Concerns;

use App\Models\User;
use App\Notifications\MlmAgentRequestSubmittedNotification;

/**
 * Helper condiviso per avvisare gli admin (super_admin) di eventi che
 * richiedono la loro attenzione. Usato dal flusso "richiesta agente KNM".
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
}
