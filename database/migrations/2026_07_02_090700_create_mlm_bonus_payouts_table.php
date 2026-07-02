<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Distribuzione del bonus di un evento fra i beneficiari in upline (accredito il mercoledì). */
    public function up(): void
    {
        Schema::create('mlm_bonus_payouts', function (Blueprint $table): void {
            $table->id();
            $table->string('uuid', 36)->unique();

            $table->foreignId('mlm_bonus_event_id')->constrained('mlm_bonus_events')->cascadeOnDelete();
            $table->unsignedBigInteger('beneficiary_user_id');
            $table->enum('rank_at_time', ['key', 'senior', 'top', 'supervisor', 'manager']);

            $table->unsignedBigInteger('amount_eur_cents');
            $table->date('week_ending'); // mercoledì di accredito

            $table->enum('status', ['pending', 'approved', 'paid', 'cancelled'])->default('pending');
            $table->string('idempotency_key', 64)->unique();

            $table->timestamps();

            $table->foreign('beneficiary_user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index(['beneficiary_user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mlm_bonus_payouts');
    }
};
