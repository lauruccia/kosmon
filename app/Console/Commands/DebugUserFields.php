<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Mostra tutti i campi raw di un utente dal dump SQL.
 * Utile per trovare quale indice di campo contiene il saldo corretto.
 *
 * Uso:
 *   php artisan kmoney:debug-user-fields "C:\Users\Intel\Downloads\dump.sql" --old-id=9
 *   php artisan kmoney:debug-user-fields "C:\Users\Intel\Downloads\dump.sql" --email=gullilauretta@gmail.com
 *   php artisan kmoney:debug-user-fields "C:\Users\Intel\Downloads\dump.sql" --show-columns
 */
class DebugUserFields extends Command
{
    protected $signature = 'kmoney:debug-user-fields
        {file : Percorso al dump SQL}
        {--old-id=   : old user_id da cercare}
        {--email=    : email da cercare}
        {--show-columns : Mostra le intestazioni delle colonne della tabella users}';

    protected $description = 'Mostra i campi raw di un utente nel dump SQL per diagnosticare mappature';

    public function handle(): int
    {
        $file = $this->argument('file');
        if (! file_exists($file)) {
            $this->error("File non trovato: {$file}");
            return 1;
        }

        $this->info("Leggendo dump...");
        $content = file_get_contents($file);

        // ----------------------------------------------------------------
        // Mostra le colonne della tabella users (da CREATE TABLE)
        // ----------------------------------------------------------------
        if ($this->option('show-columns')) {
            $this->showColumns($content, 'users');
            return 0;
        }

        // ----------------------------------------------------------------
        // Trova l'utente
        // ----------------------------------------------------------------
        $oldId    = $this->option('old-id');
        $email    = $this->option('email');

        if (! $oldId && ! $email) {
            $this->error('Specifica --old-id oppure --email.');
            return 1;
        }

        $this->info("Cerco la riga utente nel dump...");

        // Estrai tutti i blocchi INSERT INTO `users`
        preg_match_all('/INSERT INTO `users`[^;]+;/s', $content, $matches);

        $found = null;
        foreach ($matches[0] as $block) {
            foreach ($this->parseInsertRows($block) as $row) {
                $fields = $this->splitFields($row);
                if (count($fields) < 10) continue;

                $rowId    = $fields[0] ?? null;
                $rowEmail = null;

                // Cerca il campo email (di solito intorno all'indice 15)
                foreach ($fields as $i => $f) {
                    if (is_string($f) && str_contains($f, '@') && str_contains($f, '.')) {
                        $rowEmail = $f;
                        break;
                    }
                }

                $match = ($oldId && (string) $rowId === (string) $oldId)
                    || ($email && strtolower((string) $rowEmail) === strtolower($email));

                if ($match) {
                    $found = $fields;
                    break 2;
                }
            }
        }

        if (! $found) {
            $this->error('Utente non trovato nel dump.');
            return 1;
        }

        $this->info("Utente trovato! Campi raw:");
        $this->newLine();

        // Mostra ogni campo con indice
        $rows = [];
        foreach ($found as $i => $val) {
            $displayVal = is_null($val)    ? 'NULL'
                : (is_string($val)         ? '"' . substr($val, 0, 80) . '"'
                : (is_float($val)          ? number_format($val, 4)
                : (string) $val));

            // Evidenzia campi che sembrano valori monetari
            $isMoney = is_numeric($val) && $val != (int) $val && $val > 0;
            $isLarge = is_numeric($val) && $val > 100;
            $note = '';
            if ($isMoney) $note = '<-- potrebbe essere un importo';
            if ($val == 983.94 || $val == 50.0 || $val == 50) $note = '<<< SALDO CANDIDATO';

            $rows[] = [$i, $displayVal, $note];
        }

        $this->table(['Indice', 'Valore', 'Nota'], $rows);

        // Cerca campi numerici non-interi che potrebbero essere il saldo
        $this->newLine();
        $this->info('=== Campi numerici decimali (potenziali saldi) ===');
        foreach ($found as $i => $val) {
            if (is_float($val) && $val > 0) {
                $this->line("  Indice [{$i}] = {$val}");
            }
            // Anche stringhe numeriche con punto decimale
            if (is_string($val) && is_numeric($val) && str_contains($val, '.') && (float)$val > 0) {
                $this->line("  Indice [{$i}] = {$val}  (string)");
            }
        }

        // Cerca nei transactions il totale per questo utente
        if ($oldId) {
            $this->newLine();
            $this->info("=== Transazioni nel dump per old_user_id={$oldId} ===");
            $txnTables = ['transactions', 'transactions_new', 'transactions_backup', 'transactions_back_2'];
            $totalCredits = 0;
            $totalDebits  = 0;
            $count = 0;

            foreach ($txnTables as $table) {
                preg_match_all('/INSERT INTO `' . preg_quote($table, '/') . '`[^;]+;/s', $content, $txnBlocks);
                foreach ($txnBlocks[0] as $block) {
                    foreach ($this->parseInsertRows($block) as $row) {
                        $v = $this->splitFields($row);
                        if (count($v) < 10) continue;
                        if ((int) ($v[1] ?? -1) !== (int) $oldId) continue;

                        $amount  = (float) ($v[4] ?? 0);
                        $trxType = trim($v[7] ?? '');
                        $remark  = trim($v[10] ?? '');
                        $date    = trim($v[11] ?? '');

                        if ($trxType === '+') $totalCredits += $amount;
                        else                  $totalDebits  += $amount;
                        $count++;

                        $this->line("  [{$table}] {$trxType}{$amount} KY  remark={$remark}  date={$date}");
                    }
                }
            }

            $this->newLine();
            $this->line("  Totale righe trovate: {$count}");
            $this->line("  Totale crediti:  +" . number_format($totalCredits, 2) . " KY");
            $this->line("  Totale debiti:   -" . number_format($totalDebits, 2) . " KY");
            $this->line("  Saldo calcolato: " . number_format($totalCredits - $totalDebits, 2) . " KY");
            $this->line("  Saldo vecchio sistema (kosmomoney.com): 983,94 KY");
        }

        return 0;
    }

