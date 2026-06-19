<?php

namespace App\Http\Requests;

/**
 * Stesse regole di StoreAnnouncementRequest; cambia solo l'autorizzazione:
 * puo' modificare solo il proprietario dell'annuncio (o un super admin).
 */
class UpdateAnnouncementRequest extends StoreAnnouncementRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if ($user === null) {
            return false;
        }

        $announcement = $this->route('announcement');

        return $announcement !== null
            && ($user->is_super_admin || $announcement->company_id === $user->company_id);
    }
}
