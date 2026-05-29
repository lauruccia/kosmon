<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\BalanceAlert;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BalanceAlertController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        $account = $this->resolveAccount($request);
        if ($account instanceof RedirectResponse) {
            return $account;
        }

        $alerts = BalanceAlert::where('account_id', $account->id)
            ->orderByDesc('created_at')
            ->get();

        return view('portal.balance-alerts', [
            'pageTitle' => 'Avvisi saldo',
            'activeNav' => 'balance-alerts',
            'alerts'    => $alerts,
            'account'   => $account,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'threshold_ky'   => 'required|numeric|min:0.01|max:9999999',
            'notify_email'   => 'nullable|boolean',
            'notify_inapp'   => 'nullable|boolean',
            'cooldown_hours' => 'nullable|integer|min:1|max:720',
        ]);

        $account = $this->resolveAccount($request);
        if ($account instanceof RedirectResponse) {
            return $account;
        }

        if (BalanceAlert::where('account_id', $account->id)->count() >= 5) {
            return back()->withErrors(['threshold_ky' => 'Puoi configurare al massimo 5 avvisi per conto.']);
        }

        BalanceAlert::create([
            'account_id'       => $account->id,
            'threshold_amount' => (int) round((float) $data['threshold_ky'] * 100),
            'notify_email'     => $request->boolean('notify_email', true),
            'notify_inapp'     => $request->boolean('notify_inapp', true),
            'cooldown_hours'   => (int) ($data['cooldown_hours'] ?? 24),
        ]);

        return back()->with('portal_success', 'Avviso saldo creato. Riceverai una notifica quando il saldo scendera\' sotto la soglia.');
    }

    public function toggle(Request $request, BalanceAlert $balanceAlert): RedirectResponse
    {
        $account = $this->resolveAccount($request);
        if ($account instanceof RedirectResponse) {
            return $account;
        }

        abort_unless($balanceAlert->account_id === $account->id, 403);

        $balanceAlert->update(['is_active' => ! $balanceAlert->is_active]);

        $msg = $balanceAlert->is_active ? 'Avviso attivato.' : 'Avviso disattivato.';
        return back()->with('portal_success', $msg);
    }

    public function destroy(Request $request, BalanceAlert $balanceAlert): RedirectResponse
    {
        $account = $this->resolveAccount($request);
        if ($account instanceof RedirectResponse) {
            return $account;
        }

        abort_unless($balanceAlert->account_id === $account->id, 403);

        $balanceAlert->delete();

        return back()->with('portal_success', 'Avviso eliminato.');
    }

    private function resolveAccount(Request $request): Account|RedirectResponse
    {
        $user    = $request->user();
        $company = $user->company;

        if (! $company) {
            return redirect()->route('portal.dashboard');
        }

        $account = $company->mainAccount ?? $company->accounts()->first();

        if (! $account) {
            return redirect()->route('portal.dashboard');
        }

        return $account;
    }
}