    private function showColumns(string $content, string $table): void
    {
        // Cerca CREATE TABLE `users`
        preg_match('/CREATE TABLE `' . preg_quote($table, '/') . '`\s*\((.+?)\)\s*ENGINE/s', $content, $m);
        if (! $m) {
            $this->warn("CREATE TABLE per `{$table}` non trovato nel dump.");
            $this->info("Il dump potrebbe non includere la struttura delle tabelle.");
            return;
        }

        $this->info("=== Struttura tabella `{$table}` ===");
        $lines   = explode("\n", $m[1]);
        $colIdx  = 0;
        $rows    = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if (preg_match('/^`([^`]+)`\s+(.+)/', $line, $col)) {
                $rows[] = [$colIdx, $col[1], substr($col[2], 0, 50)];
                $colIdx++;
            }
        }
        $this->table(['Indice', 'Campo', 'Tipo'], $rows);
    }

    // =========================================================================
    // PARSER SQL (identico agli altri comandi)
    // =========================================================================

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
            elseif ($c === ',' && $depth === 0) { $fields[] = $this->convertField(trim($buf)); $buf = ''; }
            else { $buf .= $c; }
        }
        if ($buf !== '') $fields[] = $this->convertField(trim($buf));
        return $fields;
    }

    private function convertField(string $val): mixed
    {
        if (strtoupper($val) === 'NULL') return null;
        if (strlen($val) >= 2 && $val[0] === "'" && $val[-1] === "'") {
            $s = substr($val, 1, -1);
            return str_replace(["\'", '\\\\', '\\n', '\\r', '\\t'], ["'", '\\', "\n", "\r", "\t"], $s);
        }
        if (is_numeric($val)) return strpos($val, '.') !== false ? (float) $val : (int) $val;
        return $val;
    }
}
