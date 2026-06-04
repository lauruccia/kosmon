<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\LedgerEntry;
use App\Models\Transfer;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Reimporta TUTTO dal dump aggiornato di kosmomoney.com (kosmomoney_update_4.sql).
 *
 * Cosa fa:
 *   1. Legge users dal dump -> aggiorna available_balance da campo wallet
 *   2. Cancella tutti i Transfer + LedgerEntry precedentemente importati
 *   3. Reimporta tutte le transazioni dal dump come Transfer booked + LedgerEntry
 *
 * Uso:
 *   php artisan kmoney:reimport-current-dump "C:\Users\Intel\Downloads\kosmomoney_update_4.sql"
 *   php artisan kmoney:reimport-current-dump "C:\Users\Intel\Downloads\kosmomoney_update_4.sql" --dry-run
 *   php artisan kmoney:reimport-current-dump "C:\Users\Intel\Downloads\kosmomoney_update_4.sql" --force
 */
class ReimportCurrentDump extends Command
{
    protected $signature = 'kmoney:reimport-current-dump
        {file : Percorso al dump SQL aggiornato}
        {--dry-run : Mostra cosa farebbe senza modificare il DB}
        {--force  : Salta la conferma interattiva}';

    protected $description = 'Azzera e reimporta tutto da dump kosmomoney aggiornato (saldi + transazioni)';

    // Mappa remark -> kind Transfer
    private const REMARK_KIND = [
        'balance_add'          => 'admin_credit',
        'admin_deposit'        => 'admin_credit',
        'wallet_add'           => 'admin_credit',
        'deposit'              => 'admin_credit',
        'wallet_subtract'      => 'admin_debit',
        'received_money'       => 'p2p_receive',
        'own_bank_transfer'    => 'bank_transfer',
        'purchase_product'     => 'purchase',
        'Buy product'          => 'purchase',
        'Buy KyCard'           => 'kycard_purchase',
        'sold_product'         => 'trade_payment',
        'sell product'         => 'trade_payment',
        'referral_commission'  => 'referral_commission',
    ];

