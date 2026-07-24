<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_payments', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete(); // chi ha avviato l'upgrade

            $table->foreignId('from_plan_id')->nullable()->constrained('plans')->nullOnDelete();
            $table->foreignId('to_plan_id')->constrained('plans')->cascadeOnDelete();

            // Differenza addebitata (prezzo piano target - prezzo piano attuale), centesimi.
            $table->unsignedInteger('amount_cents');

            $table->string('status', 30)->default('pending'); // pending | pending_bank_transfer | completed | failed | cancelled
            $table->string('payment_method', 20); // stripe | paypal | bank_transfer | ky

            $table->string('stripe_checkout_session_id')->nullable();
            $table->string('stripe_payment_intent_id')->nullable();
            $table->string('paypal_order_id')->nullable();
            $table->foreignId('ky_transfer_id')->nullable()->constrained('transfers')->nullOnDelete();

            $table->text('admin_notes')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_payments');
    }
};
