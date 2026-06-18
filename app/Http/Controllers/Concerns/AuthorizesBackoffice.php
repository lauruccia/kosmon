<?php

namespace App\Http\Controllers\Concerns;

use App\Models\User;

/**
 * Helper di autorizzazione per i controller di backoffice (admin).
 * Estratto da AdminController per condividerlo tra i controller Admin/* (God controller split).
 */
trait AuthorizesBackoffice
{
    protected function authorizeBackoffice(User $user): void
    {
        abort_unless($user->canAccessBackoffice(), 403);
    }

    protected function authorizePermission(User $user, string $permission): void
    {
        abort_unless($user->is_super_admin || $user->hasPermission($permission), 403);
    }
}