    public function handle(): int
    {
        $file   = $this->argument('file');
        $dryRun = (bool) $this->option('dry-run');

        if (! file_exists($file)) {
            $this->error("File non trovato: {$file}");
            return 1;
        }

        if ($dryRun) {
            $this->warn('*** DRY-RUN: nessuna modifica al DB ***');
        }

        $this->info('Lettura dump... (potrebbe richiedere qualche secondo)');
        $content = file_get_contents($file);
        $this->info('  OK — ' . round(strlen($content) / 1024 / 1024, 1) . ' MB');

        // ----------------------------------------------------------------
        // FASE 1: Parsing utenti dal dump
        // ----------------------------------------------------------------
        $this->info('');
        $this->info('Fase 1: Parsing utenti dal dump...');
        $dumpUsers = $this->parseUsers($content);
        $this->info("  Trovati {$dumpUsers['count']} utenti nel dump");
        $this->info("  Totale wallet nel dump: " . number_format($dumpUsers['total_wallet'], 2) . " KY");

        // ----------------------------------------------------------------
        // FASE 2: Parsing transazioni dal dump
        // ----------------------------------------------------------------
        $this->info('');
        $this->info('Fase 2: Parsing transazioni dal dump...');
        $transactions = $this->parseTransactions($content);
        $this->info("  Trovate " . count($transactions) . " transazioni nel dump");

        // Distribuzione remark
        $remarkCounts = [];
        foreach ($transactions as $t) {
            $remarkCounts[$t['remark']] = ($remarkCounts[$t['remark']] ?? 0) + 1;
        }
        arsort($remarkCounts);
        foreach ($remarkCounts as $r => $c) {
            $this->line("    {$r}: {$c}");
        }

        // ----------------------------------------------------------------
        // RIEPILOGO
        // ----------------------------------------------------------------
        $this->info('');
        $this->info('=== RIEPILOGO OPERAZIONI ===');
        $existingTransfers = Transfer::count();
        $existingLedger    = LedgerEntry::count();
        $this->line("  Transfer esistenti nel nuovo DB:     {$existingTransfers}");
        $this->line("  LedgerEntry esistenti nel nuovo DB:  {$existingLedger}");
        $this->line("  Utenti nel dump:                     {$dumpUsers['count']}");
        $this->line("  Transazioni nel dump:                " . count($transactions));

        if ($dryRun) {
            $this->newLine();
            $this->warn('[DRY-RUN] Nessuna modifica eseguita.');
            return 0;
        }

        if (! $this->option('force')) {
            if (! $this->confirm('Procedere? Tutti i Transfer e LedgerEntry saranno cancellati e reimportati.', true)) {
                $this->info('Annullato.');
                return 0;
            }
        }

        // ----------------------------------------------------------------
        // FASE 3: Reset Transfer + LedgerEntry
        // ----------------------------------------------------------------
        $this->info('');
        $this->info('Fase 3: Cancellazione Transfer e LedgerEntry esistenti...');
        $isSqlite = DB::getDriverName() === 'sqlite';
        if ($isSqlite) {
            DB::statement('PRAGMA foreign_keys = OFF');
            DB::table('ledger_entries')->delete();
            DB::table('transfers')->delete();
            DB::statement('PRAGMA foreign_keys = ON');
        } else {
            DB::statement('SET FOREIGN_KEY_CHECKS=0');
            LedgerEntry::truncate();
            Transfer::truncate();
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }
        $this->info('  OK — Transfer e LedgerEntry cancellati.');

        // ----------------------------------------------------------------
        // FASE 4: Aggiornamento saldi account
        // ----------------------------------------------------------------
        $this->info('');
        $this->info('Fase 4: Aggiornamento profili, piani, limiti e saldi account...');

        $balanceUpdated = 0;
        $balanceNotFound = 0;
        $profilesUpdated = 0;
        $systemAccount = Account::where('is_system_account', true)->first();
        $totalEmitted  = 0;

        foreach ($dumpUsers['by_email'] as $email => $userData) {
            $user = User::with(['company'])->where('email', $email)->first();
            if (! $user) {
                $balanceNotFound++;
                continue;
            }
            $account = Account::where('owner_user_id', $user->id)->first();
            if (! $account) {
                $balanceNotFound++;
                continue;
            }

            $holderType = $this->mapHolderType($userData);
            $limits     = $this->mapLegacyLimits($userData);

            $user->forceFill([
                'account_holder_type' => $holderType,
                'company_id' => $holderType === 'company' ? $user->company_id : null,
                'role' => $holderType === 'company' && $user->role === 'private-owner' ? 'owner' : ($holderType === 'private' ? 'private-owner' : $user->role),
                'is_active' => (int) ($userData['status'] ?? 1) === 1,
                'circuit_capacity_limit' => null,
                'negative_balance_limit' => $limits['negative_balance_limit'],
                'daily_transaction_limit' => $limits['daily_transaction_limit'],
                'monthly_transaction_limit' => $limits['monthly_transaction_limit'],
                'per_movement_limit' => null,
                'transfer_limits_use_defaults' => false,
            ])->save();

            if ($user->company) {
                $user->company->forceFill([
                    'subscription_plan' => $holderType === 'company'
                        ? $this->mapSubscriptionPlan($userData['store_plan_purchase'] ?? null)
                        : null,
                    'status' => (int) ($userData['status'] ?? 1) === 1 ? 'active' : 'suspended',
                ])->save();
            }

            $walletKy = (int) round($userData['wallet']);
            $account->forceFill([
                'company_id' => $holderType === 'company' ? $user->company_id : null,
                'owner_type' => $holderType,
                'status' => (int) ($userData['status'] ?? 1) === 1 ? 'active' : 'suspended',
                'available_balance' => $walletKy,
                'allow_negative_balance' => $limits['negative_balance_limit'] > 0,
                'max_balance' => $limits['max_balance'],
                'daily_outgoing_limit' => $limits['daily_transaction_limit'],
            ])->save();

            $totalEmitted += $walletKy;
            $balanceUpdated++;
            $profilesUpdated++;
        }

        $this->info("  Profili/limiti aggiornati: {$profilesUpdated}");
        $this->info("  Saldi aggiornati: {$balanceUpdated} account");
        $this->info("  Non trovati nel nuovo DB: {$balanceNotFound}");
        $this->info("  Totale KY in circolazione: " . ky_format($totalEmitted) . " KY");

        // Aggiorna Cassa Circuito = -(totale emesso)
        if ($systemAccount) {
            $systemAccount->forceFill(['available_balance' => -$totalEmitted])->save();
            $this->info("  Cassa Circuito: -" . ky_format($totalEmitted) . " KY");
        }

        // ----------------------------------------------------------------
        // FASE 5: Costruisci mappa old_user_id -> account_id
        // ----------------------------------------------------------------
        $this->info('');
        $this->info('Fase 5: Costruzione mappa old_user_id -> account...');

        // Mappa: old_dump_user_id -> kmoney account_id
        $accountMap = [];
        foreach ($dumpUsers['by_id'] as $oldId => $userData) {
            $email = $userData['email'];
            $user  = User::where('email', $email)->first();
            if (! $user) continue;
            $account = Account::where('owner_user_id', $user->id)->first();
            if (! $account) continue;
            $accountMap[$oldId] = $account;
        }
        $this->info("  Mappati " . count($accountMap) . " utenti dump -> account kmoney");

        // ----------------------------------------------------------------
        // FASE 6: Raggruppa transazioni per TRX (controparte)
        // ----------------------------------------------------------------
        // TRX code uguale = stessa operazione (es: pagamento P2P o bonifico interno)
        $trxGroups = [];
        foreach ($transactions as $t) {
            $trxGroups[$t['trx']][] = $t;
        }

        // ----------------------------------------------------------------
        // FASE 7: Import transazioni
        // ----------------------------------------------------------------
        $this->info('');
        $this->info('Fase 6: Importazione transazioni...');

        $imported  = 0;
        $skipped   = 0;
        $errors    = 0;

        // Transazioni gia processate (evita doppio import per coppie TRX)
        $processedTrx = [];

        foreach ($transactions as $t) {
            $oldUserId = $t['user_id'];
            $account   = $accountMap[$oldUserId] ?? null;

            if (! $account) {
                $skipped++;
                continue;
            }

            $bookedAt  = CarbonImmutable::parse($t['created_at']);
            $amountKy = (int) max(1, (int) round((float) $t['amount']));
            $trx       = $t['trx'];
            $remark    = $t['remark'];
            $kind      = self::REMARK_KIND[$remark] ?? 'other';
            $trxType   = $t['trx_type'];

            // Cerca controparte
            $counterpartyAccount = null;
            if (isset($trxGroups[$trx]) && count($trxGroups[$trx]) === 2) {
                foreach ($trxGroups[$trx] as $peer) {
                    if ($peer['user_id'] !== $oldUserId) {
                        $counterpartyAccount = $accountMap[$peer['user_id']] ?? null;
                        break;
                    }
                }
            }

            // Per transazioni in coppia, importa una sola volta
            if ($counterpartyAccount && isset($processedTrx[$trx])) {
                continue;
            }

            try {
                // Determina from/to account
                if ($trxType === '+') {
                    // Credito: qualcuno ha inviato a questo utente
                    $fromAccount = $counterpartyAccount ?? $systemAccount;
                    $toAccount   = $account;
                } else {
                    // Debito: questo utente ha inviato a qualcuno
                    $fromAccount = $account;
                    $toAccount   = $counterpartyAccount ?? $systemAccount;
                }

                if (! $fromAccount || ! $toAccount) {
                    $skipped++;
                    continue;
                }

                DB::transaction(function () use (
                    $t, $fromAccount, $toAccount, $amountKy,
                    $kind, $bookedAt, $trx, &$imported
                ) {
                    $idempKey = 'fresh_txn_' . $t['id'];

                    $transfer = Transfer::create([
                        'from_account_id'  => $fromAccount->id,
                        'to_account_id'    => $toAccount->id,
                        'amount'           => $amountKy,
                        'currency'         => 'KY',
                        'kind'             => $kind,
                        'status'           => 'booked',
                        'booked_at'        => $bookedAt,
                        'description'      => $t['details'] ?: $t['remark'],
                        'idempotency_key'  => $idempKey,
                        'meta'             => [
                            'old_txn_id'    => $t['id'],
                            'old_user_id'   => $t['user_id'],
                            'trx_code'      => $t['trx'],
                            'remark'        => $t['remark'],
                            'imported_from' => 'kosmomoney_update_4',
                        ],
                    ]);

                    // LedgerEntry debit (from)
                    LedgerEntry::create([
                        'transfer_id'   => $transfer->id,
                        'account_id'    => $fromAccount->id,
                        'direction'     => 'debit',
                        'amount'        => $amountKy,
                        'balance_after' => 0,
                        'posted_at'     => $bookedAt,
                        'meta'          => ['imported_from' => 'kosmomoney_update_4'],
                    ]);

                    // LedgerEntry credit (to)
                    LedgerEntry::create([
                        'transfer_id'   => $transfer->id,
                        'account_id'    => $toAccount->id,
                        'direction'     => 'credit',
                        'amount'        => $amountKy,
                        'balance_after' => 0,
                        'posted_at'     => $bookedAt,
                        'meta'          => ['imported_from' => 'kosmomoney_update_4'],
                    ]);

                    $imported++;
                });

                if ($counterpartyAccount) {
                    $processedTrx[$trx] = true;
                }

            } catch (\Throwable $e) {
                $errors++;
                $this->warn("  Errore txn#{$t['id']}: " . $e->getMessage());
            }
        }

        $this->info("  Importati:  {$imported} transfer");
        $this->info("  Saltati:    {$skipped}");
        $this->info("  Errori:     {$errors}");

        // ----------------------------------------------------------------
        // RIEPILOGO FINALE
        // ----------------------------------------------------------------
        $this->newLine();
        $this->info('=== REIMPORT COMPLETATO ===');
        $this->info('  Account aggiornati:    ' . $balanceUpdated);
        $this->info('  Transfer importati:    ' . Transfer::count());
        $this->info('  LedgerEntry create:    ' . LedgerEntry::count());
        $this->info('  KY in circolazione:    ' . number_format(
            Account::where('is_system_account', false)->sum('available_balance'), 0, ',', '.'
        ) . ' KY');

        // Verifica Laura
        $lauraUser = User::where('email', 'gullilauretta@gmail.com')->first();
        if ($lauraUser) {
            $lauraAcc = Account::where('owner_user_id', $lauraUser->id)->first();
            $lauraTxns = Transfer::where('from_account_id', $lauraAcc?->id)
                ->orWhere('to_account_id', $lauraAcc?->id)
                ->count();
            $this->info("  Laura (lauragulli):    " . ky_format(($lauraAcc?->available_balance ?? 0)) . " KY — {$lauraTxns} transfer");
        }

        return 0;
    }

