<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AccountManager;
use App\Models\AuditLog;
use App\Models\SubAccountInvitation;
use App\Models\User;
use App\Notifications\SubAccountInvitationSent;
use App\Notifications\SubAccountAccessGranted;
use App\Notifications\SubAccountAccessRevoked;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use RuntimeException;

class SubAccountService
{
    /**
     * Create a new sub-account under the given root account.
     * Optionally invite a manager by email (B+C model).
     */
    public function create(
        Account $rootAccount,
        User $createdBy,
        array $attributes,
        ?string $managerEmail = null,
        string $ipAddress = '',
    ): Account {
        if ($rootAccount->isSubAccount()) {
            throw new RuntimeException('Non puoi creare un sottoconto di un sottoconto.');
        }

        $subAccount = Account::create([
            'company_id'           => $rootAccount->company_id,
            'owner_user_id'        => $rootAccount->owner_user_id,
            'owner_type'           => $rootAccount->owner_type,
            'parent_account_id'    => $rootAccount->id,
            'assigned_by_user_id'  => $createdBy->id,
            'type'                 => 'subaccount',
            'account_name'         => $attributes['account_name'],
            'currency_code'        => $rootAccount->currency_code,
            'status'               => 'active',
            'allow_negative_balance' => false,
            'available_balance'    => 0,
            'pending_balance'      => 0,
            'spending_limit'       => $attributes['spending_limit'] ?? null,
            'daily_outgoing_limit' => $attributes['daily_outgoing_limit'] ?? null,
            'monthly_outgoing_limit' => $attributes['monthly_outgoing_limit'] ?? null,
        ]);

        AuditLog::create([
            'actor_user_id'  => $createdBy->id,
            'event'          => 'subaccount.created',
            'auditable_type' => Account::class,
            'auditable_id'   => $subAccount->id,
            'ip_address'     => $ipAddress,
            'context'        => [
                'root_account_id'      => $rootAccount->id,
                'spending_limit'       => $subAccount->spending_limit,
                'daily_outgoing_limit' => $subAccount->daily_outgoing_limit,
                'monthly_outgoing_limit' => $subAccount->monthly_outgoing_limit,
            ],
        ]);

        if ($managerEmail) {
            $this->inviteManager($subAccount, $createdBy, $managerEmail, $ipAddress);
        }

        return $subAccount;
    }

    /**
     * Invite a user to manage a sub-account (B+C model).
     * - If email already registered → send accept notification to existing user.
     * - If not registered → create SubAccountInvitation and send email.
     */
    public function inviteManager(
        Account $subAccount,
        User $invitedBy,
        string $email,
        string $ipAddress = '',
    ): void {
        if (! $subAccount->isSubAccount()) {
            throw new RuntimeException('Solo i sottoconti possono avere gestori assegnati.');
        }

        $rootAccount = $subAccount->parentAccount;

        // Prevent inviting the root account owner themselves (already implicit owner)
        $existingUser = User::where('email', $email)->first();

        if ($existingUser) {
            // Model B: user already registered — check not already a manager
            if ($subAccount->managers()->whereKey($existingUser->id)->exists()) {
                throw new RuntimeException('Questo utente gestisce gia questo sottoconto.');
            }

            // Create pending entry in account_managers
            AccountManager::create([
                'account_id'  => $subAccount->id,
                'user_id'     => $existingUser->id,
                'role'        => 'manager',
                'accepted_at' => null,
            ]);

            // Notify existing user to accept
            $existingUser->notify(new SubAccountAccessGranted($subAccount, $invitedBy));

            AuditLog::create([
                'actor_user_id'  => $invitedBy->id,
                'event'          => 'subaccount.manager_invited_existing',
                'auditable_type' => Account::class,
                'auditable_id'   => $subAccount->id,
                'ip_address'     => $ipAddress,
                'context'        => ['invited_user_id' => $existingUser->id, 'email' => $email],
            ]);
        } else {
            // Model C: not registered — create invitation with token
            // Delete any existing non-accepted invitation for this email+account
            SubAccountInvitation::where('account_id', $subAccount->id)
                ->where('email', $email)
                ->whereNull('accepted_at')
                ->delete();

            $invitation = SubAccountInvitation::create([
                'account_id' => $subAccount->id,
                'invited_by' => $invitedBy->id,
                'email'      => $email,
                'token'      => Str::random(64),
                'expires_at' => now()->addDays(7),
            ]);

            Notification::route('mail', $email)
                ->notify(new SubAccountInvitationSent($invitation, $subAccount, $invitedBy));

            AuditLog::create([
                'actor_user_id'  => $invitedBy->id,
                'event'          => 'subaccount.manager_invited_new',
                'auditable_type' => Account::class,
                'auditable_id'   => $subAccount->id,
                'ip_address'     => $ipAddress,
                'context'        => ['email' => $email, 'token' => $invitation->token],
            ]);
        }
    }

