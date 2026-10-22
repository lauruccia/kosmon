<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * KYCard a rate e KYCard a rinnovo mensile (22/10/2026).
     *
     * - `ky_card_subscriptions`: un abbonamento Stripe per cliente e per card.
     *   kind = 'installments' (N rate, si chiude da solo) oppure 'recurring'
     *   (rinnovo mensile finche' il cliente non disdice). Gli importi sono
     *   uno SNAPSHOT preso al momento dell'acquisto: se il prezzo della card
     *   cambia dopo, gli abbonamenti gia' partiti non si toccano.
     * - `ky_cards`: due flag (rate accettate, ricorrenza accettata) con cui
     *   l'admin decide card per card cosa si puo' pagare a rate o a rinnovo.
     * - `ky_card_purchases`: ogni fattura pagata e' UNA riga, con
     *   stripe_invoice_id UNIVOCO — e' la difesa contro il doppio accredito
     *   quando Stripe rispedisce lo stesso evento.
     *
     * Su stringhe e non su ENUM, di proposito: kind e status cambiano nel
     * tempo, e su MySQL un ENUM si modifica solo con un ALTER a mano da
     * phpMyAdmin (il server non esegue migrate).
     */
    public function up(): void
    {
        Schema::create('ky_card_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('ky_card_id')->constrained()->restrictOnDelete();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();

            $table->string('kind', 20);                       // installments | recurring
            $table->unsignedSmallInteger('installments_total')->nullable();
            $table->unsignedSmallInteger('installments_paid')->default(0);

            $table->unsignedInteger('amount_cents');          // importo di OGNI addebito, in centesimi di euro
            $table->unsignedInteger('card_price_cents');      // prezzo intero della card (snapshot)
            $table->unsignedBigInteger('ky_total');           // KY della card (snapshot), in centesimi di KY

            // pending -> active -> completed (rate finite) | past_due -> defaulted (rata non pagata)
            // | cancelling (disdetta richiesta, ultimo periodo pagato) -> cancelled
            $table->string('status', 20)->default('pending');

            $table->string('stripe_checkout_session_id')->nullable()->unique();
            $table->string('stripe_customer_id')->nullable();
            $table->string('stripe_subscription_id')->nullable()->unique();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('next_billing_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['account_id', 'status']);
            $table->index('status');
        });

        Schema::table('ky_cards', function (Blueprint $table): void {
            $table->boolean('installments_enabled')->default(false);
            // Numeri di rate offerti, es. [3,6,12]. Solo quelli che dividono
            // esattamente prezzo e KY (lo controlla il form admin).
            // text e non json: il cast `array` del modello fa gia' il lavoro, e
            // un tipo JSON vero non e' garantito sul MySQL del vecchio hosting.
            $table->text('installment_options')->nullable();
            $table->boolean('recurring_enabled')->default(false);
        });

        Schema::table('ky_card_purchases', function (Blueprint $table): void {
            $table->foreignId('ky_card_subscription_id')->nullable()
                ->constrained('ky_card_subscriptions')->nullOnDelete();
            $table->unsignedSmallInteger('installment_no')->nullable();
            $table->string('stripe_invoice_id')->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('ky_card_purchases', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('ky_card_subscription_id');
            $table->dropUnique(['stripe_invoice_id']);
            $table->dropColumn(['installment_no', 'stripe_invoice_id']);
        });

        Schema::table('ky_cards', function (Blueprint $table): void {
            $table->dropColumn(['installments_enabled', 'installment_options', 'recurring_enabled']);
        });

        Schema::dropIfExists('ky_card_subscriptions');
    }
};
