<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Company;
use App\Models\Transfer;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class StatementController extends Controller
{
    /**
     * Mostra il form di selezione periodo (portale azienda).
     */
    public function show(Request $request): \Illuminate\View\View|\Illuminate\Http\RedirectResponse
    {
        $user = $request->user();

        if ($user->canAccessBackoffice()) {
            return redirect()->route('admin.dashboard');
        }

        $account = $this->resolveAccount($user);

        $months = $this->availableMonths($account);

        return view('portal.statement', [
            'pageTitle'  => 'Estratto conto',
            'account'    => $account,
            'months'     => $months,
            'activeNav'  => 'movimenti',
        ]);
    }

    /**
     * Genera e scarica il PDF dell'estratto conto mensile (portale azienda).
     */
    public function download(Request $request): Response|\Illuminate\Http\RedirectResponse
    {
        $user = $request->user();

        if ($user->canAccessBackoffice()) {
            return redirect()->route('admin.dashboard');
        }

        $account = $this->resolveAccount($user);

        return $this->buildPdf($account, $request->input('mese'));
    }

    /**
     * Download estratto conto per qualsiasi conto — solo admin.
     */
    public function adminDownload(Request $request, Account $account): Response
    {
        abort_unless($request->user()->canAccessBackoffice(), 403);

        return $this->buildPdf($account, $request->input('mese'));
    }

    // ────────────────────────────────────────────────────────────────────────

    private function buildPdf(Account $account, ?string $meseInput): Response
    {
        [$start, $end] = $this->parsePeriod($meseInput);

        // Saldo all'inizio del periodo — ultimo ledger entry PRIMA del periodo
        $openingEntry = $account->ledgerEntries()
            ->where('posted_at', '<', $start)
            ->latest('posted_at')
            ->latest('id')
            ->first();

        $openingBalance = $openingEntry ? (int) $openingEntry->balance_after : 0;

        // Movimenti del periodo
        $transfers = Transfer::query()
            ->with(['fromAccount.company', 'fromAccount.ownerUser', 'toAccount.company', 'toAccount.ownerUser'])
            ->where(function ($q) use ($account) {
                $q->where('from_account_id', $account->id)
                  ->orWhere('to_account_id', $account->id);
            })
            ->where('status', 'booked')
            ->whereBetween('booked_at', [$start, $end])
            ->orderBy('booked_at')
            ->orderBy('id')
            ->get();

        // Saldo finale
        $closingEntry = $account->ledgerEntries()
            ->where('posted_at', '<=', $end)
            ->latest('posted_at')
            ->latest('id')
            ->first();

        $closingBalance = $closingEntry ? (int) $closingEntry->balance_after : $openingBalance;

        $pdf = Pdf::loadView('pdf.statement', [
            'account'        => $account,
            'company'        => $account->company,
            'period'         => $start->format('F Y'),
            'periodStart'    => $start,
            'periodEnd'      => $end,
            'openingBalance' => $openingBalance,
            'closingBalance' => $closingBalance,
            'transfers'      => $transfers,
            'generatedAt'    => CarbonImmutable::now(),
        ])->setPaper('a4', 'portrait');

        $filename = 'estratto-conto-' . ($account->company->slug ?? $account->id) . '-' . $start->format('Y-m') . '.pdf';

        return $pdf->download($filename);
    }

    private function resolveAccount(\App\Models\User $user): Account
    {
        if ($user->managed_account_id !== null) {
            $account = Account::with(['company', 'ownerUser'])->findOrFail($user->managed_account_id);
            return $account->parentAccount ?? $account;
        }

        if ($user->company_id !== null) {
            return Account::with(['company', 'ownerUser'])
                ->where('company_id', $user->company_id)
                ->whereNull('parent_account_id')
                ->where('status', 'active')
                ->orderBy('id')
                ->firstOrFail();
        }

        return Account::with(['company', 'ownerUser'])
            ->where('owner_user_id', $user->id)
            ->whereNull('parent_account_id')
            ->where('status', 'active')
            ->orderBy('id')
            ->firstOrFail();
    }

    private function parsePeriod(?string $meseInput): array
    {
        try {
            $ref = $meseInput
                ? CarbonImmutable::createFromFormat('Y-m', $meseInput)
                : CarbonImmutable::now()->subMonth();
        } catch (\Exception) {
            $ref = CarbonImmutable::now()->subMonth();
        }

        return [$ref->startOfMonth(), $ref->endOfMonth()];
    }

    private function availableMonths(Account $account): array
    {
        $first = $account->ledgerEntries()->oldest('posted_at')->value('posted_at');
        if (! $first) {
            return [];
        }

        $start  = CarbonImmutable::parse($first)->startOfMonth();
        $end    = CarbonImmutable::now()->startOfMonth();
        $months = [];

        while ($start->lessThanOrEqualTo($end)) {
            $months[] = [
                'value' => $start->format('Y-m'),
                'label' => $start->translatedFormat('F Y'),
            ];
            $start = $start->addMonth();
        }

        return array_reverse($months);
    }
}
