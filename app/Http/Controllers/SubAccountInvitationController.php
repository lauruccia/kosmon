<?php

namespace App\Http\Controllers;

use App\Models\SubAccountInvitation;
use App\Models\User;
use App\Services\SubAccountService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

/**
 * Handles invitation acceptance for non-registered users (Model C).
 * Routes are PUBLIC (no auth required) up to the acceptance step.
 */
class SubAccountInvitationController extends Controller
{
    /**
     * GET /invito-sottoconto/{token}
     * Show the invitation page — register form for new users.
     */
    public function show(string $token): View|RedirectResponse
    {
        $invitation = SubAccountInvitation::with(['account.parentAccount.company', 'invitedBy'])
            ->where('token', $token)
            ->firstOrFail();

        if ($invitation->isAccepted()) {
            return redirect()->route('portal.dashboard')
                ->with('portal_success', 'Invito gia accettato. Effettua il login per accedere.');
        }

        if ($invitation->isExpired()) {
            abort(410, 'Invito scaduto. Chiedi al titolare di inviarne uno nuovo.');
        }

        // If already logged in redirect straight to accept
        if (Auth::check()) {
            return redirect()->route('subaccount.invitation.register', $token);
        }

        return view('subaccount.invitation', [
            'invitation'  => $invitation,
            'subAccount'  => $invitation->account,
            'ownerName'   => $invitation->account->parentAccount?->company?->name
                ?? $invitation->invitedBy->name,
        ]);
    }

    /**
     * GET /invito-sottoconto/{token}/registra
     * Show registration form (for new users) OR redirect existing users who are now logged in.
     */
    public function showRegister(string $token): View|RedirectResponse
    {
        $invitation = SubAccountInvitation::with(['account.parentAccount.company', 'invitedBy'])
            ->where('token', $token)
            ->firstOrFail();

        if (! $invitation->isPending()) {
            return redirect()->route('login')->with('status', 'Invito non valido o scaduto.');
        }

        // Logged-in user: accept directly
        if (Auth::check()) {
            return $this->acceptLoggedIn($token, Auth::user(), $invitation, app(SubAccountService::class));
        }

        // Check if email is already registered (shouldn't happen via model C, but safety net)
        $existingUser = User::where('email', $invitation->email)->first();
        if ($existingUser) {
            return redirect()->route('login')
                ->with('status', 'Email gia registrata. Effettua il login per accettare l\'invito.');
        }

        return view('subaccount.register', [
            'invitation' => $invitation,
            'subAccount' => $invitation->account,
            'ownerName'  => $invitation->account->parentAccount?->company?->name
                ?? $invitation->invitedBy->name,
        ]);
    }

    /**
     * POST /invito-sottoconto/{token}/registra
     * Register new user and accept invitation.
     */
    public function register(Request $request, string $token, SubAccountService $service): RedirectResponse
    {
        $invitation = SubAccountInvitation::with(['account'])
            ->where('token', $token)
            ->firstOrFail();

        if (! $invitation->isPending()) {
            return redirect()->route('login')->with('status', 'Invito non valido o scaduto.');
        }

        $validated = $request->validate([
            'name'     => ['required', 'string', 'max:120'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        // Create the new user (no company, no KYC, delegate role)
        $user = User::create([
            'name'                  => $validated['name'],
            'email'                 => $invitation->email,
            'password'              => Hash::make($validated['password']),
            'role'                  => 'delegated-user',
            'is_active'             => true,
            'is_super_admin'        => false,
            'email_verified_at'     => now(), // auto-verify — email was validated via invite
            'transfer_limits_use_defaults' => true,
        ]);

        $service->acceptInvitation($invitation, $user);

        Auth::login($user);

        return redirect()->route('portal.dashboard')
            ->with('portal_success', 'Registrazione completata! Puoi ora gestire il sottoconto "' . $invitation->account->account_name . '".');
    }

    /**
     * POST /invito-sottoconto/{accountId}/accetta
     * Accept assignment for already-registered users (Model B).
     * Requires auth.
     */
    public function acceptExisting(Request $request, int $accountId, SubAccountService $service): RedirectResponse
    {
        abort_if($request->user()->canAccessBackoffice(), 403);

        $subAccount = \App\Models\Account::findOrFail($accountId);

        try {
            $service->acceptByExistingUser($subAccount, $request->user());
        } catch (\RuntimeException $e) {
            return redirect()->route('portal.accounts.structure')
                ->with('portal_error', $e->getMessage());
        }

        return redirect()->route('portal.accounts.structure')
            ->with('portal_success', 'Accesso accettato! Trovi il sottoconto nel selettore conto.');
    }

    // ─── Private ──────────────────────────────────────────────────────────

    private function acceptLoggedIn(string $token, User $user, SubAccountInvitation $invitation, SubAccountService $service): RedirectResponse
    {
        // If logged-in user email matches invitation email → accept and link
        if (strtolower($user->email) === strtolower($invitation->email)) {
            try {
                $service->acceptInvitation($invitation, $user);
                return redirect()->route('portal.dashboard')
                    ->with('portal_success', 'Sottoconto "' . $invitation->account->account_name . '" aggiunto al tuo profilo.');
            } catch (\RuntimeException $e) {
                return redirect()->route('portal.accounts.structure')
                    ->with('portal_error', $e->getMessage());
            }
        }

        // Different user logged in — ask to log out first or just show info
        return redirect()->route('portal.accounts.structure')
            ->with('portal_error', 'Sei loggato con un account diverso dall\'invitato. Esci e riprova con l\'email: ' . $invitation->email);
    }
}
