<?php

namespace App\Console\Commands;

use App\Models\Account;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RecalcAccountBalances extends Command
{
    protected $signature   = 'kmoney:recalc-balances {--dry-run : Mostra i valori senza salvare}';
    protected $description = 'Ricalcola available_balance per tutti i conti sommando i trasferimenti importati';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        $accounts = Account::query()
            ->where('is_system_account', false)
            ->whereNull('parent_account_id')
            ->get(['id', 'uuid', 'available_balance', 'company_id']);

        if ($accounts->isEmpty()) {
            $this->info('Nessun conto trovato.');
            return self::SUCCESS;
        }

        $validStatuses = ['booked', 'completed'];

        $rows = [];
        foreach ($accounts as $account) {
            $credits = (int) DB::table('transfers')
                ->where('to_account_id', $account->id)
                ->whereIn('status', $validStatuses)
                ->sum('amount');

            $debits = (int) DB::table('transfers')
                ->where('from_account_id', $account->id)
                ->whereIn('status', $validStatuses)
                ->sum('amount');

            $calculated = $credits - $debits;
            $current    = (int) $account->available_balance;
            $diff       = $calculated - $current;

            $rows[] = [
                'account_id' => $account->id,
                'current'    => $current,
                'calculated' => $calculated,
                'diff'       => $diff,
            ];

            if (! $dryRun && $diff !== 0) {
                DB::transaction(function () use ($account, $calculated) {
                    $fresh = Account::lockForUpdate()->findOrFail($account->id);
                    $fresh->forceFill(['available_balance' => $calculated])->save();
                });
            }
        }

        $this->table(
            ['Account ID', 'Saldo attuale (cent)', 'Saldo ricalcolato (cent)', 'Differenza'],
            array_map(fn ($r) => [
                $r['account_id'],
                ky_format($r['current']),
                ky_format($r['calculated']),
                ($r['diff'] >= 0 ? '+' : '') . ky_format($r['diff']),
            ], $rows)
        );

        if ($dryRun) {
            $this->warn('Dry-run: nessuna modifica salvata. Riesegui senza --dry-run per applicare.');
        } else {
            $changed = count(array_filter($rows, fn ($r) => $r['diff'] !== 0));
            $this->info("✓ {$changed} conti aggiornati su " . count($rows) . " totali.");
        }

        return self::SUCCESS;
    }
}
