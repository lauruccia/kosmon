<?php

namespace App\Http\Controllers;

use App\Mail\RegistrationConfirmation;
use App\Models\Account;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function showLogin(): View
    {
        return view('auth.login');
    }

    public function showRegister(): View
    {
        return view('auth.register');
    }

    public function register(Request $request): RedirectResponse
    {
        $holderType = $request->input('account_holder_type', 'private');

        $validated = $request->validate([
            'account_holder_type' => ['required', 'string', Rule::in(['private', 'company'])],
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:30'],
            'fiscal_code' => ['nullable', 'string', 'max:50'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'company_name' => [Rule::requiredIf($holderType === 'company'), 'nullable', 'string', 'max:160'],
            'vat_number' => ['nullable', 'string', 'max:50', 'unique:companies,vat_number'],
            'company_email' => ['nullable', 'email', 'max:120'],
            'ref' => ['nullable', 'string', 'max:12'],
            'become_agent' => ['nullable', 'boolean'],
        ]);

        $becomeAgent = $request->boolean('become_agent');

        // Risolvi chi ha invitato questo utente (se presente)
        $referrer = null;
        if (! empty($validated['ref'])) {
            $referrer = \App\Models\User::where('referral_code', strtoupper(trim($validated['ref'])))->first();
        }

        [$user, $account, $company] = DB::transaction(function () use ($validated, $holderType, $referrer, $becomeAgent) {
            $company = null;
            $legacyRoleLabel = $holderType === 'company' ? 'registered-company' : 'registered-private';

            // MLM: risolve il primo agente antenato nella catena di chi ha invitato
            // (se il referrer e' un cliente, si risale al SUO agente gia' risolto).
            $mlmTree = app(\App\Services\MlmTreeService::class);
            $nearestAgentAncestor = $mlmTree->resolveAgentForNewClient($referrer);

            if ($holderType === 'company') {
                $company = Company::create([
                    'name' => $validated['company_name'],
                    'slug' => Str::slug($validated['company_name']) . '-' . Str::lower(Str::random(5)),
                    'email' => $validated['company_email'] ?? $validated['email'],
                    'vat_number' => $validated['vat_number'] ?? null,
                    'fiscal_code' => $validated['fiscal_code'] ?? null,
                    'status' => 'active',
                    'kyc_status' => 'pending',
                    'currency_code' => 'KY',
                    'settings' => [
                        'registered_via' => 'public_portal',
                    ],
                ]);
            }

            $user = User::create([
                'company_id' => $company?->id,
                'account_holder_type' => $holderType,
                'name' => $validated['name'],
                'email' => $validated['email'],
                'phone' => $validated['phone'] ?? null,
                'fiscal_code' => $validated['fiscal_code'] ?? null,
                'password' => $validated['password'],
                'role' => $legacyRoleLabel,
                'is_active' => true,
                'is_super_admin' => false,
                'referred_by_user_id' => $referrer?->id,
                'mlm_role' => $becomeAgent ? 'agente' : 'cliente',
                'mlm_activated_at' => $becomeAgent ? now() : null,
                'mlm_client_agent_id' => $becomeAgent ? null : $nearestAgentAncestor?->id,
            ]);

            if ($becomeAgent) {
                $mlmTree->attachAgent($user, $nearestAgentAncestor);
            } else {
                app(\App\Services\MlmPointsService::class)->awardRegistrationPoints($user);
            }

            $account = Account::create([
                'company_id' => $company?->id,
                'owner_user_id' => $user->id,
                'owner_type' => $holderType,
                'type' => 'primary',
                'account_name' => $holderType === 'company' ? 'Conto principale ' . $company->name : 'Conto personale ' . $user->name,
                'currency_code' => 'KY',
                'status' => 'active',
                'allow_negative_balance' => false,
                'available_balance' => 0,
                'pending_balance' => 0,
            ]);

            $defaultRoleSlug = $holderType === 'company' ? 'company-member' : 'private-member';
            $defaultRole = Role::query()->where('slug', $defaultRoleSlug)->firstOrFail();
            $user->roles()->sync([$defaultRole->id]);

            return [$user, $account, $company];
        });

        // Invia email di benvenuto in background (queued)
        Mail::to($user->email)->queue(
            new RegistrationConfirmation($user, $account, $company)
        );

        Auth::login($user);
        $request->session()->regenerate();

        // Invia email di verifica (separata dalla welcome email)
        $user->sendEmailVerificationNotification();

        return redirect()->route('portal.dashboard')->with('portal_success', "Conto KMoney aperto correttamente. Controlla la tua email per verificare l'indirizzo.");
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt(array_merge($credentials, ['is_active' => true]), true)) {
            return back()->withInput()->withErrors([
                'email' => 'Credenziali non valide o utente disattivato.',
            ]);
        }

        $request->session()->regenerate();

        $user = $request->user()->loadMissing('roles.permissions', 'company.accounts.creditLimits', 'managedAccount');

        if ($user->canAccessBackoffice()) {
            return redirect()->route('admin.dashboard');
        }

        return redirect()->intended(route('portal.dashboard'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
