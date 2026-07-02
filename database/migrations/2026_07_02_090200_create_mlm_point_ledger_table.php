<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Log transazionale dei "punti cliente" (PC) maturati da un agente.
     * Ogni riga è un evento (apertura conto / deposito) con una finestra di
     * validità (valid_from..valid_until) secondo lo smoothing su N mesi.
     * "Punti attivi" di un agente = SUM(points) WHERE valid_until >= oggi.
     */
    public function up(): void
    {
        Schema::create('mlm_point_ledger', function (Blueprint $table): void {
            $table->id();
            $table->string('uuid', 36)->unique();

            $table->unsignedBigInteger('agent_user_id');
            $table->unsignedBigInteger('client_user_id');

            $table->enum('source_type', ['registration', 'deposit']);
            $table->unsignedBigInteger('source_transfer_id')->nullable(); // riferimento al deposito (KyCardPurchase/Transfer) se applicabile

            $table->unsignedInteger('points');
            $table->date('valid_from');
            $table->date('valid_until');

            $table->timestamps();

            $table->foreign('agent_user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('client_user_id')->references('id')->on('users')->cascadeOnDelete();

            $table->index(['agent_user_id', 'valid_until']);
            $table->index(['client_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mlm_point_ledger');
    }
};
