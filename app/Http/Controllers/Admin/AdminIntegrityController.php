<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\LedgerEntry;
use App\Models\Transfer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Pagina di verifica e correzione integrità contabile del circuito.
 *
 * Invarianti controllati:
 *  1. SUM(available_balance) di tutti i conti = 0  (circuito chiuso)
 *  2. available_balance di ogni conto = somma algebrica delle sue LedgerEntry
 *  3. Ogni transfer booked ha esattamente 1 debit + 1 credit pari all'importo
 */
class AdminIntegrityController extends Controller
{
    // ── Visualizza report integrità ─────────────────────────────────────────

    public function index(Request $request): View
    {
        abort_unless($request->user()->is_super_admin, 403);

        // ── 1. Chiusura circuito: SUM di tutti i saldi deve essere 0 ──────
        $totalBalance    = (int) Account::query()->sum('available_balance');
        $circuitHealthy  = abs($totalBalance) <= 1; // tolleranza 1 cent

        $systemAccount   = Account::systemAccount();
        $kyInCirculation = $systemAccount ? abs((int) $systemAccount->available_balance) : 0;

        // ── 2. Conti con saldo disallineato rispetto al ledger ─────────────
        $mismatchedAccounts = DB::select("
            SELECT
                a.id,
                a.uuid,
                a.account_name,
                a.available_balance,
                a.is_system_account,
                COALESCE(SUM(
                    CASE le.direction
                        WHEN 'credit' THEN  le.amount
                        WHEN 'debit'  THEN -le.amount
                        ELSE 0
                    END
                ), 0) AS ledger_balance
            FROM accounts a
            LEFT JOIN ledger_entries le ON le.account_id = a.id
            GROUP BY a.id, a.uuid, a.account_name, a.available_balance, a.is_system_account
            HAVING ABS(a.available_balance - ledger_balance) > 1
            ORDER BY ABS(a.available_balance - ledger_balance) DESC
            LIMIT 100
        ");

        // ── 3. Transfer booked sbilanciati ────────────────────────────────
        $unbalancedTransfers = DB::select("
            SELECT
                t.id,
                t.uuid,
                t.amount,
                t.kind,
                t.status,
                t.booked_at,
                fa.account_name AS from_name,
                ta.account_name AS to_name,
                COALESCE(SUM(CASE WHEN le.direction = 'debit'  THEN le.amount ELSE 0 END), 0) AS total_debit,
                COALESCE(SUM(CASE WHEN le.direction = 'credit' THEN le.amount ELSE 0 END), 0) AS total_credit,
                COUNT(le.id) AS entry_count
            FROM transfers t
            LEFT JOIN ledger_entries le ON le.transfer_id = t.id
            LEFT JOIN accounts fa ON fa.id = t.from_account_id
            LEFT JOIN accounts ta ON ta.id = t.to_account_id
            WHERE t.status = 'booked'
            GROUP BY t.id, t.uuid, t.amount, t.kind, t.status, t.booked_at, fa.account_name, ta.account_name
            HAVING total_debit  != t.amount
                OR total_credit != t.amount
                OR entry_count  != 2
            ORDER BY t.booked_at DESC
            LIMIT 50
        ");

        // ── Riepilogo rapido ──────────────────────────────────────────────
        $totalAccounts    = Account::query()->count();
        $mismatchCount    = count($mismatchedAccounts);
        $unbalancedCount  = count($unbalancedTransfers);
        $allHealthy       = $circuitHealthy && $mismatchCount === 0 && $unbalancedCount === 0;

        return view('admin.integrity', [
            'pageTitle'           => 'Integrità Contabile — Circuito KY',
            'activeNav'           => 'integrity',
            // circuito
            'totalBalance'        => $totalBalance,
            'circuitHealthy'      => $circuitHealthy,
            'kyInCirculation'     => $kyInCirculation,
            'systemAccount'       => $systemAccount,
            // conti
            'mismatchedAccounts'  => $mismatchedAccounts,
            'mismatchCount'       => $mismatchCount,
            'totalAccounts'       => $totalAccounts,
            // transfer
            'unbalancedTransfers' => $unbalancedTransfers,
            'unbalancedCount'     => $unbalancedCount,
            // globale
            'allHealthy'          => $allHealthy,
        ]);
    }

    // ── Correggi saldo di un singolo conto (ricalcolo da LedgerEntry) ──────

    public function fixAccount(Request $request, int $accountId): RedirectResponse
    {
        abort_unless($request->user()->is_super_admin, 403);

        DB::transaction(function () use ($request, $accountId) {
            $account = Account::lockForUpdate()->findOrFail($accountId);

            $ledgerBalance = (int) LedgerEntry::query()
                ->where('account_id', $account->id)
                ->selectRaw("COALESCE(SUM(
                    CASE direction
                        WHEN 'credit' THEN  amount
                        WHEN 'debit'  THEN -amount
                        ELSE 0
                    END
                ), 0) AS bal")
                ->value('bal');

            $oldBalance = (int) $account->available_balance;

            $account->forceFill(['available_balance' => $ledgerBalance])->save();

            AuditLog::create([
                'actor_user_id'  => $request->user()->id,
                'event'          => 'admin.integrity.fix_account',
                'auditable_type' => Account::class,
                'auditable_id'   => $account->id,
                'ip_address'     => $request->ip(),
                'context'        => [
                    'account_number' => $account->account_number ?? $account->uuid,
                    'old_balance'    => $oldBalance,
                    'new_balance'    => $ledgerBalance,
                    'diff'           => $ledgerBalance - $oldBalance,
                ],
            ]);
        });

        return redirect()->route('admin.integrity.index')
            ->with('portal_success', 'Saldo conto #' . $accountId . ' ricalcolato dal ledger.');
    }

    // ── Correggi tutti i conti disallineati in un colpo ────────────────────

    public function fixAll(Request $request): RedirectResponse
    {
        abort_unless($request->user()->is_super_admin, 403);

        $mismatched = DB::select("
            SELECT
                a.id,
                a.uuid,
                a.available_balance,
                COALESCE(SUM(
                    CASE le.direction
                        WHEN 'credit' THEN  le.amount
                        WHEN 'debit'  THEN -le.amount
                        ELSE 0
                    END
                ), 0) AS ledger_balance
            FROM accounts a
            LEFT JOIN ledger_entries le ON le.account_id = a.id
            GROUP BY a.id, a.uuid, a.available_balance
            HAVING ABS(a.available_balance - ledger_balance) > 1
        ");

        if (empty($mismatched)) {
            return redirect()->route('admin.integrity.index')
                ->with('portal_success', 'Nessun conto disallineato da correggere.');
        }

        $fixed = 0;

        foreach ($mismatched as $row) {
            DB::transaction(function () use ($request, $row, &$fixed) {
                $account = Account::lockForUpdate()->findOrFail($row->id);

                // Ricalcola dal ledger al momento del lock
                $newBalance = (int) LedgerEntry::query()
                    ->where('account_id', $account->id)
                    ->selectRaw("COALESCE(SUM(
                        CASE direction
                            WHEN 'credit' THEN  amount
                            WHEN 'debit'  THEN -amount
                            ELSE 0
                        END
                    ), 0) AS bal")
                    ->value('bal');

                $account->forceFill(['available_balance' => $newBalance])->save();

                AuditLog::create([
                    'actor_user_id'  => $request->user()->id,
                    'event'          => 'admin.integrity.fix_account',
                    'auditable_type' => Account::class,
                    'auditable_id'   => $account->id,
                    'ip_address'     => $request->ip(),
                    'context'        => [
                        'account_number' => $row->uuid,
                        'old_balance'    => (int) $row->available_balance,
                        'new_balance'    => $newBalance,
                        'diff'           => $newBalance - (int) $row->available_balance,
                        'bulk_fix'       => true,
                    ],
                ]);

                $fixed++;
            });
        }

        return redirect()->route('admin.integrity.index')
            ->with('portal_success', "Corretti {$fixed} conti disallineati. Operazione registrata in Audit Log.");
    }
}
