<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Analizza un dump SQL e mostra le tabelle presenti con il numero di righe.
 *
 * Uso:
 *   php artisan kmoney:analyze-dump "C:\Users\Intel\Downloads\dump.sql"
 *   php artisan kmoney:analyze-dump "C:\Users\Intel\Downloads\dump.sql" --user-id=9
 */
class AnalyzeDump extends Command
{
    protected $signature = 'kmoney:analyze-dump
        {file : Percorso al dump SQL}
        {--user-id=  : Mostra tutte le righe di questo old_user_id nelle tabelle trovate}';

    protected $description = 'Analizza il dump SQL e mostra tabelle / conteggi righe';

    public function handle(): int
    {
        $file = $this->argument('file');
        if (! file_exists($file)) {
            $this->error("File non trovato: {$file}");
            return 1;
        }

        $this->info("Leggendo dump ({$file})...");
        $content = file_get_contents($file);
        $this->info('  OK — ' . round(strlen($content) / 1024 / 1024, 1) . ' MB');

        // Trova tutte le tabelle con INSERT INTO
        preg_match_all('/INSERT INTO `([^`]+)`/i', $content, $matches);
        $tableRows = array_count_values($matches[1]);
        arsort($tableRows);

        $this->newLine();
        $this->info('=== TABELLE TROVATE (ordinate per righe INSERT) ===');
        $this->table(['Tabella', 'Blocchi INSERT', 'Note'], array_map(function ($tbl, $cnt) {
            $note = match(true) {
                str_contains($tbl, 'transact') => '*** TRANSAZIONI ***',
                str_contains($tbl, 'transfer') => '*** TRANSFER ***',
                str_contains($tbl, 'payment')  => '*** PAGAMENTI ***',
                str_contains($tbl, 'balance')  => '*** SALDI ***',
                str_contains($tbl, 'order')    => '--- ordini ---',
                str_contains($tbl, 'wallet')   => '--- wallet ---',
                str_contains($tbl, 'kycard')   => '--- kycard ---',
                str_contains($tbl, 'product')  => '--- prodotti ---',
                default                        => '',
            };
            return [$tbl, $cnt, $note];
        }, array_keys($tableRows), $tableRows));

        // Se specificato user-id, cerca le sue righe nelle tabelle principali
        $userId = $this->option('user-id');
        if ($userId) {
            $this->newLine();
            $this->info("=== RICERCA user_id={$userId} nelle tabelle ===");

            $txnTables = array_filter(
                array_keys($tableRows),
                fn($t) => str_contains($t, 'transact')
                    || str_contains($t, 'transfer')
                    || str_contains($t, 'payment')
                    || str_contains($t, 'balance')
                    || str_contains($t, 'wallet')
                    || str_contains($t, 'kycard')
            );

            foreach ($txnTables as $tbl) {
                // Conta le occorrenze di user_id=X in questa tabella
                preg_match_all(
                    '/INSERT INTO `' . preg_quote($tbl, '/') . '`[^;]+;/s',
                    $content,
                    $blockMatches
                );
                $found = 0;
                foreach ($blockMatches[0] as $block) {
                    // Conta righe che contengono l'user_id come primo o secondo campo
                    preg_match_all('/\(' . $userId . ',/', $block, $m);
                    $found += count($m[0]);
                    // Anche posizioni successive comuni (user_id non sempre al primo posto)
                    preg_match_all('/,' . $userId . ',/', $block, $m2);
                    $found += count($m2[0]);
                }
                if ($found > 0) {
                    $this->line("  {$tbl}: ~{$found} righe per user_id={$userId}");
                }
            }

            // Cerca anche nella tabella users
            $this->newLine();
            $this->info("=== DATI UTENTE (user_id={$userId}) ===");
            preg_match_all('/INSERT INTO `users`[^;]+;/s', $content, $userBlocks);
            foreach ($userBlocks[0] as $block) {
                // Cerca il blocco con id=userId all'inizio della riga
                preg_match_all('/\(\s*' . $userId . '\s*,([^)]{0,300})\)/', $block, $rowMatches);
                foreach ($rowMatches[0] as $rawRow) {
                    $this->line("  Riga users: " . substr($rawRow, 0, 200));
                }
            }
        }

        return 0;
    }
}
