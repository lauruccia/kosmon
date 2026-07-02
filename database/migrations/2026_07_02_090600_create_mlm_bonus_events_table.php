<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Evento "diventato BasiQ" che genera la cascata di bonus verso l'upline. */
    public function up(): void
    {
        Schema::create('mlm_bonus_events', function (Blueprint $table): void {
            $table->id();
            $table->string('uuid', 36)->unique();

            $table->unsignedBigInteger('basiq_user_id');
            $table->timestamp('triggered_at');
            $table->json('upline_chain_snapshot')->nullable(); // ordine catena upline con rank, per audit

            $table->enum('status', ['pending', 'processed', 'ignored_late_basiq'])->default('pending');
            $table->timestamp('processed_at')->nullable();

            $table->timestamps();

            $table->foreign('basiq_user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->unique(['basiq_user_id']); // un solo evento BasiQ per agente
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mlm_bonus_events');
    }
};
