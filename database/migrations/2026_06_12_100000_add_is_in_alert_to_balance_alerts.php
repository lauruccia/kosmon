<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('balance_alerts', function (Blueprint $table) {
            // Traccia se il conto è attualmente in stato di allerta.
            // L'alert scatta SOLO al passaggio da false → true (saldo scende sotto soglia).
            // Si resetta a false quando il saldo torna sopra soglia.
            $table->boolean('is_in_alert')->default(false)->after('last_triggered_at');
        });
    }

    public function down(): void
    {
        Schema::table('balance_alerts', function (Blueprint $table) {
            $table->dropColumn('is_in_alert');
        });
    }
};
