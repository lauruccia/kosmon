<?php

use App\Models\Account;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Crea il conto riserva "Cassa Circuito KMoney".
     *
     * Questo conto parte da 0 e va in negativo man mano che KY vengono emessi:
     * il saldo negativo rappresenta il totale di KY in circolazione nel circuito.
     * Equivalente alla "base monetaria" in un sistema bancario tradizionale.
     */
    public function up(): void
    {
        // Idempotente: crea solo se non esiste già
        $exists = Account::query()->where('is_system_account', true)->exists();

        if ($exists) {
            return;
        }

        Account::create([
            'uuid'                 => 'KYSYSTEM00000000', // account number fisso e riconoscibile
            'company_id'           => null,
            'owner_user_id'        => null,
            'owner_type'           => 'system',
            'type'                 => 'primary',
            'account_name'         => 'Cassa Circuito KMoney',
            'currency_code'        => 'KY',
            'status'               => 'active',
            'allow_negative_balance' => true,
            'is_system_account'    => true,
            'available_balance'    => 0,
            'pending_balance'      => 0,
        ]);
    }

    public function down(): void
    {
        Account::query()->where('is_system_account', true)->delete();
    }
};
