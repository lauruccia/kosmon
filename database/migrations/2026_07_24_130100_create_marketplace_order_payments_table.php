<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ciclo di vita del pagamento EUR (quota non-KY) di un ordine shop.
 *
 * Un ordine shop (Transfer kind=portal_marketplace_order) può avere una quota
 * EUR da pagare fuori dal circuito KY tramite uno dei metodi configurati
 * dall'azienda venditrice (payment_gateways). Questa tabella traccia se/come
 * quella quota è stata pagata, separata dal Transfer (che resta dedicato solo
 * al movimento KY) per gestire tentativi, fallimenti e conferme senza toccare
 * il ledger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_order_payments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('transfer_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('listing_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete(); // azienda venditrice
            $table->foreignId('payment_gateway_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider', 20)->nullable(); // copiato al momento della scelta: sopravvive se il gateway viene poi rimosso
            $table->unsignedInteger('amount'); // centesimi di EUR
            $table->string('currency_code', 3)->default('EUR');
            $table->string('status', 20)->default('pending'); // pending|awaiting_confirmation|paid|failed|cancelled
            $table->string('provider_reference', 190)->nullable(); // id sessione/ordine lato Stripe/PayPal
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('confirmed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_order_payments');
    }
};
