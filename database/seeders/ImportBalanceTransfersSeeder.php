<?php

namespace Database\Seeders;

use App\Models\Transfer;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * FASE 3 — Importa i trasferimenti peer-to-peer (balance_transfers).
 *
 * Migra i 50 balance_transfers con status=1 (completati) dal vecchio DB.
 * Il destinatario viene risolto tramite la tabella beneficiaries e poi
 * mappato tramite migration_user_map (account_number → old user_id).
 *
 * Prerequisiti: ImportFromOldDbSeeder gia' eseguito.
 *
 * Esecuzione:
 *   php artisan db:seed --class=ImportBalanceTransfersSeeder
 */
class ImportBalanceTransfersSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('=== FASE 3: Importazione trasferimenti peer-to-peer ===');

        $transfers = DB::connection('old')
            ->table('balance_transfers')
            ->where('status', 1)  // solo completati
            ->orderBy('id')
            ->get();

        $this->command->info("Trovati {$transfers->count()} trasferimenti completati.");

        $imported = 0;
        $skipped  = 0;
        $errors   = 0;

        foreach ($transfers as $bt) {
            $idempotencyKey = "migration_balance_transfers_{$bt->id}";

            if (Transfer::where('idempotency_key', $idempotencyKey)->exists()) {
                $skipped++;
                continue;
            }

            // Trova mittente nella nuova app
            $senderMap = DB::table('migration_user_map')
                ->where('old_user_id', $bt->user_id)
                ->first();

            if (! $senderMap) {
                $this->command->warn("  Mittente non trovato: old_user_id={$bt->user_id} (bt_id={$bt->id})");
                $skipped++;
                continue;
            }

            // Trova destinatario: la tabella beneficiaries ha beneficiary_id = old user_id del destinatario
            $beneficiary = DB::connection('old')
                ->table('beneficiaries')
                ->where('id', $bt->beneficiary_id)
                ->first();

            if (! $beneficiary) {
                $this->command->warn("  Beneficiario non trovato: ben_id={$bt->beneficiary_id} (bt_id={$bt->id})");
                $skipped++;
                continue;
            }

            // Il beneficiary ha beneficiary_id = old user_id del destinatario
            $receiverOldId = $beneficiary->beneficiary_id;
            $receiverMap   = DB::table('migration_user_map')
                ->where('old_user_id', $receiverOldId)
                ->first();

            if (! $receiverMap) {
                // Fallback: cerca per account_number
                $receiverUser = DB::connection('old')
                    ->table('users')
                    ->where('account_number', $beneficiary->account_number)
                    ->first();

                if ($receiverUser) {
                    $receiverMap = DB::table('migration_user_map')
                        ->where('old_user_id', $receiverUser->id)
                        ->first();
                }
            }

            if (! $receiverMap) {
                $this->command->warn("  Destinatario non mappato: ben_id={$bt->beneficiary_id} account={$beneficiary->account_number}");
                $skipped++;
                continue;
            }

            try {
                $amountCents = (int) round((float) $bt->amount * 100);

                Transfer::create([
                    'uuid'            => (string) Str::uuid(),
                    'reference'       => $this->generateReference(),
                    'initiated_by'    => $senderMap->new_user_id,
                    'from_account_id' => $senderMap->new_account_id,
                    'to_account_id'   => $receiverMap->new_account_id,
                    'amount'          => $amountCents,
                    'currency_code'   => 'KY',
                    'status'          => 'completed',
                    'kind'            => 'trade_payment',
                    'idempotency_key' => $idempotencyKey,
                    'description'     => 'Trasferito da sito precedente (trx: ' . $bt->trx . ')',
                    'booked_at'       => $bt->created_at,
                    'created_at'      => $bt->created_at,
                    'updated_at'      => $bt->updated_at,
                ]);

                $imported++;

            } catch (\Throwable $e) {
                $errors++;
                Log::error("ImportBalanceTransfersSeeder: errore bt_id={$bt->id}", [
                    'message' => $e->getMessage(),
                ]);
                $this->command->warn("  ERRORE bt_id={$bt->id}: {$e->getMessage()}");
            }
        }

        $this->command->info("=== FASE 3 COMPLETATA ===");
        $this->command->info("  Importati: {$imported}");
        $this->command->info("  Saltati:   {$skipped}");
        $this->command->info("  Errori:    {$errors}");

        $total = Transfer::where('kind', 'trade_payment')->count();
        $this->command->info("  Totale trade_payment nella nuova app: {$total}");
    }

    private function generateReference(): string
    {
        do {
            $ref = 'KM-' . strtoupper(Str::random(12));
        } while (Transfer::where('reference', $ref)->exists());
        return $ref;
    }
}
