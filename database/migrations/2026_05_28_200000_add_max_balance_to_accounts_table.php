<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            // Tetto massimo del conto: quando available_balance >= max_balance
            // l'azienda puo' solo acquistare, non vendere.
            // NULL = nessun tetto configurato dall'admin.
            $table->bigInteger('max_balance')->nullable()->default(null)->after('available_balance');
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn('max_balance');
        });
    }
};
