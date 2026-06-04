<?php

namespace App\Http\Middleware;

use App\Models\Account;
use App\Models\Company;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocca l'accesso al portale se l'azienda è stata sospesa dall'admin.
 * Viene inserito nella catena dopo il middleware 'auth'.
 */
class EnsureCompanyNotSuspended
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || $user->canAccessBackoffice()) {
            return $next($request);
        }

        // Risolvi l'account principale dell'utente
        $accountId = $user->managed_account_id ?? null;

        $company = null;

        if ($accountId) {
            $account = Account::with('company')->find($accountId);
            $company = $account?->company;
        }

        if (! $company) {
            // Percorso diretto per utenti aziendali (company_id presente sul record user)
            if ($user->company_id) {
                $company = Company::find($user->company_id);
            }
        }

        if (! $company) {
            // Fallback: cerca tramite account personale (owner_user_id) per utenti privati
            $account = Account::with('company')
                ->where('owner_user_id', $user->id)
                ->first();
            $company = $account?->company;
        }

        if ($company && $company->isSuspended()) {
            // Logout and show suspended page
            auth()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors([
                'email' => 'Il tuo account è stato sospeso. Contatta il supporto per maggiori informazioni.'
                    . ($company->suspension_reason ? ' Motivo: ' . $company->suspension_reason : ''),
            ]);
        }

        return $next($request);
    }
}
