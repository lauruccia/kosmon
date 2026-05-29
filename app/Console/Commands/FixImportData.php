<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\LedgerEntry;
use App\Models\Transfer;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Ripara i dati importati da kmoney:import-old-data.
 *
 * Problemi rilevati:
 *   1. I transfer importati hanno status='completed' invece di 'booked'
 *      -> La dashboard admin e gli estratti conto filtrano su 'booked', quindi
 *         mostrano 0 KY in circolazione pur avendo saldi corretti sugli account.
 *   2. Non esistono LedgerEntry per i transfer importati
 *      -> Gli estratti conto sono vuoti / la storia movimenti non funziona.
 *   3. La Cassa Circuito ha saldo 0 invece di -(totale emissioni)
 *      -> L'admin vede la cassa a 0 anche se ha emesso 11.435 KY.
 *
 * Uso:
 *   php artisan kmoney:fix-import          # esegue la riparazione
 *   php artisan kmoney:fix-import --dry-run # mostra cosa farebbe senza toccare il DB
 */
class FixImportData extends Command
{
    protected $signature = 'kmoney:fix-import
        {--dry-run : Mostra le modifiche senza eseguirle}
        {--force  : Salta la conferma interattiva}';

    protected $description = 'Ripara i transfer importati: status completed->booked, crea LedgerEntry, aggiorna Cassa Circuito';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('*** DRY-RUN attivo -- nessuna modifica al database ***');
        }

        // --- Recupera i transfer da riparare ---
        $transfers = Transfer::query()
            ->where('status', 'completed')
            ->whereIn('kind', ['admin_credit', 'trade_payment'])
            ->get();

        if ($transfers->isEmpty()) {
            $this->info('Nessun transfer da riparare trovato. Il DB sembra gia a posto.');
            return 0;
        }

        $this->info("Transfer da riparare: {$transfers->count()}");

        // --- Verifica LedgerEntry gia esistenti ---
        $existingLedger = LedgerEntry::whereIn(
            'transfer_id',
            $transfers->pluck('id')
        )->count();

        if ($existingLedger > 0) {
            $this->warn("Attenzione: {$existingLedger} LedgerEntry esistono gia per questi transfer.");
            $this->warn("Potrebbe indicare una riparazione parziale precedente.");
            if (! $dryRun && ! $this->option('force')) {
                if (! $this->confirm('Vuoi continuare? Le LedgerEntry duplicate verranno saltate.')) {
                    return 1;
                }
            }
        }

        // --- Riepilogo ---
        $adminCredits  = $transfers->where('kind', 'admin_credit');
        $tradePayments = $transfers->where('kind', 'trade_payment');

        $this->table(
            ['Tipo', 'Quantita', 'Totale KY'],
            [
                ['admin_credit',   $adminCredits->count(),  number_format($adminCredits->sum('amount') / 100, 2)],
                ['trade_payment',  $tradePayments->count(), number_format($tradePayments->sum('amount') / 100, 2)],
            ]
        );

        $cassaAdjustment = (int) $adminCredits->sum('amount');
        $systemAccount   = Account::where('is_system_account', true)->first();

        $this->info("Cassa Circuito attuale: " . number_format(($systemAccount->available_balance ?? 0) / 100, 2) . " KY");
        $this->info("Aggiustamento Cassa: -" . number_format($cassaAdjustment / 100, 2) . " KY");
        $this->info("Cassa dopo fix: " . number_format((($systemAccount->available_balance ?? 0) - $cassaAdjustment) / 100, 2) . " KY");

        if ($dryRun) {
            $this->newLine();
            $this->info('[DRY-RUN] Operazioni che verrebbero eseguite:');
            $this->line("  1. UPDATE transfers SET status='booked', booked_at=created_at WHERE status='completed' ({$transfers->count()} righe)");
            $this->line("  2. INSERT INTO ledger_entries ({$transfers->count()} * 2 = " . ($transfers->count() * 2) . " righe)");
            $this->line("  3. UPDATE accounts SET available_balance = available_balance - {$cassaAdjustment} WHERE is_system_account=1");
            $this->newLine();
            $this->warn('*** DRY-RUN: nessuna modifica eseguita ***');
            return 0;
        }

        if (! $this->option('force') && ! $this->confirm('Procedere con la riparazione?', true)) {
            $this->info('Operazione annullata.');
            return 0;
        }

        // --- FASE 1: status completed -> booked ---
        $this->info('Fase 1: Aggiorno status completed -> booked...');

        $updated = 0;
        $errors  = 0;

        DB::transaction(function () use ($transfers, &$updated, &$errors) {
            foreach ($transfers as $transfer) {
                try {
                    // Usa created_at come booked_at se non gia impostato
                    $bookedAt = $transfer->booked_at ?? $transfer->created_at;

                    $transfer->forceFill([
                        'status'    => 'booked',
                        'booked_at' => $bookedAt,
                    ])->save();

                    $updated++;
                } catch (\Throwable $e) {
                    $errors++;
                    $this->error("  Errore transfer#{$transfer->id}: {$e->getMessage()}");
                }
            }
        });

        $this->info("  OK: {$updated} aggiornati, {$errors} errori");

        // --- FASE 2: LedgerEntry ---
        $this->info('Fase 2: Creo LedgerEntry...');

        $leCreated = 0;
        $leSkipped = 0;

        // Ricarica i transfer con booked_at aggiornato
        $transfers = Transfer::query()
            ->where('status', 'booked')
            ->whereIn('kind', ['admin_credit', 'trade_payment'])
            ->whereIn('id', $transfers->pluck('id'))
            ->get();

        foreach ($transfers as $transfer) {
            // Salta se gia esistono LedgerEntry per questo transfer
            if (LedgerEntry::where('transfer_id', $transfer->id)->exists()) {
                $leSkipped++;
                continue;
            }

            try {
                $bookedAt = CarbonImmutable::parse($transfer->booked_at ?? $transfer->created_at);

                // Recupera i saldi "storici" degli account al momento dell'import
                // Non possiamo ricostruirli esattamente, quindi usiamo il saldo attuale
                // come approssimazione (e' solo storico, non influisce sui saldi live)
                $fromAccount = Account::find($transfer->from_account_id);
                $toAccount   = Account::find($transfer->to_account_id);

                if (! $fromAccount || ! $toAccount) {
                    $leSkipped++;
                    continue;
                }

                DB::transaction(function () use ($transfer, $fromAccount, $toAccount, $bookedAt, &$leCreated) {
                    LedgerEntry::create([
                        'transfer_id'   => $transfer->id,
                        'account_id'    => $fromAccount->id,
                        'direction'     => 'debit',
                        'amount'        => (int) $transfer->amount,
                        'balance_after' => 0, // non ricostruibile con precisione, 0 indica "storico"
                        'posted_at'     => $bookedAt,
                        'meta'          => [
                            'counterparty_account_id' => $toAccount->id,
                            'imported_migration'      => true,
                        ],
                    ]);

                    LedgerEntry::create([
                        'transfer_id'   => $transfer->id,
                        'account_id'    => $toAccount->id,
                        'direction'     => 'credit',
                        'amount'        => (int) $transfer->amount,
                        'balance_after' => 0, // non ricostruibile con precisione, 0 indica "storico"
                        'posted_at'     => $bookedAt,
                        'meta'          => [
                            'counterparty_account_id' => $fromAccount->id,
                            'imported_migration'      => true,
                        ],
                    ]);

                    $leCreated += 2;
                });

            } catch (\Throwable $e) {
                $leSkipped++;
                $this->error("  Errore LedgerEntry transfer#{$transfer->id}: {$e->getMessage()}");
            }
        }

        $this->info("  OK: {$leCreated} LedgerEntry create, {$leSkipped} saltate");

        // --- FASE 3: Cassa Circuito ---
        $this->info('Fase 3: Aggiorno saldo Cassa Circuito...');

        if (! $systemAccount) {
            $this->error('  Cassa Circuito non trovata! Esegui le migration.');
        } else {
            $newBalance = (int) $systemAccount->available_balance - $cassaAdjustment;
            $systemAccount->forceFill(['available_balance' => $newBalance])->save();

            $this->info("  OK: Cassa aggiornata a " . number_format($newBalance / 100, 2) . " KY");
        }

        // --- RIEPILOGO FINALE ---
        $this->newLine();
        $this->info('=== RIPARAZIONE COMPLETATA ===');
        $this->info("  Transfer aggiornati a booked:  {$updated}");
        $this->info("  LedgerEntry create:            {$leCreated}");
        $this->info("  Cassa Circuito:                " . number_format((Account::where('is_system_account', true)->first()?->available_balance ?? 0) / 100, 2) . " KY");
        $this->info("  Saldi utenti (invariati):      " . number_format(Account::where('is_system_account', false)->sum('available_balance') / 100, 2) . " KY");

        return 0;
    }
}
