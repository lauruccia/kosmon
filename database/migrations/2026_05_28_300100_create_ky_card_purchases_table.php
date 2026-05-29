<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('ky_card_purchases', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('ky_card_id')->constrained()->restrictOnDelete();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();  // conto che riceve i KY
            $table->foreignId('user_id')->constrained()->restrictOnDelete();     // utente che ha acquistato
            $table->unsignedInteger('price_eur_cents');   // snapshot del prezzo al momento dell'acquisto
            $table->unsignedBigInteger('ky_amount');      // KY effettivamente accreditati (snapshot)
            // status: pending -> pagamento Stripe in attesa; completed -> KY accreditati; failed -> fallito; refunded
            $table->enum('status', ['pending', 'completed', 'failed', 'refunded'])->default('pending');
            $table->string('stripe_checkout_session_id')->nullable()->unique();
            $table->string('stripe_payment_intent_id')->nullable();
            $table->foreignId('transfer_id')->nullable()->constrained()->nullOnDelete(); // Transfer KY generato
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['account_id', 'status']);
            $table->index('stripe_checkout_session_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ky_card_purchases');
    }
};
