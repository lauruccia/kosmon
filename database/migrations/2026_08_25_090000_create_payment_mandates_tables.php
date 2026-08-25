<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Il mandato di pagamento — fase 2a di PIANO_SHOP_ESTERNO.md (§5).
 *
 * Due tabelle, e la seconda non è un dettaglio contabile: è quella che rende
 * possibili tre cose che senza un legame esplicito fra mandato e movimento non
 * si potrebbero fare in modo affidabile —
 *
 *   1. l'IDEMPOTENZA per mandato: un retry di rete di kshop non deve mai
 *      generare due addebiti (la chiave è unica per mandato);
 *   2. l'ANTIFURTO: "quanti addebiti automatici in quest'ultima ora?" è una
 *      domanda che si fa a questa tabella, non a un contatore che non sa il
 *      tempo;
 *   3. lo STORICO che l'utente vede nella pagina "App collegate", distinto
 *      dagli acquisti che ha confermato a mano.
 *
 * Nessuna di queste tabelle contiene denaro: il denaro resta dove è sempre
 * stato, nei `transfers` e nel registro in partita doppia. Qui c'è solo il
 * permesso, e la traccia di come è stato usato.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_mandates', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();

            // L'applicazione a cui è concesso (config/oauth.php), non un id a database.
            $table->string('client_id', 100)->index();

            // L'UNICO limite: tetto per singolo acquisto, in centesimi di KY.
            // Nessun plafond di periodo, nessun contatore di spesa: vedi §5 del
            // piano per il perché, e per le due protezioni che lo compensano.
            $table->unsignedInteger('max_per_transaction');

            // Venditori da cui si può addebitare senza chiedere niente. Cresce
            // SOLO con una conferma esplicita dell'utente: è la prima delle due
            // protezioni al posto del plafond.
            $table->text('authorized_sellers');

            $table->timestamp('expires_at');
            $table->timestamp('suspended_at')->nullable();   // antifurto automatico
            $table->timestamp('revoked_at')->nullable();     // revoca dell'utente

            $table->unsignedInteger('charges_count')->default(0);
            $table->timestamp('last_used_at')->nullable();
            $table->string('created_ip', 45)->nullable();

            $table->timestamps();

            // La domanda più frequente: "questo utente ha un mandato vivo per
            // questa applicazione?"
            $table->index(['user_id', 'client_id']);
        });

        Schema::create('payment_mandate_charges', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('payment_mandate_id')->constrained()->cascadeOnDelete();
            $table->foreignId('transfer_id')->constrained()->cascadeOnDelete();

            $table->unsignedInteger('amount');            // centesimi di KY
            $table->string('seller_account_number', 32);  // KYB… del venditore
            $table->string('external_order_uuid', 64)->nullable();
            $table->string('order_title')->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->string('created_ip', 45)->nullable();

            $table->timestamps();

            // Idempotenza: la stessa chiave sullo stesso mandato non può
            // produrre due addebiti. È il vincolo che regge la promessa fatta
            // nel piano ("un retry di rete non deve mai generare due addebiti")
            // anche se domani il codice attorno cambiasse.
            $table->string('idempotency_key', 100);
            $table->unique(['payment_mandate_id', 'idempotency_key'], 'mandate_charges_idempotency_unique');

            // Serve all'antifurto: "quanti addebiti nell'ultima ora?"
            $table->index(['payment_mandate_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_mandate_charges');
        Schema::dropIfExists('payment_mandates');
    }
};
