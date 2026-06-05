<?php

namespace App\Console\Commands;

use App\Models\NfcCard;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Migra i seriali NFC dal vecchio formato KMY-YYYY-XXXXXX-C
 * al nuovo formato credit-card XXXX-XXXX-XXXX-XXXX con HMAC di verifica.
 *
 * Uso:
 *   php artisan kmoney:migrate-nfc-serials
 *   php artisan kmoney:migrate-nfc-serials --dry-run     ← solo anteprima
 */
class MigrateNfcCardSerials extends Command
{
    protected $signature   = 'kmoney:migrate-nfc-serials {--dry-run : Mostra le modifiche senza salvare}';
    protected $description = 'Migra i seriali NFC dal vecchio formato (KMY-YYYY-XXXXXX-C) al nuovo formato 4×4 con HMAC';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->warn('⚠  DRY-RUN attivo — nessuna modifica verrà salvata.');
        }

        // Seleziona le card con vecchio formato (regex: KMY-YYYY-XXXXXX-C)
        $cards = NfcCard::whereRaw("serial_number REGEXP '^KMY-[0-9]{4}-[A-Z0-9]{6}-[A-Z0-9]$'")->get();

        // Fallback per SQLite (non supporta REGEXP nativamente con tutti i driver)
        if ($cards->isEmpty()) {
            $cards = NfcCard::all()->filter(
                fn ($c) => preg_match('/^KMY-\d{4}-[A-Z0-9]{6}-[A-Z0-9]$/', (string) $c->serial_number)
            );
        }

        if ($cards->isEmpty()) {
            $this->info('Nessuna card con vecchio formato trovata. Nulla da fare.');
            return self::SUCCESS;
        }

        $this->info("Trovate {$cards->count()} card da migrare.");
        $this->newLine();

        $headers = ['ID', 'UUID (breve)', 'Vecchio seriale', 'Nuovo seriale'];
        $rows    = [];
        $updated = 0;
        $errors  = 0;

        DB::beginTransaction();

        try {
            foreach ($cards as $card) {
                $oldSerial = $card->serial_number;
                $newSerial = NfcCard::generateSerial();

                $rows[] = [
                    $card->id,
                    substr($card->uuid, 0, 8) . '…',
                    $oldSerial,
                    $newSerial,
                ];

                if (! $dryRun) {
                    $card->forceFill(['serial_number' => $newSerial])->save();
                    $updated++;
                }
            }

            $this->table($headers, $rows);

            if ($dryRun) {
                DB::rollBack();
                $this->newLine();
                $this->warn('DRY-RUN: nessuna modifica salvata. Rimuovi --dry-run per applicare.');
            } else {
                DB::commit();
                $this->newLine();
                $this->info("✅  Migrazione completata: {$updated} card aggiornate.");
            }

        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error('Errore durante la migrazione: ' . $e->getMessage());
            $errors++;
        }

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
