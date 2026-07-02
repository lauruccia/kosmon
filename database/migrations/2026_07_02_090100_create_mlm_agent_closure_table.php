<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Closure table dell'albero agenti (solo agenti, mai clienti).
     * Ogni riga rappresenta una coppia (antenato, discendente) con la relativa
     * profondità e la "colonna/ramo" di 1° livello sotto l'antenato attraversata
     * per raggiungere il discendente. Permette query aggregate (punti per colonna,
     * conteggio ranghi per colonna) senza ricorsione a runtime.
     */
    public function up(): void
    {
        Schema::create('mlm_agent_closure', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('ancestor_id');
            $table->unsignedBigInteger('descendant_id');
            $table->unsignedInteger('depth'); // 0 = self, 1 = figlio diretto, ecc.

            // Figlio di 1° livello dell'antenato attraverso cui passa il percorso
            // verso il discendente (= "colonna/ramo"). Coincide col discendente
            // stesso quando depth = 1. Null quando depth = 0 (riga self).
            $table->unsignedBigInteger('branch_root_id')->nullable();

            $table->timestamps();

            $table->foreign('ancestor_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('descendant_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('branch_root_id')->references('id')->on('users')->nullOnDelete();

            $table->unique(['ancestor_id', 'descendant_id']);
            $table->index(['descendant_id']);
            $table->index(['ancestor_id', 'branch_root_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mlm_agent_closure');
    }
};
