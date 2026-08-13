<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listing_offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('listing_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            // Prezzo pieno del prodotto FOTOGRAFATO al momento della creazione
            // dell'offerta: se in seguito il venditore cambia price_ky, questa
            // colonna resta lo storico corretto dello sconto realmente applicato,
            // invece di essere ricalcolata a posteriori sul prezzo pieno attuale.
            $table->unsignedBigInteger('full_price_ky_snapshot');
            $table->unsignedBigInteger('offer_price_ky');
            $table->unsignedTinyInteger('offer_ky_percentage')->default(100);
            $table->timestamp('expires_at');

            // Valorizzato quando un admin termina l'offerta prima della scadenza
            // naturale (AdminListingOfferController::destroy()), NULL finché resta
            // attiva. Nessun job schedulato: un'offerta è "attiva ora" quando
            // cancelled_at è NULL e expires_at è nel futuro, calcolato a
            // query-time (stesso approccio di Listing::scopeActive()/is_expired) —
            // così il prodotto torna automaticamente al prezzo pieno alla scadenza
            // senza bisogno di alcun processo in background.
            $table->timestamp('cancelled_at')->nullable();

            $table->timestamps();

            // Righe MAI cancellate fisicamente (storico offerte passate, richiesta
            // esplicita di Laura, 2026-08-13).
            $table->index(['listing_id', 'cancelled_at', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listing_offers');
    }
};
