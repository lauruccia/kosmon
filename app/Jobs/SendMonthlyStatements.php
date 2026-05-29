<?php

namespace App\Jobs;

use App\Models\Account;
use App\Models\PaymentPlanInstallment;
use App\Models\Transfer;
use App\Notifications\MonthlyStatementNotification;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendMonthlyStatements implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function handle(): void
    {
        $prevMonth  = Carbon::now()->subMonth();
        $monthStart = $prevMonth->copy()->startOfMonth();
        $monthEnd   = $prevMonth->copy()->endOfMonth();
        $label      = $prevMonth->locale('it')->translatedFormat('F Y');

        // Solo conti root (no sottoconti), con owner user e KYC approvato
        $accounts = Account::query()
            ->whereNull('parent_account_id')
            ->with(['company', 'ownerUser'])
            ->whereHas('company', fn ($q) => $q->where('kyc_status', 'approved'))
            ->get();

        foreach ($accounts as $account) {
            $user = $account->ownerUser ?? $account->company?->users()->first();
            if (! $user) continue;

            // Controlla preferenza opt-in
            $prefs    = $user->notification_preferences ?? [];
            $channels = $prefs['monthly_statement'] ?? ['mail'];
            if (empty($channels)) continue;

            $income  = (int) Transfer::where('to_account_id', $account->id)
                ->where('status', 'booked')
                ->whereBetween('booked_at', [$monthStart, $monthEnd])
                ->sum('amount');

            $expense = (int) Transfer::where('from_account_id', $account->id)
                ->where('status', 'booked')
                ->whereBetween('booked_at', [$monthStart, $monthEnd])
                ->sum('amount');

            // Rate in scadenza prossimi 7 giorni
            $dueSoon = (int) PaymentPlanInstallment::query()
                ->whereHas('paymentPlan', fn ($q) => $q->where('from_account_id', $account->id)->where('status', 'active'))
                ->where('status', 'pending')
                ->whereBetween('due_date', [now()->toDateString(), now()->addDays(7)->toDateString()])
                ->count();

            try {
                $user->notify(new MonthlyStatementNotification($account, [
                    'month_label'      => $label,
                    'balance'          => $account->available_balance,
                    'income'           => $income,
                    'expense'          => $expense,
                    'due_installments' => $dueSoon,
                ]));
            } catch (\Throwable $e) {
                Log::warning('[SendMonthlyStatements] account #' . $account->id . ': ' . $e->getMessage());
            }
        }

        Log::info('[SendMonthlyStatements] Processed ' . $accounts->count() . ' accounts for ' . $label);
    }
}
