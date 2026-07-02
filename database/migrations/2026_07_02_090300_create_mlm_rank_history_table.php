<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mlm_rank_history', function (Blueprint $table): void {
            $table->id();
            $table->string('uuid', 36)->unique();

            $table->unsignedBigInteger('agent_user_id');
            $table->enum('rank', ['start', 'basic', 'key', 'senior', 'top', 'supervisor', 'manager']);
            $table->timestamp('achieved_at');

            // Snapshot dei requisiti verificati al momento dell'avanzamento (audit/compliance)
            $table->json('evaluation_snapshot')->nullable();

            $table->timestamps();

            $table->foreign('agent_user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index(['agent_user_id', 'achieved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mlm_rank_history');
    }
};
