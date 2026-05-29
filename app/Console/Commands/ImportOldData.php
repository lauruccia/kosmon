<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\Company;
use App\Models\Transfer;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Importa tutti i dati dal vecchio sito KosmOMoney.
 *
 * Uso:
 *   php artisan kmoney:import-old-data "C:\path\al\dump.sql"
 *
 * Cosa fa:
 *   1. Legge e parsa il dump SQL MySQL del vecchio sito
 *   2. Crea companies + users + accounts (1211 aziende)
 *   3. Crea transfers per crediti admin (balance_add, admin_deposit, deposit)
 *   4. Crea transfers peer-to-peer (balance_transfers)
 *
 * Pre-requisiti:
 *   - php artisan migrate:fresh              (pulisce il DB)
 *   - php artisan db:seed --class=RolesAndPermissionsSeeder
 *   (la migration 2026_05_27_110000 crea automaticamente la Cassa Circuito)
 *
 * Poi esegui:
 *   php artisan kmoney:import-old-data "percorso\al\dump.sql"
 *
 * Il super-admin viene creato automaticamente con email/password configurabili.
 */
class ImportOldData extends Command
{
    protected $signature = 'kmoney:import-old-data
        {file : Percorso al dump SQL del vecchio sito}
        {--admin-email=admin@kosmomoney.com : Email super admin da creare}
        {--admin-password=changeme123 : Password super admin (CAMBIALA dopo il primo login!)}
        {--skip-transfers : Salta l\'importazione delle transazioni/trasferimenti}
        {--dry-run : Mostra cosa verrebbe fatto senza modificare il DB}';

    protected $description = 'Importa utenti, saldi e transazioni dal vecchio sito KosmOMoney';

    // Tipi di transazione che corrispondono a crediti admin
    private const CREDIT_REMARKS = ['balance_add', 'admin_deposit', 'deposit', 'wallet_add'];

    private bool $dryRun = false;
    private int $imported = 0;
    private int $skipped  = 0;
    private int $errors   = 0;
    private array $log    = [];

    public function handle(): int
    {
        $file = $this->argument('file');

        if (! file_exists($file)) {
            $this->error("File non trovato: {$file}");
            return 1;
        }

        $this->dryRun = (bool) $this->option('dry-run');

        if ($this->dryRun) {
            $this->warn('*** DRY-RUN attivo — nessuna modifica al database ***');
        }

        $this->info('Leggendo dump SQL... (potrebbe richiedere qualche secondo)');
        $content = file_get_contents($file);
        $this->info('  OK — ' . round(strlen($content) / 1024 / 1024, 1) . ' MB letti');

        // --------------------------------------------------------
        // FASE 1 — Super Admin
        // --------------------------------------------------------
        $this->importSuperAdmin();

        // --------------------------------------------------------
        // FASE 2 — Utenti → Companies + Users + Accounts
        // --------------------------------------------------------
        $this->importUsers($content);

        // --------------------------------------------------------
        // FASE 3 — Transazioni credito
        // --------------------------------------------------------
        if (! $this->option('skip-transfers')) {
            $this->importCreditTransactions($content);
            $this->importBalanceTransfers($content);
        }

        // --------------------------------------------------------
        // RIEPILOGO
        // --------------------------------------------------------
        $this->newLine();
        $this->info('=== MIGRAZIONE COMPLETATA ===');
        $this->info("  Importati: {$this->imported}");
        $this->info("  Saltati:   {$this->skipped}");
        if ($this->errors > 0) {
            $this->warn("  Errori:    {$this->errors}");
        }

        if (! empty($this->log)) {
            $logPath = storage_path('logs/import_old_data_' . date('Ymd_His') . '.log');
            file_put_contents($logPath, implode("\n", $this->log));
            $this->info("  Log errori: {$logPath}");
        }

        $this->newLine();
        $this->info('Conteggi nel nuovo DB:');
        $this->info('  companies: ' . Company::count());
        $this->info('  users:     ' . User::count());
        $this->info('  accounts:  ' . Account::where('is_system_account', false)->count());
        $this->info('  transfers: ' . Transfer::count());

        if ($this->dryRun) {
            $this->warn('*** DRY-RUN: nessuna modifica è stata eseguita ***');
        } else {
            $this->newLine();
            $this->info('✓ Migrazione completata con successo!');
            $this->info('  Login admin: ' . $this->option('admin-email'));
            $this->warn('  IMPORTANTE: cambia la password admin subito dopo il primo login!');
        }

        return 0;
    }

    // =========================================================================
    // SUPER ADMIN
    // =========================================================================

    private function importSuperAdmin(): void
    {
        $this->info('Fase 1: Creo super admin...');

        $email = $this->option('admin-email');

        if (User::where('email', $email)->exists()) {
            $this->line("  Super admin già presente: {$email}");
            return;
        }

        if ($this->dryRun) {
            $this->line("  [DRY-RUN] Creerebbe super admin: {$email}");
            return;
        }

        User::create([
            'company_id'          => null,
            'account_holder_type' => 'company',
            'name'                => 'KMoney Admin',
            'email'               => $email,
            'email_verified_at'   => now(),
            'password'            => Hash::make($this->option('admin-password')),
            'role'                => 'system-superadmin',
            'is_active'           => true,
            'is_super_admin'      => true,
        ]);

        $this->info("  ✓ Super admin creato: {$email}");
    }

    // =========================================================================
    // UTENTI
    // =========================================================================

    private function importUsers(string $content): void
    {
        $this->info('Fase 2: Importo aziende, utenti e conti...');

        // Crea tabella mapping se non esiste
        if (! $this->dryRun) {
            DB::statement('
                CREATE TABLE IF NOT EXISTS migration_user_map (
                    old_user_id    INTEGER NOT NULL PRIMARY KEY,
                    new_company_id INTEGER,
                    new_user_id    INTEGER,
                    new_account_id INTEGER
                )
            ');
        }

        // Estrai tutti i blocchi INSERT INTO `users`
        $allUsers    = $this->extractUsers($content);
        $total       = count($allUsers);
        $this->info("  Trovati {$total} utenti nel vecchio DB");

        $slugSeen    = [];
        $processed   = 0;

        foreach ($allUsers as $old) {
            $processed++;
            if ($processed % 200 === 0) {
                $this->line("  ... {$processed}/{$total}");
            }

            // Già importato?
            if (! $this->dryRun && DB::table('migration_user_map')->where('old_user_id', $old['id'])->exists()) {
                $this->skipped++;
                continue;
            }

            // Email duplicata?
            if (! $this->dryRun && User::where('email', $old['email'])->exists()) {
                $this->skipped++;
                $this->log[] = "SKIP email duplicata: old_id={$old['id']} {$old['email']}";
                continue;
            }

            if ($this->dryRun) {
                $this->imported++;
                continue;
            }

            DB::beginTransaction();
            try {
                $holderType = $this->mapHolderType($old);
                $plan       = $holderType === 'company' ? $this->mapSubscriptionPlan($old['store_plan_purchase'] ?? null) : null;
                $limits     = $this->mapLegacyLimits($old);

                // Company
                $cname  = $this->resolveCompanyName($old);
                $company = null;

                $vatNumber = $this->nullIfBlank($old['vat_number']);
                $fiscalCode = $this->nullIfBlank($old['tax_code']);

                if ($holderType === 'company') {
                    $slug = $this->uniqueSlug($cname, $old['username'] ?? '', $slugSeen);

                    // Evita duplicati su vat_number/fiscal_code: nel vecchio DB erano
                    // spesso placeholder riusati da piu' profili.
                    if ($vatNumber !== null && Company::where('vat_number', $vatNumber)->exists()) {
                        $vatNumber = null;
                    }
                    if ($fiscalCode !== null && Company::where('fiscal_code', $fiscalCode)->exists()) {
                        $fiscalCode = null;
                    }

                    $company = Company::create([
                        'uuid'              => (string) Str::uuid(),
                        'name'              => $cname,
                        'slug'              => $slug,
                        'email'             => $old['email'],
                        'vat_number'        => $vatNumber,
                        'fiscal_code'       => $fiscalCode,
                        'status'            => $this->mapStatus($old['kv'], $old['status']),
                        'subscription_plan' => $plan,
                        'kyc_status'        => $old['kv'] == 1 ? 'approved' : 'pending',
                        'currency_code'     => 'KY',
                        'approved_at'       => $old['kv'] == 1 ? $old['updated_at'] : null,
                        'created_at'        => $old['created_at'],
                        'updated_at'        => $old['updated_at'],
                    ]);
                }

                $userFiscalCode = $fiscalCode;
                if ($userFiscalCode !== null && User::where('fiscal_code', $userFiscalCode)->exists()) {
                    $userFiscalCode = null;
                }

                // User
                $uname = trim(($old['firstname'] ?? '') . ' ' . ($old['lastname'] ?? '')) ?: $cname;
                $user  = User::create([
                    'company_id'          => $company?->id,
                    'account_holder_type' => $holderType,
                    'name'                => $uname,
                    'email'               => $old['email'],
                    'email_verified_at'   => $old['ev'] == 1 ? $old['created_at'] : null,
                    'password'            => $old['password'], // hash bcrypt diretto
                    'phone'               => $this->nullIfBlank($old['mobile']),
                    'fiscal_code'         => $userFiscalCode,
                    'role'                => $holderType === 'company' ? 'owner' : 'private-owner',
                    'is_active'           => $old['status'] == 1,
                    'is_super_admin'      => false,
                    'circuit_capacity_limit' => null,
                    'negative_balance_limit' => $limits['negative_balance_limit'],
                    'daily_transaction_limit' => $limits['daily_transaction_limit'],
                    'monthly_transaction_limit' => $limits['monthly_transaction_limit'],
                    'per_movement_limit' => null,
                    'transfer_limits_use_defaults' => false,
                    'created_at'          => $old['created_at'],
                    'updated_at'          => $old['updated_at'],
                ]);

                // Account (balance in centesimi)
                $balanceCents = (int) round($old['balance'] * 100);
                $account = Account::create([
                    'company_id'             => $company?->id,
                    'owner_user_id'          => $user->id,
                    'owner_type'             => $holderType,
                    'type'                   => 'main',
                    'account_name'           => $holderType === 'company' ? 'Conto principale ' . $cname : 'Conto personale ' . $uname,
                    'currency_code'          => 'KY',
                    'status'                 => $old['status'] == 1 ? 'active' : 'suspended',
                    'allow_negative_balance' => $limits['negative_balance_limit'] > 0,
                    'is_system_account'      => false,
                    'available_balance'      => $balanceCents,
                    'pending_balance'        => 0,
                    'max_balance'            => $limits['max_balance'],
                    'daily_outgoing_limit'   => $limits['daily_transaction_limit'],
                    'created_at'             => $old['created_at'],
                    'updated_at'             => $old['updated_at'],
                ]);

                // Salva mapping
                DB::table('migration_user_map')->insert([
                    'old_user_id'    => $old['id'],
                    'new_company_id' => $company?->id,
                    'new_user_id'    => $user->id,
                    'new_account_id' => $account->id,
                ]);

                DB::commit();
                $this->imported++;

            } catch (\Throwable $e) {
                DB::rollBack();
                $this->errors++;
                $this->log[] = "ERROR user old_id={$old['id']} {$old['email']}: {$e->getMessage()}";
            }
        }

        $this->info("  ✓ Importati {$this->imported} | Saltati {$this->skipped} | Errori {$this->errors}");
    }

    // =========================================================================
    // TRANSAZIONI CREDITO ADMIN
    // =========================================================================

    private function importCreditTransactions(string $content): void
    {
        $this->info('Fase 3: Importo crediti admin (balance_add, admin_deposit, deposit)...');

        $systemAccount = Account::where('is_system_account', true)->first();
        if (! $systemAccount) {
            $this->error('  Cassa Circuito non trovata! Esegui prima le migration.');
            return;
        }

        $imported = 0;
        $skipped  = 0;

        foreach (['transactions', 'transactions_new'] as $table) {
            $rows = $this->extractTransactions($content, $table);

            foreach ($rows as $row) {
                if (! in_array($row['remark'], self::CREDIT_REMARKS)) continue;
                if ($row['trx_type'] === '-') continue;

                $ikey = "mig_{$table}_{$row['id']}";
                if (! $this->dryRun && Transfer::where('idempotency_key', $ikey)->exists()) {
                    $skipped++;
                    continue;
                }

                $map = $this->dryRun ? null : DB::table('migration_user_map')
                    ->where('old_user_id', $row['user_id'])->first();

                if (! $this->dryRun && ! $map) { $skipped++; continue; }

                if ($this->dryRun) { $imported++; continue; }

                try {
                    Transfer::create([
                        'uuid'            => (string) Str::uuid(),
                        'reference'       => 'KM-MIG-' . strtoupper(Str::random(8)),
                        'initiated_by'    => null,
                        'from_account_id' => $systemAccount->id,
                        'to_account_id'   => $map->new_account_id,
                        'amount'          => (int) round($row['amount'] * 100),
                        'currency_code'   => 'KY',
                        'status'          => 'completed',
                        'kind'            => 'admin_credit',
                        'idempotency_key' => $ikey,
                        'description'     => $row['details'] ?: $row['remark'],
                        'booked_at'       => $row['created_at'],
                        'created_at'      => $row['created_at'],
                        'updated_at'      => $row['created_at'],
                    ]);
                    $imported++;
                } catch (\Throwable $e) {
                    $skipped++;
                    $this->log[] = "ERROR credit_trans {$table} id={$row['id']}: {$e->getMessage()}";
                }
            }
        }

        $this->info("  ✓ Crediti admin: {$imported} importati, {$skipped} saltati");
        $this->imported += $imported;
        $this->skipped  += $skipped;
    }

    // =========================================================================
    // BALANCE TRANSFERS (P2P)
    // =========================================================================

    private function importBalanceTransfers(string $content): void
    {
        $this->info('Fase 4: Importo trasferimenti peer-to-peer...');

        // Carica beneficiaries in memoria
        $beneficiaries = $this->extractBeneficiaries($content);
        // Mappa account_number → old user_id (fallback)
        $accToUser = $this->buildAccountToUserMap($content);

        $imported = 0;
        $skipped  = 0;

        $rows = $this->extractBalanceTransfers($content);

        foreach ($rows as $bt) {
            $ikey = "mig_bt_{$bt['id']}";
            if (! $this->dryRun && Transfer::where('idempotency_key', $ikey)->exists()) {
                $skipped++;
                continue;
            }

            $ben = $beneficiaries[$bt['beneficiary_id']] ?? null;
            $receiverOldId = $ben['beneficiary_id'] ?? null;
            if (! $receiverOldId && isset($ben['account_number'])) {
                $receiverOldId = $accToUser[$ben['account_number']] ?? null;
            }

            if ($this->dryRun) { $imported++; continue; }

            $senderMap   = DB::table('migration_user_map')->where('old_user_id', $bt['user_id'])->first();
            $receiverMap = $receiverOldId ? DB::table('migration_user_map')->where('old_user_id', $receiverOldId)->first() : null;

            if (! $senderMap || ! $receiverMap) {
                $skipped++;
                $this->log[] = "SKIP bt_id={$bt['id']} sender={$bt['user_id']} receiver={$receiverOldId}: non mappato";
                continue;
            }

            if ($senderMap->new_account_id === $receiverMap->new_account_id) {
                $skipped++;
                continue;
            }

            try {
                Transfer::create([
                    'uuid'            => (string) Str::uuid(),
                    'reference'       => 'KM-MIG-BT-' . $bt['id'],
                    'initiated_by'    => $senderMap->new_user_id,
                    'from_account_id' => $senderMap->new_account_id,
                    'to_account_id'   => $receiverMap->new_account_id,
                    'amount'          => (int) round($bt['amount'] * 100),
                    'currency_code'   => 'KY',
                    'status'          => 'completed',
                    'kind'            => 'trade_payment',
                    'idempotency_key' => $ikey,
                    'description'     => 'Storico trasferimento (trx: ' . $bt['trx'] . ')',
                    'booked_at'       => $bt['created_at'],
                    'created_at'      => $bt['created_at'],
                    'updated_at'      => $bt['created_at'],
                ]);
                $imported++;
            } catch (\Throwable $e) {
                $skipped++;
                $this->log[] = "ERROR bt_id={$bt['id']}: {$e->getMessage()}";
            }
        }

        $this->info("  ✓ P2P: {$imported} importati, {$skipped} saltati");
        $this->imported += $imported;
        $this->skipped  += $skipped;
    }

    // =========================================================================
    // PARSER SQL
    // =========================================================================

    private function extractUsers(string $content): array
    {
        $users     = [];
        $seenEmail = [];

        // Trova tutti i blocchi INSERT INTO `users`
        preg_match_all('/INSERT INTO `users`[^;]+;/s', $content, $matches);

        foreach ($matches[0] as $block) {
            foreach ($this->parseInsertRows($block) as $row) {
                $v = $this->splitFields($row);
                if (count($v) < 62) continue;

                $email = strtolower(trim($v[15] ?? ''));
                if (empty($email) || isset($seenEmail[$email])) continue;
                $seenEmail[$email] = true;

                $users[] = [
                    'id'             => (int) $v[0],
                    'account_type'   => $v[3] ?? null,
                    'user_type'      => $v[4] ?? null,
                    'store_type'     => $v[5] ?? null,
                    'store_plan_purchase' => $v[6] ?? null,
                    'company_name'   => $v[7],
                    'vat_number'     => $v[8],
                    'account_number' => $v[11],
                    'firstname'      => $v[12],
                    'lastname'       => $v[13],
                    'username'       => $v[14],
                    'email'          => $v[15],
                    'mobile'         => $v[17],
                    'balance'        => (float) ($v[34] ?? 0),
                    'password'       => $v[41],
                    'status'         => (int) ($v[44] ?? 1),
                    'ev'             => (int) ($v[45] ?? 0),
                    'tax_code'       => $v[49] ?? null,
                    'kv'             => (int) ($v[53] ?? 0),
                    'created_at'     => $v[61] ?? now()->toDateTimeString(),
                    'updated_at'     => $v[62] ?? now()->toDateTimeString(),
                    'daily_transfer_limit' => $v[63] ?? null,
                    'monthly_transfer_limit' => $v[64] ?? null,
                    'minimum_transfer_limit' => $v[65] ?? null,
                    'minimum_balance_limit' => $v[66] ?? null,
                    'maximum_balance_limit' => $v[67] ?? null,
                ];
            }
        }

        return $users;
    }

    private function extractTransactions(string $content, string $table): array
    {
        $rows = [];
        preg_match_all('/INSERT INTO `' . $table . '`[^;]+;/s', $content, $matches);

        foreach ($matches[0] as $block) {
            foreach ($this->parseInsertRows($block) as $row) {
                $v = $this->splitFields($row);
                if (count($v) < 12) continue;
                $rows[] = [
                    'id'       => (int) $v[0],
                    'user_id'  => (int) $v[1],
                    'amount'   => (float) ($v[4] ?? 0),
                    'trx_type' => $v[7] ?? '',
                    'trx'      => $v[8] ?? '',
                    'details'  => $v[9] ?? '',
                    'remark'   => $v[10] ?? '',
                    'created_at' => $v[11] ?? now()->toDateTimeString(),
                ];
            }
        }

        return $rows;
    }

    private function extractBeneficiaries(string $content): array
    {
        $bens = [];
        preg_match_all('/INSERT INTO `beneficiaries`[^;]+;/s', $content, $matches);

        foreach ($matches[0] as $block) {
            foreach ($this->parseInsertRows($block) as $row) {
                $v = $this->splitFields($row);
                if (count($v) < 5) continue;
                $bens[(int) $v[0]] = [
                    'beneficiary_id' => $v[3] !== null ? (int) $v[3] : null,
                    'account_number' => $v[4],
                ];
            }
        }

        return $bens;
    }

    private function buildAccountToUserMap(string $content): array
    {
        $map = [];
        preg_match_all('/INSERT INTO `users`[^;]+;/s', $content, $matches);

        foreach ($matches[0] as $block) {
            foreach ($this->parseInsertRows($block) as $row) {
                $v = $this->splitFields($row);
                if (count($v) < 12) continue;
                if (! empty($v[11])) {
                    $map[$v[11]] = (int) $v[0];
                }
            }
        }

        return $map;
    }

    private function extractBalanceTransfers(string $content): array
    {
        $rows = [];
        preg_match_all('/INSERT INTO `balance_transfers`[^;]+;/s', $content, $matches);

        foreach ($matches[0] as $block) {
            foreach ($this->parseInsertRows($block) as $row) {
                $v = $this->splitFields($row);
                if (count($v) < 10) continue;
                if ((int) ($v[8] ?? 0) !== 1) continue; // solo completati (status=1)
                $rows[] = [
                    'id'             => (int) $v[0],
                    'user_id'        => (int) $v[1],
                    'beneficiary_id' => (int) $v[2],
                    'trx'            => $v[3] ?? '',
                    'amount'         => (float) ($v[4] ?? 0),
                    'created_at'     => $v[9] ?? now()->toDateTimeString(),
                ];
            }
        }

        return $rows;
    }

    // =========================================================================
    // PARSING GENERICO SQL
    // =========================================================================

    /**
     * Estrae le righe raw da un blocco INSERT INTO ... VALUES (...),(...),...;
     * Gestisce stringhe con caratteri speciali, escape, virgole interne.
     */
    private function parseInsertRows(string $block): array
    {
        $valuesPos = strpos($block, 'VALUES');
        if ($valuesPos === false) return [];

        $text  = rtrim(substr($block, $valuesPos + 6), ';');
        $rows  = [];
        $depth = 0;
        $buf   = '';
        $inStr = false;
        $esc   = false;
        $sc    = '';
        $len   = strlen($text);

        for ($i = 0; $i < $len; $i++) {
            $c = $text[$i];

            if ($esc) {
                $buf .= $c;
                $esc = false;
            } elseif ($c === '\\' && $inStr) {
                $buf .= $c;
                $esc = true;
            } elseif ($inStr) {
                $buf .= $c;
                if ($c === $sc) $inStr = false;
            } elseif ($c === "'" || $c === '"') {
                $inStr = true;
                $sc    = $c;
                $buf  .= $c;
            } elseif ($c === '(') {
                if ($depth === 0) $buf = '';
                else $buf .= $c;
                $depth++;
            } elseif ($c === ')') {
                $depth--;
                if ($depth === 0) {
                    $rows[] = $buf;
                    $buf    = '';
                } else {
                    $buf .= $c;
                }
            } elseif ($depth > 0) {
                $buf .= $c;
            }
        }

        return $rows;
    }

    /**
     * Divide una riga SQL raw in array di valori PHP.
     * Gestisce stringhe con virgole, NULL, numeri, escape.
     */
    private function splitFields(string $row): array
    {
        $fields = [];
        $buf    = '';
        $inStr  = false;
        $esc    = false;
        $sc     = '';
        $depth  = 0;

        for ($i = 0, $len = strlen($row); $i < $len; $i++) {
            $c = $row[$i];

            if ($esc) {
                $buf .= $c;
                $esc = false;
            } elseif ($c === '\\' && $inStr) {
                $buf .= $c;
                $esc = true;
            } elseif ($inStr) {
                $buf .= $c;
                if ($c === $sc) $inStr = false;
            } elseif ($c === "'" || $c === '"') {
                $inStr = true;
                $sc    = $c;
                $buf  .= $c;
            } elseif (($c === '(' || $c === '{' || $c === '[') && ! $inStr) {
                $depth++;
                $buf .= $c;
            } elseif (($c === ')' || $c === '}' || $c === ']') && ! $inStr) {
                $depth--;
                $buf .= $c;
            } elseif ($c === ',' && $depth === 0) {
                $fields[] = $this->convertField(trim($buf));
                $buf      = '';
            } else {
                $buf .= $c;
            }
        }

        if ($buf !== '') {
            $fields[] = $this->convertField(trim($buf));
        }

        return $fields;
    }

    /**
     * Converte un valore SQL raw in valore PHP appropriato.
     */
    private function convertField(string $val): mixed
    {
        if (strtoupper($val) === 'NULL') {
            return null;
        }

        if (strlen($val) >= 2 && $val[0] === "'" && $val[-1] === "'") {
            $s = substr($val, 1, -1);
            $s = str_replace(["\'", '\\\\', '\\n', '\\r', '\\t'], ["'", '\\', "\n", "\r", "\t"], $s);
            return $s;
        }

        if (is_numeric($val)) {
            return strpos($val, '.') !== false ? (float) $val : (int) $val;
        }

        return $val;
    }

    // =========================================================================
    // HELPER
    // =========================================================================

    private function resolveCompanyName(array $u): string
    {
        $name = trim($u['company_name'] ?? '');
        if ($name !== '') return $name;

        $name = trim(($u['firstname'] ?? '') . ' ' . ($u['lastname'] ?? ''));
        if ($name !== '') return $name;

        return $u['username'] ?? ('Azienda ' . $u['id']);
    }

    private function uniqueSlug(string $companyName, string $username, array &$seen): string
    {
        $base = Str::slug($username ?: $companyName);
        if (empty($base)) $base = 'azienda';

        $candidate = $base;
        $i = 1;
        while (isset($seen[$candidate]) || Company::where('slug', $candidate)->exists()) {
            $candidate = $base . '-' . $i++;
        }
        $seen[$candidate] = true;

        return $candidate;
    }

    private function mapStatus(int $kv, int $status): string
    {
        if ($status === 0) return 'suspended';
        return 'active';
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

    /**
     * I limiti legacy sono salvati in KY interi nel vecchio DB.
     * minimum_transfer_limit e' un minimo operativo, non un massimale: la nuova
     * app non ha un campo equivalente, quindi non va importato in per_movement.
     */
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
        if ($value === null || $value === '') {
            return null;
        }

        return (int) round((float) $value);
    }

    private function legacyInteger(mixed $value): int
    {
        return $this->legacyNullableInteger($value) ?? 0;
    }

    private function nullIfBlank(mixed $v): ?string
    {
        if ($v === null) return null;
        $s = trim((string) $v);
        return ($s === '' || $s === '0') ? null : $s;
    }
}
