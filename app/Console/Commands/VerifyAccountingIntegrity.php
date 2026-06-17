<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\Transfer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use App\Notifications\AccountingIntegrityAlert;

/**
 * Verifica giornaliera degli invarianti contabili.
 *
 * Invarianti controllati:
 *  1. Ogni transfer booked ha esattamente 1 debit + 1 credit di pari importo
 *  2. available_balance di ogni conto = somma algebrica delle LedgerEntry
 *  3. Somma di tutti i saldi = 0 (circuito chiuso, al netto del conto sistema)
 *
 * In caso di scostamento: notifica admin via email + log Sentry.
 */
class VerifyAccountingIntegrity extends Command
{
    protected $signature = 'accounting:verify-integrity
                            {--fix-log : Solo logga i problemi senza notificare}';

    protected $description = 'Verifica gli invarianti contabili (quadratura, saldi, ledger)';

    public function handle(): int
    {
        $errors = [];

        // ── 1. Quadratura transfer: ogni booked transfer deve avere debit = credit ──
        $unbalancedTransfers = DB::select("
            SELECT t.id, t.uuid, t.amount, t.kind,
                   SUM(CASE WHEN le.direction = 'debit'  THEN le.amount ELSE 0 END) AS total_debit,
                   SUM(CASE WHEN le.direction = 'credit' THEN le.amount ELSE 0 END) AS total_credit,
                   COUNT(le.id) AS entry_count
            FROM transfers t
            LEFT JOIN ledger_entries le ON le.transfer_id = t.id
            WHERE t.status = 'booked'
              -- Esenta i record di migrazione legacy ('KM-MIG-*'): storico importato
              -- senza partita doppia per natura (vedi AdminIntegrityController).
              AND t.reference NOT LIKE 'KM-MIG-%'
            GROUP BY t.id, t.uuid, t.amount, t.kind
            HAVING total_debit != t.amount
                OR total_credit != t.amount
                OR entry_count != 2
            LIMIT 50
        ");

        if (! empty($unbalancedTransfers)) {
            foreach ($unbalancedTransfers as $row) {
                $errors[] = "Transfer #{$row->id} ({$row->uuid}) sbilanciato: "
                    . "amount={$row->amount}, debit={$row->total_debit}, credit={$row->total_credit}, entries={$row->entry_count}";
            }
        }

        // ── 2. Verifica available_balance vs somma ledger per ogni conto ──
        $mismatchedAccounts = DB::select("
            SELECT a.id, a.account_number, a.available_balance,
                   COALESCE(SUM(
                       CASE le.direction
                           WHEN 'credit' THEN  le.amount
                           WHEN 'debit'  THEN -le.amount
                           ELSE 0
                       END
                   ), 0) AS ledger_balance
            FROM accounts a
            LEFT JOIN ledger_entries le ON le.account_id = a.id
            GROUP BY a.id, a.account_number, a.available_balance
            HAVING ABS(a.available_balance - ledger_balance) > 1
            LIMIT 50
        ");

        if (! empty($mismatchedAccounts)) {
            foreach ($mismatchedAccounts as $row) {
                $diff = $row->available_balance - $row->ledger_balance;
                $errors[] = "Conto #{$row->id} ({$row->account_number}) saldo non quadra: "
                    . "available_balance={$row->available_balance}, ledger={$row->ledger_balance}, diff={$diff}";
            }
        }

        // ── 3. Somma di tutti i saldi non-sistema = opposto del saldo sistema ──
        // In un circuito chiuso: somma(tutti i saldi) = 0
        $totalBalance = Account::query()->sum('available_balance');
        if (abs($totalBalance) > 100) { // tolleranza 1 KY per arrotondamenti
            $errors[] = "Somma globale dei saldi non è zero: {$totalBalance} centesimi";
        }

        if (empty($errors)) {
            $this->info('✓ Tutti gli invarianti contabili sono verificati.');
            Log::info('accounting.integrity_ok', ['checked_at' => now()->toIso8601String()]);
            return self::SUCCESS;
        }

        // ── Segnala gli errori ──
        $this->error('ANOMALIE CONTABILI RILEVATE:');
        foreach ($errors as $err) {
            $this->line("  • {$err}");
        }

        Log::critical('accounting.integrity_failure', [
            'errors'     => $errors,
            'checked_at' => now()->toIso8601String(),
        ]);

        // Notifica Sentry
        if (app()->bound('sentry')) {
            \Sentry\captureMessage(
                'Accounting integrity failure: ' . count($errors) . ' anomalie',
                \Sentry\Severity::critical()
            );
        }

        if (! $this->option('fix-log')) {
            $this->notifyAdmins($errors);
        }

        return self::FAILURE;
    }

    private function notifyAdmins(array $errors): void
    {
        try {
            $admins = \App\Models\User::query()
                ->where('is_super_admin', true)
                ->whereNotNull('email')
                ->get();

            foreach ($admins as $admin) {
                $admin->notify(new AccountingIntegrityAlert($errors));
            }
        } catch (\Throwable $e) {
            Log::error('accounting.notify_failed', ['error' => $e->getMessage()]);
        }
    }
}
