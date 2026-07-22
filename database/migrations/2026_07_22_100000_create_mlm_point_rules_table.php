<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tabella configurabile "punti per evento" (2026-07-22, richiesta di
     * Laura): quanti punti matura l'agente diretto per ogni evento del suo
     * cliente e per quanti GIORNI restano attivi. Sostituisce la regola
     * "/12 + frazionari" del 2026-07-20 (importo mensile = deposito/12,
     * 1 punto ogni 50 EUR): da oggi i punti NON vengono piu' spalmati — la
     * ricarica matura i punti della sua riga nel momento in cui avviene, e
     * valgono per la durata configurata.
     *
     * Eventi:
     *  - 'registration' (deposit_amount_eur_cents NULL): il cliente apre il
     *    conto. Seed: 1 punto per 90 giorni (prima: 1 punto per 1 mese).
     *  - 'deposit': una riga PER OGNI TAGLIO di ricarica disponibile
     *    (l'admin imposta i valori). Alla ricarica si applica la riga con il
     *    taglio piu' alto <= importo (cosi' un importo fuori taglio ricade
     *    sul taglio inferiore invece di non maturare nulla). Sotto il taglio
     *    minimo configurato (seed: 120 EUR) la ricarica non genera punti.
     *
     * Seed = valori decisi da Laura il 2026-07-22 ("1 mese = 30 giorni"):
     *  120 EUR -> 2 punti / 30 giorni
     *  600 EUR -> 2 punti / 180 giorni
     *  1.200 EUR -> 2 punti / 360 giorni
     */
    public function up(): void
    {
        Schema::create('mlm_point_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('event_type', 20); // 'registration' | 'deposit'
            $table->unsignedInteger('deposit_amount_eur_cents')->nullable(); // NULL per registration
            $table->decimal('points', 8, 2);
            $table->unsignedInteger('duration_days');
            $table->timestamps();

            $table->unique(['event_type', 'deposit_amount_eur_cents']);
        });

        $now = now();

        DB::table('mlm_point_rules')->insert([
            ['event_type' => 'registration', 'deposit_amount_eur_cents' => null, 'points' => 1, 'duration_days' => 90, 'created_at' => $now, 'updated_at' => $now],
            ['event_type' => 'deposit', 'deposit_amount_eur_cents' => 12_000, 'points' => 2, 'duration_days' => 30, 'created_at' => $now, 'updated_at' => $now],
            ['event_type' => 'deposit', 'deposit_amount_eur_cents' => 60_000, 'points' => 2, 'duration_days' => 180, 'created_at' => $now, 'updated_at' => $now],
            ['event_type' => 'deposit', 'deposit_amount_eur_cents' => 120_000, 'points' => 2, 'duration_days' => 360, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('mlm_point_rules');
    }
};
