<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase A del piano "esperienza d'acquisto" (26/08/2026): la nota che il
 * compratore lascia al venditore dalla pagina di cassa.
 *
 * Migrazione ADDITIVA e nient'altro: gli ordini già in produzione restano
 * esattamente come sono, con la nota a null. Nessun backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->text('buyer_note')->nullable()->after('shipping_phone');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('buyer_note');
        });
    }
};
