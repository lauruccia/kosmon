<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Punti cliente FRAZIONARI (2026-07-20, slide "Importo Personale Mensile",
 * decisione di Laura "sempre /12 come slide"): 1 punto ogni 50 EUR di
 * importo personale mensile, quindi un deposito da 120 EUR vale 0,2 punti
 * al mese per 12 mesi. La colonna passa da unsignedInteger a DECIMAL(8,2).
 *
 * SQL manuale equivalente per la produzione (phpMyAdmin, vedi DEPLOY.md):
 *   ALTER TABLE mlm_point_ledger MODIFY COLUMN points DECIMAL(8,2) NOT NULL;
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mlm_point_ledger', function (Blueprint $table) {
            $table->decimal('points', 8, 2)->change();
        });
    }

    public function down(): void
    {
        Schema::table('mlm_point_ledger', function (Blueprint $table) {
            $table->unsignedInteger('points')->change();
        });
    }
};
