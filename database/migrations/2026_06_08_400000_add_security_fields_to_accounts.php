<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            // Timestamp fino a quando l'account è temporaneamente bloccato (anti-frode)
            $table->timestamp('locked_until')->nullable()->after('daily_outgoing_limit');
        });

        // Imposta limite giornaliero di 500 KY (50000 centesimi) su tutti i conti
        // non-sistema che ancora non ce l'hanno
        DB::table('accounts')
            ->where('is_system_account', false)
            ->whereNull('daily_outgoing_limit')
            ->update(['daily_outgoing_limit' => 50000]);
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn('locked_until');
        });

        DB::table('accounts')
            ->where('daily_outgoing_limit', 50000)
            ->whereNull('monthly_outgoing_limit')
            ->update(['daily_outgoing_limit' => null]);
    }
};
