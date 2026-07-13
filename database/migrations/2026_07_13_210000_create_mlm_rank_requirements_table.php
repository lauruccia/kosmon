<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Requisiti di qualifica agente (Basic..Manager), resi configurabili da
     * admin (2026-07-13, richiesta di Laura per poter testare rapidamente
     * senza modificare codice). Prima di questa migration erano costanti
     * hardcoded in MlmRankEngine::evaluate() — i valori seedati qui sotto
     * sono ESATTAMENTE quelli storici, confermati letteralmente dalla slide
     * "Qualifiche" KNM (vedi memoria mlm_qualifiche_retrocessione), cosi'
     * il comportamento non cambia finche' nessuno tocca il form admin.
     *
     * "start" non ha una riga: e' il grado di default, senza requisiti.
     */
    public function up(): void
    {
        Schema::create('mlm_rank_requirements', function (Blueprint $table): void {
            $table->id();
            $table->string('rank', 20)->unique();

            $table->unsignedInteger('min_points')->default(0);
            $table->unsignedInteger('min_level1_basic')->default(0);
            $table->unsignedInteger('min_branches_with_key')->default(0);
            $table->unsignedInteger('min_branches_with_senior')->default(0);
            $table->unsignedInteger('min_branches_with_top')->default(0);
            $table->unsignedInteger('min_branches_with_supervisor')->default(0);
            $table->unsignedInteger('min_branches_300pt')->default(0);

            $table->timestamps();
        });

        $now = now();

        DB::table('mlm_rank_requirements')->insert([
            [
                'rank' => 'basic', 'min_points' => 12, 'min_level1_basic' => 0,
                'min_branches_with_key' => 0, 'min_branches_with_senior' => 0,
                'min_branches_with_top' => 0, 'min_branches_with_supervisor' => 0,
                'min_branches_300pt' => 0, 'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'rank' => 'key', 'min_points' => 24, 'min_level1_basic' => 2,
                'min_branches_with_key' => 0, 'min_branches_with_senior' => 0,
                'min_branches_with_top' => 0, 'min_branches_with_supervisor' => 0,
                'min_branches_300pt' => 0, 'created_at' => $now, 'updated_at' => $now,
            ],
            [
                // Senior = 48 pt + 3 Basic al 1° liv. + 2 Key su 2 colonne diverse.
                'rank' => 'senior', 'min_points' => 48, 'min_level1_basic' => 3,
                'min_branches_with_key' => 2, 'min_branches_with_senior' => 0,
                'min_branches_with_top' => 0, 'min_branches_with_supervisor' => 0,
                'min_branches_300pt' => 0, 'created_at' => $now, 'updated_at' => $now,
            ],
            [
                // Top = 48 pt + 4 Basic al 1° liv. + 3 colonne da 300 punti attivi.
                'rank' => 'top', 'min_points' => 48, 'min_level1_basic' => 4,
                'min_branches_with_key' => 0, 'min_branches_with_senior' => 0,
                'min_branches_with_top' => 0, 'min_branches_with_supervisor' => 0,
                'min_branches_300pt' => 3, 'created_at' => $now, 'updated_at' => $now,
            ],
            [
                // SuperVisor = 48 pt + 5 Basic al 1° liv. + 2 Senior e 2 Top su 4
                // colonne diverse: implementato come "4 colonne con almeno un
                // Senior" (min_branches_with_senior=4, che include gia' le 2 Top,
                // dato che Top > Senior) + "2 colonne con almeno un Top" (=2).
                'rank' => 'supervisor', 'min_points' => 48, 'min_level1_basic' => 5,
                'min_branches_with_key' => 0, 'min_branches_with_senior' => 4,
                'min_branches_with_top' => 2, 'min_branches_with_supervisor' => 0,
                'min_branches_300pt' => 0, 'created_at' => $now, 'updated_at' => $now,
            ],
            [
                // Manager = 48 pt + 6 Basic al 1° liv. + 3 SuperVisor su 3 colonne diverse.
                'rank' => 'manager', 'min_points' => 48, 'min_level1_basic' => 6,
                'min_branches_with_key' => 0, 'min_branches_with_senior' => 0,
                'min_branches_with_top' => 0, 'min_branches_with_supervisor' => 3,
                'min_branches_300pt' => 0, 'created_at' => $now, 'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('mlm_rank_requirements');
    }
};
