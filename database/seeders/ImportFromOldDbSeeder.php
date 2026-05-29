<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * FASE 1 — Importa utenti, aziende e conti dal vecchio database.
 *
 * Prerequisiti:
 *   - Il vecchio DB deve essere accessibile come connessione 'old' (vedi config/database.php)
 *   - La nuova app deve avere le migration eseguite (php artisan migrate)
 *
 * Esecuzione:
 *   php artisan db:seed --class=ImportFromOldDbSeeder
 *
 * Il seeder salva la mappa old_user_id → new_account_id nella tabella
 * migration_user_map (creata on-the-fly) per uso dai seeder successivi.
 */
class ImportFromOldDbSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('=== FASE 1: Importazione utenti, aziende e conti ===');

        // Crea tabella di mapping (se non esiste)
        DB::statement('
            CREATE TABLE IF NOT EXISTS migration_user_map (
                old_user_id    INT UNSIGNED NOT NULL PRIMARY KEY,
                new_company_id BIGINT UNSIGNED NULL,
                new_user_id    BIGINT UNSIGNED NOT NULL,
                new_account_id BIGINT UNSIGNED NOT NULL,
                created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ');

        $oldUsers = DB::connection('old')
            ->table('users')
            ->orderBy('id')
            ->get();

        $this->command->info("Trovati {$oldUsers->count()} utenti nel vecchio DB.");

        $imported  = 0;
        $skipped   = 0;
        $errors    = 0;
        $duplicateLog = [];

        foreach ($oldUsers as $old) {
            // Salta se già importato
            if (DB::table('migration_user_map')->where('old_user_id', $old->id)->exists()) {
                $skipped++;
                continue;
            }

            // Salta se email duplicata nella nuova app
            if (User::where('email', $old->email)->exists()) {
                $duplicateLog[] = "Duplicato email saltato: old_id={$old->id} email={$old->email}";
                $skipped++;
                continue;
            }

            DB::beginTransaction();
            try {
                $holderType = $this->mapHolderType($old);
                $plan       = $holderType === 'company' ? $this->mapSubscriptionPlan($old->store_plan_purchase ?? null) : null;
                $limits     = $this->mapLegacyLimits($old);

                // --- 1. COMPANY ---
                $companyName = $this->resolveCompanyName($old);
                $company     = null;

                if ($holderType === 'company') {
                    $slug = $this->makeUniqueSlug($old->username ?? Str::slug($companyName));

                    $company = Company::create([
                        'uuid'              => (string) Str::uuid(),
                        'name'              => $companyName,
                        'slug'              => $slug,
                        'email'             => $old->email,
                        'vat_number'        => $this->nullIfEmpty($old->vat_number),
                        'fiscal_code'       => $this->nullIfEmpty($old->tax_code ?? null),
                        'status'            => $this->mapStatus($old->kv, $old->status),
                        'subscription_plan' => $plan,
                        'kyc_status'        => $this->mapKycStatus($old->kv),
                        'currency_code'     => 'KY',
                        'approved_at'       => ($old->kv == 1) ? $old->updated_at : null,
                    ]);
                }

                // --- 2. USER ---
                $name = trim(($old->firstname ?? '') . ' ' . ($old->lastname ?? ''));
                if (empty($name)) {
                    $name = $companyName;
                }

                $user = User::create([
                    'company_id'          => $company?->id,
                    'account_holder_type' => $holderType,
                    'name'                => $name,
                    'email'               => $old->email,
                    'email_verified_at'   => ($old->ev == 1) ? now() : null,
                    'password'            => $old->password, // hash bcrypt compatibile
                    'role'                => $holderType === 'company' ? 'owner' : 'private-owner',
                    'is_active'           => ($old->status == 1),
                    'phone'               => $this->nullIfEmpty($old->mobile ?? null),
                    'fiscal_code'         => $this->nullIfEmpty($old->tax_code ?? null),
                    'circuit_capacity_limit' => null,
                    'negative_balance_limit' => $limits['negative_balance_limit'],
                    'daily_transaction_limit' => $limits['daily_transaction_limit'],
                    'monthly_transaction_limit' => $limits['monthly_transaction_limit'],
                    'per_movement_limit' => null,
                    'transfer_limits_use_defaults' => false,
                ]);

                // --- 3. ACCOUNT ---
                // Il vecchio balance e' in KY decimale; la nuova app usa centesimi bigint
                $oldBalance    = (float) ($old->balance ?? 0);
                $balanceCents  = (int) round($oldBalance * 100);

                $account = Account::create([
                    'company_id'        => $company?->id,
                    'owner_user_id'     => $user->id,
                    'owner_type'        => $holderType,
                    'type'              => 'main',
                    'account_name'      => $holderType === 'company' ? 'Conto principale ' . $companyName : 'Conto personale ' . $name,
                    'currency_code'     => 'KY',
                    'status'            => $old->status == 1 ? 'active' : 'suspended',
                    'allow_negative_balance' => $limits['negative_balance_limit'] > 0,
                    'is_system_account' => false,
                    'available_balance' => $balanceCents,
                    'pending_balance'   => 0,
                    'max_balance'       => $limits['max_balance'],
                    'daily_outgoing_limit' => $limits['daily_transaction_limit'],
                ]);

                // --- 4. MAPPING ---
                DB::table('migration_user_map')->insert([
                    'old_user_id'    => $old->id,
                    'new_company_id' => $company?->id,
                    'new_user_id'    => $user->id,
                    'new_account_id' => $account->id,
                ]);

                DB::commit();
                $imported++;

                if ($imported % 100 === 0) {
                    $this->command->info("  Importati {$imported} utenti...");
                }

            } catch (\Throwable $e) {
                DB::rollBack();
                $errors++;
                Log::error("ImportFromOldDbSeeder: errore old_id={$old->id}", [
                    'message' => $e->getMessage(),
                    'email'   => $old->email,
                ]);
                $this->command->warn("  ERRORE old_id={$old->id} ({$old->email}): {$e->getMessage()}");
            }
        }

        // Salva log duplicati
        if (! empty($duplicateLog)) {
            $logPath = storage_path('logs/migration_duplicates.log');
            file_put_contents($logPath, implode("\n", $duplicateLog) . "\n");
            $this->command->warn("  {$skipped} duplicati saltati → vedi {$logPath}");
        }

        $this->command->info("=== FASE 1 COMPLETATA ===");
        $this->command->info("  Importati: {$imported}");
        $this->command->info("  Saltati:   {$skipped}");
        $this->command->info("  Errori:    {$errors}");

        // Verifica rapida
        $this->command->info("Verifica conteggi nella nuova app:");
        $this->command->info("  companies: " . Company::count());
        $this->command->info("  users:     " . User::count());
        $this->command->info("  accounts:  " . Account::where('is_system_account', false)->count());
    }

    // -------------------------------------------------------------------------

    private function resolveCompanyName(object $old): string
    {
        $name = $old->company_name ?? null;
        if (! empty($name)) {
            return trim($name);
        }
        $name = trim(($old->firstname ?? '') . ' ' . ($old->lastname ?? ''));
        if (! empty($name)) {
            return $name;
        }
        return $old->username ?? 'Azienda ' . $old->id;
    }

    private function makeUniqueSlug(string $base): string
    {
        $slug = Str::slug($base);
        if (empty($slug)) {
            $slug = 'azienda-' . Str::random(6);
        }
        $original = $slug;
        $i = 1;
        while (Company::where('slug', $slug)->exists()) {
            $slug = $original . '-' . $i;
            $i++;
        }
        return $slug;
    }

    /**
     * kv=0 → non verificato → pending_review
     * kv=2 → KYC in attesa → active (iscritto ma da verificare)
     * kv=1 → KYC verificato → approved
     * status=0 → banned
     */
    private function mapStatus(int $kv, int $status): string
    {
        if ($status == 0) {
            return 'suspended';
        }
        return 'active';
    }

    private function mapKycStatus(int $kv): string
    {
        return match ($kv) {
            1       => 'approved',
            2       => 'pending',
            default => 'pending',
        };
    }

    private function mapHolderType(object $old): string
    {
        return strtolower(trim((string) ($old->account_type ?? ''))) === 'individual'
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

    private function mapLegacyLimits(object $old): array
    {
        $minimumBalance = $this->legacyInteger($old->minimum_balance_limit ?? null);

        return [
            'negative_balance_limit' => $minimumBalance < 0 ? abs($minimumBalance) : 0,
            'daily_transaction_limit' => $this->legacyNullableInteger($old->daily_transfer_limit ?? null),
            'monthly_transaction_limit' => $this->legacyNullableInteger($old->monthly_transfer_limit ?? null),
            'max_balance' => $this->legacyNullableInteger($old->maximum_balance_limit ?? null),
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

    private function nullIfEmpty(?string $value): ?string
    {
        if ($value === null || trim($value) === '' || $value === '0') {
            return null;
        }
        return trim($value);
    }
}