    // =========================================================================
    // PARSER
    // =========================================================================

    private function parseUsers(string $content): array
    {
        $byEmail = [];
        $byId    = [];
        $totalWallet = 0.0;

        preg_match_all('/INSERT INTO `users`[^;]+;/s', $content, $blocks);

        foreach ($blocks[0] as $block) {
            foreach ($this->parseInsertRows($block) as $row) {
                $fields = $this->splitFields($row);
                if (count($fields) < 68) continue;

                $id     = (int) ($fields[0] ?? 0);
                $email  = $this->stripQuotes((string) ($fields[15] ?? ''));
                $wallet = (float) ($fields[35] ?? 0);

                if (! $email || ! str_contains($email, '@')) continue;

                $userData = [
                    'id' => $id,
                    'email' => $email,
                    'wallet' => $wallet,
                    'account_type' => $this->stripQuotes((string) ($fields[3] ?? '')),
                    'store_plan_purchase' => $this->stripQuotes((string) ($fields[6] ?? '')),
                    'status' => (int) ($fields[44] ?? 1),
                    'daily_transfer_limit' => $fields[63] ?? null,
                    'monthly_transfer_limit' => $fields[64] ?? null,
                    'minimum_balance_limit' => $fields[66] ?? null,
                    'maximum_balance_limit' => $fields[67] ?? null,
                ];

                $byEmail[$email] = $userData;
                $byId[$id]       = $userData;
                $totalWallet += $wallet;
            }
        }

        return ['by_email' => $byEmail, 'by_id' => $byId, 'count' => count($byEmail), 'total_wallet' => $totalWallet];
    }