    /**
     * Accept an invitation (for users that were already registered — model B).
     * Called when an existing user clicks "Accept" from SubAccountAccessGranted notification.
     */
    public function acceptByExistingUser(Account $subAccount, User $user): void
    {
        $manager = AccountManager::where('account_id', $subAccount->id)
            ->where('user_id', $user->id)
            ->whereNull('accepted_at')
            ->firstOrFail();

        $manager->update(['accepted_at' => now()]);

        AuditLog::create([
            'actor_user_id'  => $user->id,
            'event'          => 'subaccount.manager_accepted',
            'auditable_type' => Account::class,
            'auditable_id'   => $subAccount->id,
            'ip_address'     => null,
            'context'        => ['user_id' => $user->id],
        ]);
    }

    /**
     * Accept an invitation via token (for new users — model C).
     * Called after the new user registers via the invitation link.
     */
    public function acceptInvitation(SubAccountInvitation $invitation, User $newUser): void
    {
        if (! $invitation->isPending()) {
            throw new RuntimeException('Invito non valido o scaduto.');
        }

        DB::transaction(function () use ($invitation, $newUser): void {
            // Link the user to the sub-account
            AccountManager::firstOrCreate(
                ['account_id' => $invitation->account_id, 'user_id' => $newUser->id],
                ['role' => 'manager', 'accepted_at' => now()],
            );

            // If created pending (model B path), accept now
            AccountManager::where('account_id', $invitation->account_id)
                ->where('user_id', $newUser->id)
                ->whereNull('accepted_at')
                ->update(['accepted_at' => now()]);

            $invitation->update(['accepted_at' => now()]);

            AuditLog::create([
                'actor_user_id'  => $newUser->id,
                'event'          => 'subaccount.invitation_accepted',
                'auditable_type' => Account::class,
                'auditable_id'   => $invitation->account_id,
                'ip_address'     => null,
                'context'        => ['invitation_id' => $invitation->id, 'user_id' => $newUser->id],
            ]);
        });
    }

    /**
     * Revoke a manager's access to a sub-account.
     */
    public function revokeManager(Account $subAccount, User $manager, User $revokedBy, string $ipAddress = ''): void
    {
        AccountManager::where('account_id', $subAccount->id)
            ->where('user_id', $manager->id)
            ->delete();

        $manager->notify(new SubAccountAccessRevoked($subAccount, $revokedBy));

        AuditLog::create([
            'actor_user_id'  => $revokedBy->id,
            'event'          => 'subaccount.manager_revoked',
            'auditable_type' => Account::class,
            'auditable_id'   => $subAccount->id,
            'ip_address'     => $ipAddress,
            'context'        => ['revoked_user_id' => $manager->id],
        ]);
    }

    /**
     * Update limits on an existing sub-account.
     */
    public function updateLimits(Account $subAccount, array $limits, User $updatedBy, string $ipAddress = ''): void
    {
        $before = [
            'spending_limit'         => $subAccount->spending_limit,
            'daily_outgoing_limit'   => $subAccount->daily_outgoing_limit,
            'monthly_outgoing_limit' => $subAccount->monthly_outgoing_limit,
        ];

        $subAccount->forceFill([
            'spending_limit'         => $limits['spending_limit'] ?? null,
            'daily_outgoing_limit'   => $limits['daily_outgoing_limit'] ?? null,
            'monthly_outgoing_limit' => $limits['monthly_outgoing_limit'] ?? null,
        ])->save();

        AuditLog::create([
            'actor_user_id'  => $updatedBy->id,
            'event'          => 'subaccount.limits_updated',
            'auditable_type' => Account::class,
            'auditable_id'   => $subAccount->id,
            'ip_address'     => $ipAddress,
            'context'        => ['before' => $before, 'after' => $limits],
        ]);
    }

    /**
     * Suspend or reactivate a sub-account.
     */
    public function setStatus(Account $subAccount, string $status, User $updatedBy, string $ipAddress = ''): void
    {
        $subAccount->forceFill(['status' => $status])->save();

        // Suspend/reactivate the legacy managed_account_id users too
        if ($subAccount->spending_limit !== null || true) {
            User::where('managed_account_id', $subAccount->id)
                ->update(['is_active' => $status === 'active']);
        }

        AuditLog::create([
            'actor_user_id'  => $updatedBy->id,
            'event'          => 'subaccount.status_updated',
            'auditable_type' => Account::class,
            'auditable_id'   => $subAccount->id,
            'ip_address'     => $ipAddress,
            'context'        => ['status' => $status],
        ]);
    }
}
