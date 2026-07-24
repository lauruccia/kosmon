<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Metodi di pagamento EUR configurati da ciascuna azienda (o dall'admin per
 * suo conto) per incassare la quota EUR dei prodotti shop con mix KY/EUR.
 *
 * Ogni riga è UN metodo attivabile per un'azienda (stripe, paypal o
 * bank_transfer): le credenziali sono quelle dell'account INDIPENDENTE
 * dell'azienda stessa (non un conto "figlio" di una piattaforma Kosmopay) —
 * il pagamento va sempre diretto lì, Kosmopay non intermedia mai i soldi EUR.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_gateways', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 20); // stripe | paypal | bank_transfer
            $table->string('label', 100)->nullable(); // etichetta libera opzionale (es. "Conto principale")
            $table->boolean('is_active')->default(true);
            $table->text('credentials')->nullable(); // cifrato: chiavi API o dati IBAN, secondo il provider
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'provider']);
            $table->index(['company_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_gateways');
    }
};
