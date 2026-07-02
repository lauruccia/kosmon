<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Batch mensile di calcolo commissioni (1° del mese, ore 02:00). */
    public function up(): void
    {
        Schema::create('mlm_commission_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('uuid', 36)->unique();

            $table->date('period_month'); // primo giorno del mese di riferimento
            $table->string('idempotency_key', 64)->unique();
            $table->enum('status', ['pending', 'running', 'completed', 'failed'])->default('pending');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('error')->nullable();

            $table->timestamps();

            $table->unique(['period_month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mlm_commission_runs');
    }
};
