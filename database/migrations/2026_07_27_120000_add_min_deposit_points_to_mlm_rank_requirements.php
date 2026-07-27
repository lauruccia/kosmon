<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Nuovo requisito di qualifica "punti da ricariche" (2026-07-27,
     * richiesta di Laura, punto 4): minimo di punti attivi che devono
     * provenire SPECIFICAMENTE da ricariche (mlm_point_ledger_entries con
     * source_type = 'deposit'), non semplicemente dal totale punti già
     * richiesto da min_points. Vedi MlmRankEngine::evaluate() per il
     * calcolo della metrica 'deposit_points'.
     *
     * Seed a 0 per TUTTI i gradi (requisito DISATTIVATO finche' Laura non
     * imposta lei stessa una soglia da /admin/mlm-impostazioni — testo
     * letterale della richiesta: "admin inserira' il numero di punti da
     * fare con ricariche"). A differenza di min_clients (22/07), qui NON
     * seedo valori reali (es. 6/12/24) di default: attivare da subito una
     * soglia mai comunicata agli agenti retrocederebbe a sorpresa chi e'
     * gia' qualificato al primo ricalcolo notturno, prima che Laura abbia
     * potuto scegliere e comunicare il numero giusto. Editabile subito da
     * admin, stessa UI generica degli altri requisiti; come per gli altri
     * requisiti vale la retrocessione standard una volta attivato.
     */
    public function up(): void
    {
        Schema::table('mlm_rank_requirements', function (Blueprint $table): void {
            $table->unsignedInteger('min_deposit_points')->default(0)->after('min_clients');
        });
    }

    public function down(): void
    {
        Schema::table('mlm_rank_requirements', function (Blueprint $table): void {
            $table->dropColumn('min_deposit_points');
        });
    }
};
