<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FASE C del piano carrello (PIANO_CARRELLO_VARIANTI.md): il carrello.
 *
 * Il carrello vive **sul conto**, non nella sessione del browser: chi lo
 * riempie dal telefono lo ritrova dal computer, e chi cambia browser non perde
 * quello che aveva messo dentro.
 *
 * Un conto ha al massimo UN carrello attivo per volta. Quando si va alla cassa
 * quel carrello viene marcato `ordered` e resta lì come storico; il prossimo
 * "aggiungi al carrello" ne apre uno nuovo.
 *
 * Nel carrello NON si congela il prezzo. Il prezzo si legge sempre dal prodotto
 * al momento della cassa (con gli accessor `effective_*`), così le offerte
 * della settimana continuano a funzionare da sole e nessuno paga un prezzo che
 * non è più quello esposto. Il congelamento avviene un attimo dopo, sulla riga
 * dell'ordine, dove serve davvero.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();

            // active   = è il carrello in uso
            // ordered  = è diventato uno o più ordini
            // expired  = abbandonato da più di 30 giorni
            $table->string('status', 20)->default('active');

            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            // Il carrello attivo di un conto si cerca continuamente: questo
            // indice è la query più frequente di tutta la funzione.
            $table->index(['account_id', 'status']);
        });

        Schema::create('cart_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cart_id')->constrained()->cascadeOnDelete();
            $table->foreignId('listing_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('quantity')->default(1);
            $table->timestamps();

            // Lo stesso prodotto non compare due volte nello stesso carrello:
            // aggiungerlo di nuovo aumenta la quantità di quella riga. È il
            // vincolo che rende impossibile un carrello con "Sedia x1" e
            // "Sedia x2" come righe separate, che alla cassa diventerebbero due
            // conti diversi dello stesso prodotto.
            $table->unique(['cart_id', 'listing_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cart_items');
        Schema::dropIfExists('carts');
    }
};
