<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Regole "punti per evento" (2026-07-22, richiesta di Laura): quanti
     * punti matura l'agente diretto per gli eventi del suo cliente e per
     * quanti GIORNI restano attivi. Sostituisce la regola "/12 + frazionari"
     * del 2026-07-20: da oggi i punti NON vengono piu' spalmati — l'evento
     * matura i punti nel momento in cui avviene e valgono per la durata
     * configurata.
     *
     * Qui vive solo la riga 'registration' (apertura conto; seed: 1 punto
     * per 90 giorni — prima: 1 punto per 1 mese). I punti delle RICARICHE
     * stanno invece direttamente sulle KY Card (/admin/ky-cards, colonne
     * mlm_points/mlm_points_duration_days — vedi migration
     * 2026_07_22_120000): i tagli di ricarica sono le card reali, non un
     * elenco separato che potrebbe andare fuori sincrono.
     */
    public function up(): void
    {
        Schema::create('mlm_point_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('event_type', 20)->unique(); // oggi solo 'registration'
            $table->decimal('points', 8, 2);
            $table->unsignedInteger('duration_days');
            $table->timestamps();
        });

        $now = now();

        DB::table('mlm_point_rules')->insert([
            ['event_type' => 'registration', 'points' => 1, 'duration_days' => 90, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('mlm_point_rules');
    }
};
