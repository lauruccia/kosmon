<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            // Percentuale del prezzo pagata in KY: 0, 25, 50, 75, 100.
            // La parte restante (100 - ky_percentage)% viene saldata in euro off-circuit.
            // Default 100 = 100% KY (comportamento storico).
            $table->unsignedTinyInteger('ky_percentage')->default(100)->after('price_ky');
        });
    }

    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->dropColumn('ky_percentage');
        });
    }
};
