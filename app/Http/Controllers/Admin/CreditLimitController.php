<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\AuthorizesBackoffice;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class CreditLimitController extends Controller
{
    use AuthorizesBackoffice;

    public function setCreditLimit(Request $request, Company $company): RedirectResponse
    {
        $this->authorizeBackoffice($request->user());

        $account = $company->accounts()->whereNull('parent_account_id')->where('status', 'active')->first();
        abort_unless($account !== null, 404, 'Nessun conto principale attivo per questa azienda.');

        foreach (['credit_limit', 'daily_outgoing_limit', 'single_transfer_limit'] as $field) {
            if ($request->filled($field)) {
                $request->merge([$field => str_replace(',', '.', (string) $request->input($field))]);
            }
        }

        $validated = $request->validate([
            'credit_limit'           => ['required', 'numeric', 'min:0'],
            'daily_outgoing_limit'   => ['nullable', 'numeric', 'min:0'],
            'single_transfer_limit'  => ['nullable', 'numeric', 'min:0'],
        ]);

        // Disattiva eventuali limiti precedenti
        $account->creditLimits()->where('status', 'active')->update(['status' => 'inactive']);

        // Crea nuovo limite
        $account->creditLimits()->create([
            'credit_limit'          => ky_to_cents($validated['credit_limit']),
            'daily_outgoing_limit'  => $request->filled('daily_outgoing_limit') ? ky_to_cents($validated['daily_outgoing_limit']) : null,
            'single_transfer_limit' => $request->filled('single_transfer_limit') ? ky_to_cents($validated['single_transfer_limit']) : null,
            'status'                => 'active',
            'approved_at'           => now(),
        ]);

        return back()->with('portal_success',
            'Limite di credito aggiornato per ' . $company->name . '.'
        );
    }

    public function setMaxBalance(Request $request, Company $company): RedirectResponse
    {
        $this->authorizeBackoffice($request->user());

        $account = $company->accounts()->whereNull('parent_account_id')->where('status', 'active')->first();
        abort_unless($account !== null, 404, 'Nessun conto principale attivo per questa azienda.');

        if ($request->filled('max_balance')) {
            $request->merge(['max_balance' => str_replace(',', '.', (string) $request->input('max_balance'))]);
        }

        $validated = $request->validate([
            'max_balance' => ['nullable', 'numeric', 'min:0'],
        ]);

        $maxBalance = $request->filled('max_balance')
            ? ky_to_cents($validated['max_balance'])
            : null;

        $account->update(['max_balance' => $maxBalance]);

        $label = $maxBalance !== null
            ? ky_format($maxBalance) . ' KY'
            : 'nessun tetto';

        return back()->with('portal_success',
            'Tetto massimo impostato a ' . $label . ' per ' . $company->name . '.'
        );
    }

    public function limits(Request $request): View
    {
        $this->authorizeBackoffice($request->user());

        return view('admin.limits', [
            'pageTitle' => 'Limiti di default',
            'defaultTransferLimits' => SystemSetting::userLimitDefaults()->defaultsMap(),
            'usersWithOverridesCount' => User::query()
                ->where('transfer_limits_use_defaults', false)
                ->orWhereNotNull('circuit_capacity_limit')
                ->orWhereNotNull('negative_balance_limit')
                ->orWhereNotNull('daily_transaction_limit')
                ->orWhereNotNull('monthly_transaction_limit')
                ->orWhereNotNull('per_movement_limit')
                ->count(),
            'activeNav' => 'limits',
        ]);
    }

    public function updateLimitDefaults(Request $request): RedirectResponse
    {
        $this->authorizePermission($request->user(), 'users.manage');

        $defaultLimitFields = [
            'default_circuit_capacity_limit', 'default_negative_balance_limit',
            'default_daily_transaction_limit', 'default_monthly_transaction_limit',
            'default_per_movement_limit', 'payment_confirm_totp_threshold',
            'payment_pin_threshold',
        ];
        foreach ($defaultLimitFields as $field) {
            if ($request->filled($field)) {
                $request->merge([$field => str_replace(',', '.', (string) $request->input($field))]);
            }
        }

        $validated = $request->validate([
            'default_circuit_capacity_limit'    => ['nullable', 'numeric', 'min:0'],
            'default_negative_balance_limit'    => ['nullable', 'numeric', 'min:0'],
            'default_daily_transaction_limit'   => ['nullable', 'numeric', 'min:0'],
            'default_monthly_transaction_limit' => ['nullable', 'numeric', 'min:0'],
            'default_per_movement_limit'        => ['nullable', 'numeric', 'min:0'],
            'payment_confirm_totp_threshold'    => ['nullable', 'numeric', 'min:0'],
            'payment_pin_threshold'             => ['nullable', 'numeric', 'min:0'],
        ]);

        // Separa i campi threshold (non sono limiti utente, vanno salvati direttamente)
        $totpThreshold = array_key_exists('payment_confirm_totp_threshold', $validated)
            ? ($validated['payment_confirm_totp_threshold'] === null || $validated['payment_confirm_totp_threshold'] === ''
                ? null
                : ky_to_cents($validated['payment_confirm_totp_threshold']))
            : null;
        unset($validated['payment_confirm_totp_threshold']);

        $pinThreshold = array_key_exists('payment_pin_threshold', $validated)
            ? ($validated['payment_pin_threshold'] === null || $validated['payment_pin_threshold'] === ''
                ? null
                : ky_to_cents($validated['payment_pin_threshold']))
            : null;
        unset($validated['payment_pin_threshold']);

        $validated = collect($validated)
            ->map(fn ($value) => $value === null || $value === '' ? null : ky_to_cents($value))
            ->all();

        DB::transaction(function () use ($validated, $totpThreshold, $pinThreshold): void {
            $defaults = SystemSetting::userLimitDefaults();
            $currentDefaults = $defaults->defaultsMap();

            User::query()
                ->where('transfer_limits_use_defaults', true)
                ->chunkById(100, function ($users) use ($currentDefaults): void {
                    foreach ($users as $user) {
                        $user->forceFill([
                            'circuit_capacity_limit' => $user->circuit_capacity_limit ?? $currentDefaults['circuit_capacity_limit'],
                            'negative_balance_limit' => $user->negative_balance_limit ?? $currentDefaults['negative_balance_limit'],
                            'daily_transaction_limit' => $user->daily_transaction_limit ?? $currentDefaults['daily_transaction_limit'],
                            'monthly_transaction_limit' => $user->monthly_transaction_limit ?? $currentDefaults['monthly_transaction_limit'],
                            'per_movement_limit' => $user->per_movement_limit ?? $currentDefaults['per_movement_limit'],
                            'transfer_limits_use_defaults' => false,
                        ])->save();
                    }
                });

            $defaults->forceFill(array_merge($validated, [
                'payment_confirm_totp_threshold' => $totpThreshold,
                'payment_pin_threshold'          => $pinThreshold,
            ]))->save();
        });

        return back()->with('portal_success', 'Limiti di default aggiornati correttamente.');
    }

    public function creditLimitRequests(Request $request): View
    {
        $this->authorizeBackoffice($request->user());

        $pending  = \App\Models\CreditLimitRequest::with(['account.company', 'account.ownerUser'])
            ->where('status', 'pending')
            ->latest()
            ->get();

        $recent = \App\Models\CreditLimitRequest::with(['account.company', 'account.ownerUser', 'admin'])
            ->whereIn('status', ['approved', 'rejected'])
            ->latest('actioned_at')
            ->take(30)
            ->get();

        return view('admin.credit-requests', compact('pending', 'recent'));
    }

    /** POST /admin/credit-requests/{creditRequest}/approve */
    public function approveCreditRequest(Request $request, \App\Models\CreditLimitRequest $creditRequest): RedirectResponse
    {
        $this->authorizeBackoffice($request->user());
        abort_unless($creditRequest->isPending(), 422, "Richiesta non più in stato pending.");

        foreach (['approved_amount', 'daily_outgoing_limit', 'single_transfer_limit'] as $field) {
            if ($request->filled($field)) {
                $request->merge([$field => str_replace(',', '.', (string) $request->input($field))]);
            }
        }

        $validated = $request->validate([
            'approved_amount'       => ['required', 'numeric', 'min:0.01'],
            'admin_note'            => ['nullable', 'string', 'max:500'],
            'daily_outgoing_limit'  => ['nullable', 'numeric', 'min:0'],
            'single_transfer_limit' => ['nullable', 'numeric', 'min:0'],
        ]);

        $approvedCents = ky_to_cents($validated['approved_amount']);

        $account = $creditRequest->account;

        // Fido additivo: nuovo totale = massimale attuale + importo approvato
        $existingMassimale = $account->massimale();
        $newTotal = $existingMassimale + $approvedCents;

        // Disattiva eventuali CreditLimit precedenti
        $account->creditLimits()->where('status', 'active')->update(['status' => 'inactive']);

        // Crea nuovo CreditLimit con il totale sommato
        $account->creditLimits()->create([
            'credit_limit'          => $newTotal,
            'daily_outgoing_limit'  => $request->filled('daily_outgoing_limit') ? ky_to_cents($validated['daily_outgoing_limit']) : null,
            'single_transfer_limit' => $request->filled('single_transfer_limit') ? ky_to_cents($validated['single_transfer_limit']) : null,
            'status'                => 'active',
            'approved_at'           => now(),
        ]);

        // Aggiorna la richiesta (approved_amount = solo la quota aggiuntiva approvata)
        $creditRequest->update([
            'status'          => 'approved',
            'approved_amount' => $approvedCents,
            'admin_note'      => $validated['admin_note'] ?? null,
            'admin_user_id'   => $request->user()->id,
            'actioned_at'     => now(),
        ]);

        AuditLog::create([
            'actor_user_id'  => $request->user()->id,
            'event'          => 'admin.credit_limit.approve',
            'auditable_type' => \App\Models\CreditLimitRequest::class,
            'auditable_id'   => $creditRequest->id,
            'context'        => ['approved_amount' => $approvedCents],
        ]);

        // Notifica l'utente proprietario del conto
        $ownerUser = $account->ownerUser;
        if ($ownerUser) {
            $ownerUser->notify(new \App\Notifications\CreditLimitApproved($creditRequest));
        }

        return back()->with('success', 'Fido approvato e attivato sul conto.');
    }

    /** POST /admin/credit-requests/{creditRequest}/reject */
    public function rejectCreditRequest(Request $request, \App\Models\CreditLimitRequest $creditRequest): RedirectResponse
    {
        $this->authorizeBackoffice($request->user());
        abort_unless($creditRequest->isPending(), 422, "Richiesta non più in stato pending.");

        $validated = $request->validate([
            'admin_note' => ['required', 'string', 'max:500'],
        ], [
            'admin_note.required' => 'Inserisci una motivazione per il rifiuto.',
        ]);

        $creditRequest->update([
            'status'       => 'rejected',
            'admin_note'   => $validated['admin_note'],
            'admin_user_id' => $request->user()->id,
            'actioned_at'  => now(),
        ]);

        AuditLog::create([
            'actor_user_id'  => $request->user()->id,
            'event'          => 'admin.credit_limit.reject',
            'auditable_type' => \App\Models\CreditLimitRequest::class,
            'auditable_id'   => $creditRequest->id,
            'context'        => ['reason' => $validated['admin_note']],
        ]);

        // Notifica l'utente
        $ownerUser = $creditRequest->account->ownerUser;
        if ($ownerUser) {
            $ownerUser->notify(new \App\Notifications\CreditLimitRejected($creditRequest));
        }

        return back()->with('success', 'Richiesta fido rifiutata.');
    }
}