    private function parseTransactions(string $content): array
    {
        $transactions = [];
        preg_match_all('/INSERT INTO `transactions`[^;]+;/s', $content, $blocks);

        foreach ($blocks[0] as $block) {
            foreach ($this->parseInsertRows($block) as $row) {
                $fields = $this->splitFields($row);
                // id, user_id, branch_id, branch_staff_id, amount, charge, post_balance, trx_type, trx, details, remark, order_number, site_url, created_at, updated_at
                if (count($fields) < 14) continue;

                $transactions[] = [
                    'id'           => (int)    ($fields[0]  ?? 0),
                    'user_id'      => (int)    ($fields[1]  ?? 0),
                    'amount'       => (float)  ($fields[4]  ?? 0),
                    'post_balance' => (float)  ($fields[6]  ?? 0),
                    'trx_type'     => $this->stripQuotes((string) ($fields[7] ?? '')),
                    'trx'          => $this->stripQuotes((string) ($fields[8] ?? '')),
                    'details'      => $this->stripQuotes((string) ($fields[9] ?? '')),
                    'remark'       => $this->stripQuotes((string) ($fields[10] ?? '')),
                    'created_at'   => $this->stripQuotes((string) ($fields[13] ?? now())),
                ];
            }
        }

        return $transactions;
    }

