<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\LedgerEntry;
use App\Models\Transfer;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Importa TUTTE le transazioni storiche dal vecchio sito KosmOMoney.
 *
 * Il comando kmoney:import-old-data importava solo i crediti admin
 * (balance_add, admin_deposit, deposit, wallet_add) e i balance_transfers P2P.
 * Questo comando importa TUTTO il resto: prodotti venduti, KyCard, ricevuti, ecc.
 *
 * Logica:
 *   - Legge le tabelle `transactions` e `transactions_new` dal dump SQL
 *   - Per ogni transazione trova il controparte usando il codice TRX:
 *       * Se esiste un'altra riga con lo stesso TRX e trx_type opposto -> Transfer tra utenti
 *       * Altrimenti -> Transfer verso/da la Cassa Circuito (sistema)
 *   - Crea Transfer con status='booked' SENZA aggiornare available_balance
 *     (i saldi sono gia corretti dall'import iniziale)
 *   - Crea LedgerEntry per ogni Transfer
 *   - Idempotente: usa idempotency_key per non duplicare
 *
 * Uso:
 *   php artisan kmoney:import-all-transactions "C:\path\dump.sql"
 *   php artisan kmoney:import-all-transactions "C:\path\dump.sql" --dry-run
 *   php artisan kmoney:import-all-transactions "C:\path\dump.sql" --user=lauragulli
 */
class ImportAllTransactions extends Command
{
    protected $signature = 'kmoney:import-all-transactions
        {file : Percorso al dump SQL del vecchio sito}
        {--dry-run : Mostra cosa farebbe senza modificare il DB}
        {--user=   : Importa solo le transazioni di un utente specifico (email o username)}
        {--reset-balances : Ricalcola available_balance da zero applicando tutti i transfer}';

    protected $description = 'Importa TUTTE le transazioni storiche (prodotti, KyCard, ecc.) dal dump SQL';

    // Mappa remark -> kind del nuovo sistema
    private const REMARK_KIND_MAP = [
        // Crediti admin (gia importati, ma gestiamo uguale per idempotenza)
        'balance_add'          => 'admin_credit',
        'admin_deposit'        => 'admin_credit',
        'deposit'              => 'admin_credit',
        'wallet_add'           => 'admin_credit',

        // P2P transfers
        'balance_transfer'     => 'trade_payment',
        'balance_receive'      => 'trade_payment',
        'send_money'           => 'trade_payment',
        'receive_money'        => 'trade_payment',
        'transfer'             => 'trade_payment',
        'received'             => 'trade_payment',

        // Prodotti / shop
        'product_purchased'    => 'trade_payment',
        'product_sale'         => 'trade_payment',
        'shop_purchase'        => 'trade_payment',
        'shop_sale'            => 'trade_payment',
        'sold_product'         => 'trade_payment',
        'sell_product'         => 'trade_payment',
        'buy_product'          => 'trade_payment',

        // KyCard
        'kycard_purchase'      => 'portal_kycard',
        'kycard'               => 'portal_kycard',
        'kycard_buy'           => 'portal_kycard',
        'kycard_sell'          => 'portal_kycard',
        'kycard_credit'        => 'portal_kycard',

        // Prelievi / addebiti admin
        'withdrawal'           => 'admin_debit',
        'prelievo'             => 'admin_debit',
        'admin_debit'          => 'admin_debit',
        'balance_remove'       => 'admin_debit',
        'deduction'            => 'admin_debit',

        // Commissioni / bonus
        'commission'           => 'commission',
        'referral_bonus'       => 'admin_credit',
        'bonus'                => 'admin_credit',
        'cashback'             => 'admin_credit',
    ];

    private const DEFAULT_KIND = 'trade_payment';

    private bool $dryRun      = false;
    private int  $imported    = 0;
    private int  $skipped     = 0;
    private int  $errors      = 0;
    private array $log        = [];

    public function handle(): int
    {
        $file = $this->argument('file');

        if (! file_exists($file)) {
            $this->error("File non trovato: {$file}");
            return 1;
        }

        $this->dryRun = (bool) $this->option('dry-run');

        if ($this->dryRun) {
            $this->warn('*** DRY-RUN attivo -- nessuna modifica al database ***');
        }

        $this->info('Leggendo dump SQL...');
        $content = file_get_contents($file);
        $this->info('  OK — ' . round(strlen($content) / 1024 / 1024, 1) . ' MB letti');

        // Carica la mappa old_user_id -> account
        $userMap = $this->loadUserMap();
        if (empty($userMap)) {
            $this->error('migration_user_map vuota! Esegui prima kmoney:import-old-data.');
            return 1;
        }
        $this->info("  Utenti mappati: " . count($userMap));

        // Cassa Circuito
        $systemAccount = Account::where('is_system_account', true)->first();
        if (! $systemAccount) {
            $this->error('Cassa Circuito non trovata. Esegui le migration.');
            return 1;
        }

        // Filtro utente opzionale
        $filterEmail = $this->option('user');

        // ----------------------------------------------------------------
        // Fase 1a: transazioni da TUTTE le tabelle transactions (+ backup)
        // ----------------------------------------------------------------
        $txnTables = [
            'transactions',
            'transactions_new',
            'transactions_backup',
            'transactions_back_2',
        ];
        $this->info('Fase 1a: Carico transazioni dalle tabelle transactions (+ backup)...');
        $allTxns = $this->loadAllTransactions($content, $txnTables);
        $this->info("  Trovate " . count($allTxns) . " transazioni uniche");

        // ----------------------------------------------------------------
        // Fase 1b: ordini prodotti da st_orders
        // ----------------------------------------------------------------
        $this->info('Fase 1b: Carico ordini prodotti da st_orders...');
        $orderTxns = $this->loadStOrders($content, $userMap);
        $this->info("  Trovati " . count($orderTxns) . " ordini mappabili");
        $allTxns = array_merge($allTxns, $orderTxns);

        // ----------------------------------------------------------------
        // Fase 1c: depositi da deposits
        // ----------------------------------------------------------------
        $this->info('Fase 1c: Carico depositi da deposits...');
        $depositTxns = $this->loadDeposits($content);
        $this->info("  Trovati " . count($depositTxns) . " depositi");
        $allTxns = array_merge($allTxns, $depositTxns);

        $this->info("  Totale da importare: " . count($allTxns));

        // Ordina cronologicamente
        usort($allTxns, fn($a, $b) => strcmp($a['created_at'], $b['created_at']));

        // Raggruppa per TRX per trovare i controparti
        $this->info('Fase 2: Costruisco mappa TRX per abbinare mittente/destinatario...');
        $trxMap = $this->buildTrxMap($allTxns);
        $this->info("  TRX unici: " . count($trxMap));

        // Importa
        $this->info('Fase 3: Importo transazioni mancanti...');
        $this->importTransactions($allTxns, $trxMap, $userMap, $systemAccount, $filterEmail);

        // Opzionale: ricalcola saldi
        if ($this->option('reset-balances') && ! $this->dryRun) {
            $this->info('Fase 4: Ricalcolo available_balance da zero...');
            $this->rebuildBalances($userMap, $systemAccount);
        }

        // Riepilogo
        $this->newLine();
        $this->info('=== IMPORTAZIONE COMPLETATA ===');
        $this->info("  Importati: {$this->imported}");
        $this->info("  Saltati:   {$this->skipped}  (gia presenti o non mappati)");
        if ($this->errors > 0) {
            $this->warn("  Errori:    {$this->errors}");
        }

        if (! empty($this->log)) {
            $logPath = storage_path('logs/import_txn_' . date('Ymd_His') . '.log');
            if (! $this->dryRun) {
                file_put_contents($logPath, implode("\n", $this->log));
                $this->info("  Log errori: {$logPath}");
            }
        }

        $this->newLine();
        $this->info('Conteggi nel DB:');
        $this->info('  Transfers totali: ' . Transfer::count());
        $this->info('  LedgerEntry:      ' . LedgerEntry::count());

        return 0;
    }

    // =========================================================================
    // CARICAMENTO DATI
    // =========================================================================

    private function loadUserMap(): array
    {
        $map = [];
        try {
            $rows = DB::table('migration_user_map')->get();
            foreach ($rows as $row) {
                $map[$row->old_user_id] = [
                    'account_id' => $row->new_account_id,
                    'user_id'    => $row->new_user_id,
                ];
            }
        } catch (\Throwable $e) {
            $this->error("Errore leggendo migration_user_map: {$e->getMessage()}");
        }
        return $map;
    }

    /**
     * Carica tutte le transazioni dalle tabelle indicate.
     * Deduplicazione basata su user_id + trx (stesso trasferimento non conta due volte).
     *
     * @param array $tables Elenco di tabelle da leggere (transactions, backup, ecc.)
     */
    private function loadAllTransactions(string $content, array $tables): array
    {
        $txns = [];
        $seen = [];

        foreach ($tables as $table) {
            $rows = $this->extractTransactionsAll($content, $table);
            $this->info("  {$table}: " . count($rows) . " righe");

            foreach ($rows as $row) {
                // Dedup: stesso utente + stesso codice TRX = stessa transazione
                $key = $row['user_id'] . '_' . ($row['trx'] ?: $row['id'] . '_' . $table);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $row['_table'] = $table;
                $txns[] = $row;
            }
        }

        return $txns;
    }

    /**
     * Legge la tabella st_orders e la converte in transazioni KY.
     * Ogni ordine completato genera:
     *   - un debito per il buyer (from_account = buyer, to_account = seller)
     *   - idempotency_key = mig_order_{id}
     *
     * Schema atteso di st_orders (colonne tipiche):
     *   id, buyer_id, seller_id, product_id, quantity, amount, status, created_at, updated_at
     *   (la posizione esatta viene rilevata automaticamente se non corrisponde)
     */
    private function loadStOrders(string $content, array $userMap): array
    {
        $txns = [];
        $seen = [];

        preg_match_all('/INSERT INTO `st_orders`[^;]+;/s', $content, $matches);

        foreach ($matches[0] as $block) {
            foreach ($this->parseInsertRows($block) as $row) {
                $v = $this->splitFields($row);
                if (count($v) < 6) continue;

                // Struttura tipica: id, buyer_id, seller_id, product_id, qty, amount, status, ..., created_at
                $id       = (int) ($v[0] ?? 0);
                $buyerId  = (int) ($v[1] ?? 0);
                $sellerId = (int) ($v[2] ?? 0);
                $amount   = (float) ($v[5] ?? 0);
                $status   = $v[6] ?? '';
                // created_at di solito e' terzultimo o ultimo campo
                $createdAt = $v[count($v) - 2] ?? $v[count($v) - 1] ?? now()->toDateTimeString();

                // Solo ordini completati
                if (! in_array((string) $status, ['1', 'completed', 'success', 'paid'], true)) {
                    continue;
                }

                if ($amount <= 0 || $buyerId === $sellerId) continue;

                // Entrambi devono essere mappati
                if (! isset($userMap[$buyerId]) || ! isset($userMap[$sellerId])) continue;

                $key = "order_{$id}";
                if (isset($seen[$key])) continue;
                $seen[$key] = true;

                // Costruiamo una riga simile alle transactions (dal punto di vista del venditore = credito)
                $txns[] = [
                    'id'         => $id,
                    'user_id'    => $sellerId,  // venditore riceve
                    'amount'     => $amount,
                    'trx_type'   => '+',
                    'trx'        => 'ORDER-' . $id,
                    'details'    => 'Prodotto venduto (ordine #' . $id . ')',
                    'remark'     => 'shop_sale',
                    'created_at' => is_string($createdAt) ? $createdAt : now()->toDateTimeString(),
                    '_table'     => 'st_orders',
                    // Controparte esplicita: buyer debita
                    '_counterparty_old_id' => $buyerId,
                ];
            }
        }

        return $txns;
    }

    /**
     * Legge la tabella deposits (ricariche da gateway).
     * Solo i depositi completati (status=1) vengono importati come admin_credit.
     *
     * Schema tipico: id, user_id, method_id, amount, from_amount, currency, rate,
     *                charge, trx, detail, information, status, created_at, updated_at
     */
    private function loadDeposits(string $content): array
    {
        $txns = [];
        $seen = [];

        preg_match_all('/INSERT INTO `deposits`[^;]+;/s', $content, $matches);

        foreach ($matches[0] as $block) {
            foreach ($this->parseInsertRows($block) as $row) {
                $v = $this->splitFields($row);
                if (count($v) < 12) continue;

                $id      = (int) ($v[0] ?? 0);
                $userId  = (int) ($v[1] ?? 0);
                $amount  = (float) ($v[3] ?? 0);
                $trx     = trim($v[8] ?? '');
                $status  = $v[11] ?? '';
                $createdAt = $v[12] ?? now()->toDateTimeString();

                // Solo completati
                if ((string) $status !== '1') continue;
                if ($amount <= 0 || $userId <= 0) continue;

                $key = "dep_{$id}";
                if (isset($seen[$key])) continue;
                $seen[$key] = true;

                $txns[] = [
                    'id'         => $id,
                    'user_id'    => $userId,
                    'amount'     => $amount,
                    'trx_type'   => '+',
                    'trx'        => $trx ?: 'DEP-' . $id,
                    'details'    => 'Deposito gateway #' . $id,
                    'remark'     => 'deposit',
                    'created_at' => is_string($createdAt) ? $createdAt : now()->toDateTimeString(),
                    '_table'     => 'deposits',
                ];
            }
        }

        return $txns;
    }

    /**
     * Costruisce una mappa TRX -> [row1, row2, ...] per trovare controparti.
     * Se due righe hanno lo stesso TRX e trx_type opposti, sono le due facce
     * dello stesso trasferimento.
     */
    private function buildTrxMap(array $txns): array
    {
        $map = [];
        foreach ($txns as $row) {
            if (! empty($row['trx'])) {
                $map[$row['trx']][] = $row;
            }
        }
        return $map;
    }

    // =========================================================================
    // IMPORTAZIONE TRANSAZIONI
    // =========================================================================

    private function importTransactions(
        array $allTxns,
        array $trxMap,
        array $userMap,
        Account $systemAccount,
        ?string $filterEmail
    ): void {
        // Se filtro utente, troviamo il suo old_user_id
        $filterOldUserId = null;
        if ($filterEmail) {
            $user = \App\Models\User::where('email', $filterEmail)
                ->orWhereHas('company', fn($q) => $q->where('slug', $filterEmail))
                ->first();
            if ($user) {
                $row = DB::table('migration_user_map')->where('new_user_id', $user->id)->first();
                $filterOldUserId = $row?->old_user_id;
                $this->info("  Filtro utente: {$filterEmail} -> old_user_id={$filterOldUserId}");
            } else {
                $this->warn("  Utente non trovato: {$filterEmail}");
            }
        }

        // Set di TRX gia processati (per non creare Transfer duplicati per P2P)
        $processedTrx = [];

        $total = count($allTxns);
        $done  = 0;

        foreach ($allTxns as $row) {
            $done++;
            if ($done % 500 === 0) {
                $this->line("  ... {$done}/{$total}");
            }

            // Filtro utente
            if ($filterOldUserId !== null && $row['user_id'] !== $filterOldUserId) {
                continue;
            }

            $ikey = "mig_txn_{$row['_table']}_{$row['id']}";

            // Gia importato?
            if (! $this->dryRun && Transfer::where('idempotency_key', $ikey)->exists()) {
                $this->skipped++;
                continue;
            }

            // Utente mappato?
            $userInfo = $userMap[$row['user_id']] ?? null;
            if (! $userInfo) {
                $this->skipped++;
                continue;
            }

            // Zero-amount? salta
            if ($row['amount'] <= 0) {
                $this->skipped++;
                continue;
            }

            // Determina tipo
            $kind     = $this->resolveKind($row['remark'] ?? '');
            $bookedAt = CarbonImmutable::parse($row['created_at']);

            // Trova controparte — prima controlla se e' esplicita (es. st_orders)
            $counterpartyInfo = null;
            if (isset($row['_counterparty_old_id']) && isset($userMap[$row['_counterparty_old_id']])) {
                $counterpartyInfo = $userMap[$row['_counterparty_old_id']];
            }

            // Altrimenti cerca via TRX code
            $trxCode = $row['trx'] ?? '';
            if (! $counterpartyInfo && $trxCode && isset($trxMap[$trxCode])) {
                foreach ($trxMap[$trxCode] as $other) {
                    if ($other['user_id'] !== $row['user_id']
                        && $other['trx_type'] !== $row['trx_type']
                        && isset($userMap[$other['user_id']])) {
                        $counterpartyInfo = $userMap[$other['user_id']];
                        break;
                    }
                }
            }

            // Determina from/to account
            [$fromAccountId, $toAccountId] = $this->resolveAccounts(
                $row['trx_type'],
                $kind,
                $userInfo['account_id'],
                $counterpartyInfo?->account_id ?? $counterpartyInfo['account_id'] ?? $systemAccount->id,
                $systemAccount->id
            );

            // TRX P2P gia processato dall'altro lato? Salta per evitare duplicato
            if ($counterpartyInfo && $trxCode) {
                $pairKey = min($fromAccountId, $toAccountId) . '_' . max($fromAccountId, $toAccountId) . '_' . $trxCode;
                if (isset($processedTrx[$pairKey])) {
                    $this->skipped++;
                    continue;
                }
                $processedTrx[$pairKey] = true;
            }

            if ($this->dryRun) {
                $this->imported++;
                continue;
            }

            try {
                DB::transaction(function () use (
                    $row, $ikey, $fromAccountId, $toAccountId, $kind, $bookedAt
                ) {
                    $transfer = Transfer::create([
                        'uuid'            => (string) Str::uuid(),
                        'reference'       => 'KM-MIG-TXN-' . strtoupper(Str::random(6)),
                        'initiated_by'    => null,
                        'from_account_id' => $fromAccountId,
                        'to_account_id'   => $toAccountId,
                        'amount'          => (int) round($row['amount'] * 100),
                        'currency_code'   => 'KY',
                        'status'          => 'booked',
                        'kind'            => $kind,
                        'idempotency_key' => $ikey,
                        'description'     => $this->resolveDescription($row),
                        'booked_at'       => $bookedAt,
                        'created_at'      => $bookedAt,
                        'updated_at'      => $bookedAt,
                    ]);

                    // LedgerEntry (balance_after = 0 per storico, non influisce sul saldo live)
                    LedgerEntry::create([
                        'transfer_id'   => $transfer->id,
                        'account_id'    => $fromAccountId,
                        'direction'     => 'debit',
                        'amount'        => $transfer->amount,
                        'balance_after' => 0,
                        'posted_at'     => $bookedAt,
                        'meta'          => ['counterparty_account_id' => $toAccountId, 'imported' => true],
                    ]);

                    LedgerEntry::create([
                        'transfer_id'   => $transfer->id,
                        'account_id'    => $toAccountId,
                        'direction'     => 'credit',
                        'amount'        => $transfer->amount,
                        'balance_after' => 0,
                        'posted_at'     => $bookedAt,
                        'meta'          => ['counterparty_account_id' => $fromAccountId, 'imported' => true],
                    ]);
                });

                $this->imported++;

            } catch (\Throwable $e) {
                $this->errors++;
                $this->log[] = "ERROR txn id={$row['id']} user={$row['user_id']}: {$e->getMessage()}";
            }
        }
    }

    // =========================================================================
    // RICALCOLO SALDI (opzionale con --reset-balances)
    // =========================================================================

    /**
     * Ricalcola available_balance per ogni account sommando tutti i transfer booked.
     * Utile se si vuole che il saldo rispecchi esattamente lo storico importato.
     *
     * ATTENZIONE: usa questo solo se sei sicuro che tutti i transfer storici
     * siano stati importati correttamente. Se mancano transazioni il saldo
     * risultante sara sbagliato.
     */
    private function rebuildBalances(array $userMap, Account $systemAccount): void
    {
        $count = 0;
        foreach ($userMap as $oldId => $info) {
            $accId = $info['account_id'];

            $credits = Transfer::where('to_account_id', $accId)
                ->where('status', 'booked')
                ->sum('amount');

            $debits = Transfer::where('from_account_id', $accId)
                ->where('status', 'booked')
                ->sum('amount');

            $balance = (int) $credits - (int) $debits;

            DB::table('accounts')->where('id', $accId)->update(['available_balance' => $balance]);
            $count++;
        }

        // Aggiorna anche la Cassa (debiti = totale emissioni)
        $sysCredits = Transfer::where('to_account_id', $systemAccount->id)->where('status', 'booked')->sum('amount');
        $sysDebits  = Transfer::where('from_account_id', $systemAccount->id)->where('status', 'booked')->sum('amount');
        DB::table('accounts')->where('id', $systemAccount->id)->update([
            'available_balance' => (int) $sysCredits - (int) $sysDebits,
        ]);

        $this->info("  Saldi aggiornati per {$count} conti + Cassa Circuito");
    }

    // =========================================================================
    // HELPER
    // =========================================================================

    private function resolveKind(string $remark): string
    {
        $remark = strtolower(trim($remark));
        return self::REMARK_KIND_MAP[$remark] ?? self::DEFAULT_KIND;
    }

    /**
     * Determina from/to account in base al tipo di transazione.
     *
     * Regole:
     *   trx_type='+' (credito per l'utente) -> soldi arrivano ALL'utente
     *     - admin_credit: da sistema -> a utente
     *     - trade_payment ricevuto: da controparte -> a utente
     *   trx_type='-' (debito per l'utente) -> soldi escono DALL'utente
     *     - admin_debit: da utente -> a sistema
     *     - pagamento effettuato: da utente -> a controparte
     */
    private function resolveAccounts(
        string $trxType,
        string $kind,
        int $userAccountId,
        int $counterpartyAccountId,
        int $systemAccountId
    ): array {
        if ($trxType === '+') {
            // L'utente ha ricevuto KY
            $from = ($kind === 'admin_credit' || $counterpartyAccountId === $systemAccountId)
                ? $systemAccountId
                : $counterpartyAccountId;
            $to = $userAccountId;
        } else {
            // L'utente ha inviato KY
            $from = $userAccountId;
            $to   = ($kind === 'admin_debit' || $counterpartyAccountId === $systemAccountId)
                ? $systemAccountId
                : $counterpartyAccountId;
        }

        return [$from, $to];
    }

    private function resolveDescription(array $row): string
    {
        $parts = [];
        if (! empty($row['details'])) {
            $parts[] = $row['details'];
        }
        if (! empty($row['remark'])) {
            $parts[] = '(' . $row['remark'] . ')';
        }
        if (! empty($row['trx'])) {
            $parts[] = 'Rif: ' . $row['trx'];
        }
        return implode(' — ', $parts) ?: 'Transazione storica';
    }

    // =========================================================================
    // PARSER SQL
    // =========================================================================

    private function extractTransactionsAll(string $content, string $table): array
    {
        $rows = [];
        preg_match_all('/INSERT INTO `' . preg_quote($table, '/') . '`[^;]+;/s', $content, $matches);

        foreach ($matches[0] as $block) {
            foreach ($this->parseInsertRows($block) as $row) {
                $v = $this->splitFields($row);
                if (count($v) < 10) continue;

                $amount = (float) ($v[4] ?? 0);
                if ($amount <= 0) continue;

                $rows[] = [
                    'id'         => (int) $v[0],
                    'user_id'    => (int) $v[1],
                    'amount'     => $amount,
                    'trx_type'   => trim($v[7] ?? '+'),
                    'trx'        => trim($v[8] ?? ''),
                    'details'    => trim($v[9] ?? ''),
                    'remark'     => trim($v[10] ?? ''),
                    'created_at' => trim($v[11] ?? now()->toDateTimeString()),
                ];
            }
        }

        return $rows;
    }

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
                $buf .= $c; $esc = false;
            } elseif ($c === '\\' && $inStr) {
                $buf .= $c; $esc = true;
            } elseif ($inStr) {
                $buf .= $c;
                if ($c === $sc) $inStr = false;
            } elseif ($c === "'" || $c === '"') {
                $inStr = true; $sc = $c; $buf .= $c;
            } elseif ($c === '(') {
                if ($depth === 0) $buf = ''; else $buf .= $c;
                $depth++;
            } elseif ($c === ')') {
                $depth--;
                if ($depth === 0) { $rows[] = $buf; $buf = ''; }
                else $buf .= $c;
            } elseif ($depth > 0) {
                $buf .= $c;
            }
        }

        return $rows;
    }

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
                $buf .= $c; $esc = false;
            } elseif ($c === '\\' && $inStr) {
                $buf .= $c; $esc = true;
            } elseif ($inStr) {
                $buf .= $c;
                if ($c === $sc) $inStr = false;
            } elseif ($c === "'" || $c === '"') {
                $inStr = true; $sc = $c; $buf .= $c;
            } elseif (($c === '(' || $c === '{' || $c === '[') && ! $inStr) {
                $depth++; $buf .= $c;
            } elseif (($c === ')' || $c === '}' || $c === ']') && ! $inStr) {
                $depth--; $buf .= $c;
            } elseif ($c === ',' && $depth === 0) {
                $fields[] = $this->convertField(trim($buf));
                $buf = '';
            } else {
                $buf .= $c;
            }
        }

        if ($buf !== '') {
            $fields[] = $this->convertField(trim($buf));
        }

        return $fields;
    }

    private function convertField(string $val): mixed
    {
        if (strtoupper($val) === 'NULL') return null;

        if (strlen($val) >= 2 && $val[0] === "'" && $val[-1] === "'") {
            $s = substr($val, 1, -1);
            return str_replace(["\'", '\\\\', '\\n', '\\r', '\\t'], ["'", '\\', "\n", "\r", "\t"], $s);
        }

        if (is_numeric($val)) {
            return strpos($val, '.') !== false ? (float) $val : (int) $val;
        }

        return $val;
    }
}
