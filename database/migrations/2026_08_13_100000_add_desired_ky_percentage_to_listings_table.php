<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            // Percentuale KY "desiderata" dal negozio: quella scelta liberamente
            // quando il conto NON è in debito. Distinta da ky_percentage, che è
            // quella EFFETTIVAMENTE applicata in questo momento al prodotto (e
            // che viene forzata al 100% mentre il conto è in debito).
            // Richiesta di Laura 13/08/2026: un prodotto messo al 25% quando
            // l'azienda è in attivo deve tornare al 25% (non restare bloccato al
            // 100%) non appena il saldo torna >= 0 — desired_ky_percentage è la
            // memoria di quel 25% mentre ky_percentage è temporaneamente forzato
            // al 100%. Vedi Account::syncListingsKyPercentage().
            $table->unsignedTinyInteger('desired_ky_percentage')->nullable()->after('ky_percentage');
        });

        // Backfill dei prodotti già esistenti: l'unico dato disponibile sulla
        // "percentuale desiderata" è quella attualmente salvata in
        // ky_percentage (non possiamo sapere retroattivamente se un'azienda
        // già in debito avrebbe voluto un mix diverso in bonis).
        DB::table('listings')->update(['desired_ky_percentage' => DB::raw('ky_percentage')]);
    }

    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->dropColumn('desired_ky_percentage');
        });
    }
};
