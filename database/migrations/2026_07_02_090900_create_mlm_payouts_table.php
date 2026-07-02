<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Liquidazione EUR aggregata per agente/periodo (somma commissioni + bonus
     * approvati in un dato periodo). Flusso: pending -> approved -> paid.
     */
    public function up(): void
    {
        Schema::create('mlm_payouts', function (Blueprint $table): void {
            $table->id();
            $table->string('uuid', 36)->unique();

            $table->unsignedBigInteger('agent_user_id');
            $table->date('period_from');
            $table->date('period_to');

            $table->unsignedBigInteger('commissions_total_eur_cents')->default(0);
            $table->unsignedBigInteger('bonus_total_eur_cents')->default(0);
            $table->unsignedBigInteger('total_eur_cents')->default(0);

            $table->enum('status', ['pending', 'approved', 'paid', 'rejected'])->default('pending');
            $table->unsignedBigInteger('approved_by_user_id')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->string('payment_reference')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->text('admin_notes')->nullable();

            $table->timestamps();

            $table->foreign('agent_user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('approved_by_user_id')->references('id')->on('users')->nullOnDelete();

            $table->index(['agent_user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mlm_payouts');
    }
};
