<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Punti/agenti omaggio" assegnati manualmente da un admin a un agente
     * (richiesta di Laura, 2026-07-14): permette di far partire un agente
     * gia' con una base di punti clienti e/o di "Basic al 1° livello"
     * (livello 1 downline) senza dover aspettare l'accumulo naturale.
     *
     * A differenza del ledger punti (mlm_point_ledger), qui NON esiste
     * valid_from/valid_until: questi importi sono SEMPRE attivi (mai
     * scadenza), come esplicitamente richiesto. Si sommano ai valori reali
     * calcolati da MlmRankEngine::evaluate() e User::mlmActivePoints() —
     * non li sostituiscono, quindi i requisiti strutturali che dipendono
     * dalla downline reale (colonne Key/Senior/Top/SuperVisor, colonne da
     * 300 punti) restano legati alla struttura vera e non sono "regalabili"
     * qui.
     *
     * revoked_at/revoked_by_admin_id: un grant annullato per errore smette
     * di contare nei totali ma resta in tabella per audit (mai cancellato
     * fisicamente).
     */
    public function up(): void
    {
        Schema::create('mlm_metric_grants', function (Blueprint $table): void {
            $table->id();
            $table->string('uuid', 36)->unique();

            $table->unsignedBigInteger('agent_user_id');
            $table->enum('metric', ['points', 'level1_basic_count']);
            $table->unsignedInteger('amount');
            $table->string('reason', 255)->nullable();

            $table->unsignedBigInteger('granted_by_admin_id')->nullable();

            $table->timestamp('revoked_at')->nullable();
            $table->unsignedBigInteger('revoked_by_admin_id')->nullable();

            $table->timestamps();

            $table->foreign('agent_user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('granted_by_admin_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('revoked_by_admin_id')->references('id')->on('users')->nullOnDelete();

            $table->index(['agent_user_id', 'metric', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mlm_metric_grants');
    }
};
