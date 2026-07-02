<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Dettaglio commissioni (dirette e indirette) generate da un run mensile. */
    public function up(): void
    {
        Schema::create('mlm_commissions', function (Blueprint $table): void {
            $table->id();
            $table->string('uuid', 36)->unique();

            $table->foreignId('mlm_commission_run_id')->constrained('mlm_commission_runs')->cascadeOnDelete();
            $table->unsignedBigInteger('agent_user_id');

            $table->enum('type', ['diretta', 'indiretta']);
            $table->unsignedBigInteger('source_client_id'); // cliente che ha depositato
            $table->unsignedBigInteger('source_agent_id')->nullable(); // per le indirette: l'agente proprietario del cliente
            $table->unsignedTinyInteger('level')->nullable(); // 1-5 per le indirette, null per le dirette

            $table->unsignedBigInteger('base_amount_eur_cents'); // "importo mensile" commissionabile
            $table->decimal('percentage', 6, 3);
            $table->unsignedBigInteger('amount_eur_cents');

            $table->enum('status', ['pending', 'approved', 'paid', 'cancelled'])->default('pending');
            $table->string('idempotency_key', 64)->unique();

            $table->timestamps();

            $table->foreign('agent_user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('source_client_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('source_agent_id')->references('id')->on('users')->nullOnDelete();

            $table->index(['agent_user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mlm_commissions');
    }
};
