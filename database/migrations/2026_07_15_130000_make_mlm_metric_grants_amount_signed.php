<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `amount` era UNSIGNED (solo regali positivi). Richiesta di Laura il
     * 2026-07-15: l'admin deve poter anche TOGLIERE punti/agenti/colonne
     * omaggio gia' assegnati, non solo revocare in blocco un intero grant
     * (vedi MlmMetricGrantController::store()). Un valore negativo qui
     * rappresenta una CORREZIONE (si somma agli altri grant attivi tramite
     * MlmMetricGrant::activeSumFor(), che fa una semplice SUM su `amount`),
     * lasciando comunque traccia nello storico invece di modificare/
     * cancellare il grant originale. Il totale combinato con il valore
     * reale non scende mai sotto zero (vedi User::mlmActivePoints() e
     * MlmRankEngine::evaluate(), che applicano max(0, ...)).
     */
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE mlm_metric_grants MODIFY amount INT NOT NULL');

            return;
        }

        Schema::table('mlm_metric_grants', function (Blueprint $table): void {
            $table->integer('amount')->change();
        });
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE mlm_metric_grants MODIFY amount INT UNSIGNED NOT NULL');

            return;
        }

        Schema::table('mlm_metric_grants', function (Blueprint $table): void {
            $table->unsignedInteger('amount')->change();
        });
    }
};
