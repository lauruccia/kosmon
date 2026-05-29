<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\Transfer;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * FASE 2 — Importa le transazioni admin (bonus, depositi, crediti).
 *
 * Migra:
 *   - transactions  con remark IN (balance_add, admin_deposit, deposit, wallet_add)
 *   - transactions_new con gli stessi remark
 *
 * Tutti diventano transfers kind='admin_credit' dalla cassa_circuito verso il conto azienda.
 *
 * Prerequisiti: ImportFromOldDbSeeder già eseguito (tabella migration_user_map presente).
 *
 * Esecuzione:
 *   php artisan db:seed --class=ImportTransactionsSeeder
 */
class ImportTransactionsSeeder extends Seeder
{
    // Remark che corrispondono a crediti admin
    private const CREDIT_REMARKS = [
        'balance_add',
        'admin_deposit',
        'deposit',
        'wallet_add',
    ];

    public function run(): void
    {
        $this->command->info('=== FASE 2: Importazione transazioni admin ===');

        $systemAccount = Account::where('is_system_account', true)->first();
        if (! $systemAccount) {
            $this->command->error('Cassa Circuito (system account) non trovata! Esegui prima le migration.');
            return;
        }
        $this->command->info("Cassa Circuito account ID: {$systemAccount->id}");

        $imported = 0;
        $skipped  = 0;
        $errors   = 0;

        // Processa sia transactions che transactions_new
        foreach (['transactions', 'transactions_new'] as $table) {
            $this->command->info("Elaboro tabella: {$table}");

            $rows = DB::connection('old')
                ->table($table)
                ->whereIn('remark', self::CREDIT_REMARKS)
                ->where('trx_type', '+')   // solo le righe di credito
                ->orderBy('id')
                ->get();

            $this->command->info("  Trovate {$rows->count()} righe di credito.");

            foreach ($rows as $row) {
                // Trova il conto destinatario nella nuova app
                $map = DB::table('migration_user_map')
                    ->where('old_user_id', $row->user_id)
                    ->first();

                if (! $map) {
                    $skipped++;
                    continue;
                }

                // Chiave idempotency unica: tabella + id originale
                $idempotencyKey = "migration_{$table}_{$row->id}";

                // Salta se gia' importato
                if (Transfer::where('idempotency_key', $idempotencyKey)->exists()) {
                    $skipped++;
                    continue;
                }

                try {
                    $amountCents = (int) round((float) $row->amount * 100);

                    Transfer::create([
                        'uuid'            => (string) Str::uuid(),
                        'reference'       => $this->generateReference(),
                        'initiated_by'    => null,  // operazione admin
                        'from_account_id' => $systemAccount->id,
                        'to_account_id'   => $map->new_account_id,
                        'amount'          => $amountCents,
                        'currency_code'   => 'KY',
                        'status'          => 'completed',
                        'kind'            => 'admin_credit',
                        'idempotency_key' => $idempotencyKey,
                        'description'     => $row->details ?? $row->remark,
                        'booked_at'       => $row->created_at,
                        'created_at'      => $row->created_at,
                        'updated_at'      => $row->updated_at,
                    ]);

                    $imported++;

                } catch (\Throwable $e) {
                    $errors++;
                    Log::error("ImportTransactionsSeeder: errore {$table} id={$row->id}", [
                        'message' => $e->getMessage(),
                    ]);
                }
            }
        }

        $this->command->info("=== FASE 2 COMPLETATA ===");
        $this->command->info("  Importati: {$imported}");
        $this->command->info("  Saltati:   {$skipped}");
        $this->command->info("  Errori:    {$errors}");

        $total = Transfer::where('kind', 'admin_credit')->count();
        $this->command->info("  Totale admin_credit nella nuova app: {$total}");
    }

    private function generateReference(): string
    {
        do {
            $ref = 'KM-' . strtoupper(Str::random(12));
        } while (Transfer::where('reference', $ref)->exists());
        return $ref;
    }
}
