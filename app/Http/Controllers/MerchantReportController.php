<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Transfer;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class MerchantReportController extends Controller
{
    /**
     * GET /portale/report-merchant
     */
    public function index(Request $request): View
    {
        $user    = $request->user();
        $account = $this->resolveAccount($user);

        [$from, $to, $period] = $this->parsePeriod($request);

        // ── KPI principali ────────────────────────────────────────────────────
        $baseIn  = Transfer::query()->where('to_account_id', $account->id)->where('status', 'booked')
            ->whereBetween('booked_at', [$from, $to]);
        $baseOut = Transfer::query()->where('from_account_id', $account->id)->where('status', 'booked')
            ->whereBetween('booked_at', [$from, $to]);

        $incomeTotal     = (clone $baseIn)->whereNotIn('kind', ['portal_fee', 'portal_cashback'])->sum('amount');
        $expenseTotal    = (clone $baseOut)->whereNotIn('kind', ['portal_fee', 'portal_cashback'])->sum('amount');
        $cashbackTotal   = (clone $baseIn)->where('kind', 'portal_cashback')->sum('amount');
        $feeTotal        = (clone $baseOut)->where('kind', 'portal_fee')->sum('amount');
        $txCount         = (clone $baseIn)->whereNotIn('kind', ['portal_fee', 'portal_cashback'])->count();

        // ── Trend mensile (12 mesi a ritroso) ────────────────────────────────
        $months = collect(range(11, 0))->map(function (int $offset) use ($account): array {
            $month = now()->startOfMonth()->subMonths($offset);
            $start = $month->copy()->startOfMonth();
            $end   = $month->copy()->endOfMonth();

            return [
                'label'   => $month->translatedFormat('M Y'),
                'income'  => (int) Transfer::query()
                    ->where('to_account_id', $account->id)->where('status', 'booked')
                    ->whereNotIn('kind', ['portal_fee', 'portal_cashback'])
                    ->whereBetween('booked_at', [$start, $end])->sum('amount'),
                'expense' => (int) Transfer::query()
                    ->where('from_account_id', $account->id)->where('status', 'booked')
                    ->whereNotIn('kind', ['portal_fee', 'portal_cashback'])
                    ->whereBetween('booked_at', [$start, $end])->sum('amount'),
            ];
        });

        // ── Top 5 pagatori (chi mi ha pagato di più nel periodo) ─────────────
        $topPayers = Transfer::query()
            ->with('fromAccount.company', 'fromAccount.ownerUser')
            ->where('to_account_id', $account->id)
            ->where('status', 'booked')
            ->whereNotIn('kind', ['portal_fee', 'portal_cashback'])
            ->whereBetween('booked_at', [$from, $to])
            ->selectRaw('from_account_id, SUM(amount) as total, COUNT(*) as tx_count')
            ->groupBy('from_account_id')
            ->orderByDesc('total')
            ->limit(5)
            ->get();

        // ── Ultimi movimenti ──────────────────────────────────────────────────
        $recentTransfers = Transfer::query()
            ->with('fromAccount.company', 'fromAccount.ownerUser', 'toAccount.company', 'toAccount.ownerUser')
            ->where(fn ($q) => $q->where('from_account_id', $account->id)->orWhere('to_account_id', $account->id))
            ->where('status', 'booked')
            ->whereNotIn('kind', ['portal_fee'])
            ->orderByDesc('booked_at')
            ->limit(20)
            ->get();

        return view('portal.merchant-report', [
            'pageTitle'       => 'Report merchant',
            'currentAccount'  => $account,
            'currentUser'     => $user,
            'from'            => $from,
            'to'              => $to,
            'period'          => $period,
            'incomeTotal'     => (int) $incomeTotal,
            'expenseTotal'    => (int) $expenseTotal,
            'cashbackTotal'   => (int) $cashbackTotal,
            'feeTotal'        => (int) $feeTotal,
            'txCount'         => (int) $txCount,
            'months'          => $months,
            'topPayers'       => $topPayers,
            'recentTransfers' => $recentTransfers,
            'activeNav'       => 'movimenti',
        ]);
    }

    /**
     * GET /portale/report-merchant/export-csv
     */
    public function exportCsv(Request $request): Response
    {
        $user    = $request->user();
        $account = $this->resolveAccount($user);

        [$from, $to] = $this->parsePeriod($request);

        $transfers = Transfer::query()
            ->with('fromAccount.company', 'fromAccount.ownerUser', 'toAccount.company', 'toAccount.ownerUser')
            ->where(fn ($q) => $q->where('from_account_id', $account->id)->orWhere('to_account_id', $account->id))
            ->where('status', 'booked')
            ->whereBetween('booked_at', [$from, $to])
            ->orderByDesc('booked_at')
            ->get();

        $lines = [];
        $lines[] = implode(';', ['Data', 'Tipo', 'Descrizione', 'Da', 'A', 'Importo KY', 'Direzione']);

        foreach ($transfers as $t) {
            $isCredit = (int) $t->to_account_id === $account->id;
            $from_name  = $t->fromAccount?->company?->name ?? $t->fromAccount?->ownerUser?->name ?? '';
            $to_name    = $t->toAccount?->company?->name  ?? $t->toAccount?->ownerUser?->name  ?? '';
            $lines[] = implode(';', [
                $t->booked_at?->format('Y-m-d H:i'),
                $t->kind,
                str_replace(';', ',', $t->description ?? ''),
                $from_name,
                $to_name,
                number_format($t->amount / 100, 2, ',', '.'),
                $isCredit ? 'Entrata' : 'Uscita',
            ]);
        }

        $filename = 'report-' . $account->account_number . '-' . $from->format('Ym') . '-' . $to->format('Ym') . '.csv';

        return response(implode("\n", $lines), 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function parsePeriod(Request $request): array
    {
        $period = (string) $request->query('periodo', 'mese');

        $from = match ($period) {
            'anno'     => now()->startOfYear(),
            'trimestre' => now()->startOfQuarter(),
            '6mesi'    => now()->subMonths(6)->startOfDay(),
            default    => now()->startOfMonth(), // 'mese'
        };
        $to = now()->endOfDay();

        return [$from, $to, $period];
    }

    private function resolveAccount($user): Account
    {
        if ($user->managed_account_id) {
            return Account::with(['company', 'ownerUser'])->findOrFail($user->managed_account_id);
        }
        if ($user->company_id) {
            return Account::with('company')->where('company_id', $user->company_id)->whereNull('parent_account_id')->firstOrFail();
        }
        return Account::with('ownerUser')->where('owner_user_id', $user->id)->whereNull('parent_account_id')->firstOrFail();
    }
}
