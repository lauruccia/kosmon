<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nfc_cards', function (Blueprint $table) {
            // Soglia PIN in centesimi: importi >= soglia richiedono il PIN della card.
            // null = PIN sempre richiesto (comportamento più sicuro di default).
            $table->unsignedInteger('pin_threshold')->nullable()->after('limit_per_transaction')
                ->comment('Centesimi KY: importi >= soglia richiedono PIN. null = PIN sempre richiesto');
        });

        // Fix bug unità: i limiti erano salvati in KY interi dal form ma confrontati
        // in centesimi da checkLimits(). Converto i valori esistenti in centesimi (×100).
        DB::table('nfc_cards')->whereNotNull('limit_per_transaction')->update([
            'limit_per_transaction' => DB::raw('limit_per_transaction * 100'),
        ]);
        DB::table('nfc_cards')->whereNotNull('limit_daily')->update([
            'limit_daily' => DB::raw('limit_daily * 100'),
        ]);
        DB::table('nfc_cards')->whereNotNull('limit_monthly')->update([
            'limit_monthly' => DB::raw('limit_monthly * 100'),
        ]);
    }

    public function down(): void
    {
        DB::table('nfc_cards')->whereNotNull('limit_per_transaction')->update([
            'limit_per_transaction' => DB::raw('limit_per_transaction / 100'),
        ]);
        DB::table('nfc_cards')->whereNotNull('limit_daily')->update([
            'limit_daily' => DB::raw('limit_daily / 100'),
        ]);
        DB::table('nfc_cards')->whereNotNull('limit_monthly')->update([
            'limit_monthly' => DB::raw('limit_monthly / 100'),
        ]);

        Schema::table('nfc_cards', function (Blueprint $table) {
            $table->dropColumn('pin_threshold');
        });
    }
};