    private function stripQuotes(string $val): string
    {
        if (strlen($val) >= 2 && $val[0] === "'" && $val[-1] === "'") {
            return str_replace(["\'", '\\\\', '\\n', '\\r'], ["'", '\\', "\n", "\r"], substr($val, 1, -1));
        }
        return $val;
    }

    private function mapHolderType(array $old): string
    {
        return strtolower(trim((string) ($old['account_type'] ?? ''))) === 'individual'
            ? 'private'
            : 'company';
    }

    private function mapSubscriptionPlan(mixed $value): ?string
    {
        $plan = strtolower(trim((string) $value));

        return match ($plan) {
            'ecommerce' => 'ecommerce',
            'vetrina' => 'vetrina',
            'biglietto', 'biglietto da visita', 'business card' => 'biglietto',
            'anagrafica' => 'anagrafica',
            default => null,
        };
    }

    private function mapLegacyLimits(array $old): array
    {
        $minimumBalance = $this->legacyInteger($old['minimum_balance_limit'] ?? null);

        return [
            'negative_balance_limit' => $minimumBalance < 0 ? abs($minimumBalance) : 0,
            'daily_transaction_limit' => $this->legacyNullableInteger($old['daily_transfer_limit'] ?? null),
            'monthly_transaction_limit' => $this->legacyNullableInteger($old['monthly_transfer_limit'] ?? null),
            'max_balance' => $this->legacyNullableInteger($old['maximum_balance_limit'] ?? null),
        ];
    }

    private function legacyNullableInteger(mixed $value): ?int
    {
        $value = $this->stripQuotes((string) $value);

        if ($value === '' || strtoupper($value) === 'NULL') {
            return null;
        }

        return (int) round((float) $value);
    }

    private function legacyInteger(mixed $value): int
    {
        return $this->legacyNullableInteger($value) ?? 0;
    }

    private function parseInsertRows(string $block): array
    {
        $valuesPos = strpos($block, 'VALUES');
        if ($valuesPos === false) return [];
        $text  = rtrim(substr($block, $valuesPos + 6), ';');
        $rows  = []; $depth = 0; $buf = ''; $inStr = false; $esc = false; $sc = '';
        for ($i = 0, $len = strlen($text); $i < $len; $i++) {
            $c = $text[$i];
            if ($esc) { $buf .= $c; $esc = false; }
            elseif ($c === '\\' && $inStr) { $buf .= $c; $esc = true; }
            elseif ($inStr) { $buf .= $c; if ($c === $sc) $inStr = false; }
            elseif ($c === "'" || $c === '"') { $inStr = true; $sc = $c; $buf .= $c; }
            elseif ($c === '(') { if ($depth === 0) $buf = ''; else $buf .= $c; $depth++; }
            elseif ($c === ')') { $depth--; if ($depth === 0) { $rows[] = $buf; $buf = ''; } else $buf .= $c; }
            elseif ($depth > 0) { $buf .= $c; }
        }
        return $rows;
    }

    private function splitFields(string $row): array
    {
        $fields = []; $buf = ''; $inStr = false; $esc = false; $sc = ''; $depth = 0;
        for ($i = 0, $len = strlen($row); $i < $len; $i++) {
            $c = $row[$i];
            if ($esc) { $buf .= $c; $esc = false; }
            elseif ($c === '\\' && $inStr) { $buf .= $c; $esc = true; }
            elseif ($inStr) { $buf .= $c; if ($c === $sc) $inStr = false; }
            elseif ($c === "'" || $c === '"') { $inStr = true; $sc = $c; $buf .= $c; }
            elseif (($c === '(' || $c === '{' || $c === '[') && ! $inStr) { $depth++; $buf .= $c; }

            elseif (($c === ')' || $c === '}' || $c === ']') && ! $inStr) { $depth--; $buf .= $c; }
            elseif ($c === ',' && $depth === 0) { $fields[] = trim($buf); $buf = ''; }
            else { $buf .= $c; }
        }
        if ($buf !== '') $fields[] = trim($buf);
        return $fields;
    }
}
