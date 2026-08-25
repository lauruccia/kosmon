<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FASE D del piano carrello (PIANO_CARRELLO_VARIANTI.md): prodotti variabili.
 *
 * Quattro tabelle, due mondi distinti:
 *
 *   L'ADMIN definisce il vocabolario — `listing_attributes` ("Taglia",
 *   "Colore") e `listing_attribute_values` ("S", "M", "L", "rosso"). Scelta
 *   esplicita di Laura (25/08/2026): il venditore NON inventa attributi, sceglie
 *   fra quelli che esistono. È la stessa impostazione delle categorie shop
 *   (`listing_categories`, 12/08): un vocabolario comune tiene il catalogo
 *   ordinato e rende possibile, un domani, filtrare per taglia o per colore.
 *
 *   IL VENDITORE compone le sue combinazioni — `listing_variants` (una riga per
 *   "Taglia M + Colore rosso") e il collegamento ai valori scelti.
 *
 * IL PREZZO DELLA VARIANTE È UN DELTA, non un prezzo assoluto. Il venditore
 * digita "22,00" e il sistema salva "+2,00" rispetto ai 20,00 del prodotto.
 * Non è un vezzo: le Offerte della settimana (`listing_offers`) abbassano il
 * prezzo BASE, e con un delta la XL resta "due euro più della base" anche
 * durante l'offerta — il conto torna da solo. Con i prezzi assoluti avremmo
 * dovuto vietare le offerte sui prodotti variabili oppure scrivere un secondo
 * motore di prezzi accanto a quello che c'è già.
 *
 * Importi in CENTESIMI.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Il vocabolario, gestito dall'admin ───────────────────────────────

        Schema::create('listing_attributes', function (Blueprint $table) {
            $table->id();
            // Lo slug è l'identificativo STABILE: rinominare "Taglia" in
            // "Misura" dal pannello non scollega niente.
            $table->string('slug', 60)->unique();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('listing_attribute_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('listing_attribute_id')->constrained()->cascadeOnDelete();
            $table->string('slug', 60);
            $table->string('value');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['listing_attribute_id', 'slug']);
        });

        // ── Le combinazioni, composte dal venditore ──────────────────────────

        Schema::create('listing_variants', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('listing_id')->constrained()->cascadeOnDelete();

            $table->string('sku', 80)->nullable();

            // DELTA rispetto al prezzo del prodotto, in centesimi. Può essere
            // negativo (la taglia piccola costa meno). Firmato, quindi.
            $table->integer('price_delta_ky')->default(0);

            // NULL = illimitato, esattamente come `listings.stock_quantity`.
            // Per un prodotto con varianti è QUESTO lo stock che conta.
            $table->unsignedInteger('stock_quantity')->nullable();

            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['listing_id', 'is_active']);
        });

        Schema::create('listing_variant_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('listing_variant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('listing_attribute_value_id')->constrained()->cascadeOnDelete();

            // Una variante non può avere due volte lo stesso valore.
            $table->unique(['listing_variant_id', 'listing_attribute_value_id'], 'variant_value_unique');
            $table->index('listing_attribute_value_id');
        });

        // ── Gli agganci sulle tabelle che esistono già ───────────────────────

        Schema::table('listings', function (Blueprint $table) {
            // Interruttore esplicito invece di contare le varianti: un prodotto
            // può avere varianti in preparazione e non essere ancora "variabile"
            // per chi compra. E le query di lettura non devono fare un COUNT.
            $table->boolean('has_variants')->default(false);
        });

        Schema::table('cart_items', function (Blueprint $table) {
            $table->foreignId('listing_variant_id')->nullable()->constrained()->cascadeOnDelete();
        });

        // Il vincolo del carrello si allarga: prima "un prodotto una volta
        // sola", ora "una COMBINAZIONE una volta sola" — la M e la L dello
        // stesso maglione sono due righe legittime.
        //
        // Nota su cosa questo vincolo NON copre: in MySQL due NULL non sono
        // considerati uguali, quindi per i prodotti SENZA varianti (dove
        // listing_variant_id è NULL) l'unique non impedisce il doppione. A
        // garantirlo è CartService::aggiungi(), che cerca la riga esistente
        // prima di crearne una — e c'è un test apposta
        // (test_aggiungere_due_volte_lo_stesso_prodotto_somma_le_quantita).
        // Prima si crea il nuovo indice, POI si toglie il vecchio: MySQL usa
        // l'unique (cart_id, listing_id) anche per la foreign key su cart_id, e
        // toglierlo per primo risponde "#1553 needed in a foreign key
        // constraint". Il nuovo comincia anch'esso per cart_id, quindi ne
        // prende il posto e sblocca il DROP. (Su SQLite passava in entrambi gli
        // ordini: l'errore si vede solo su MySQL/MariaDB.)
        Schema::table('cart_items', function (Blueprint $table) {
            $table->unique(['cart_id', 'listing_id', 'listing_variant_id'], 'cart_items_riga_unique');
        });

        Schema::table('cart_items', function (Blueprint $table) {
            $table->dropUnique('cart_items_cart_id_listing_id_unique');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->foreignId('listing_variant_id')->nullable()->constrained()->nullOnDelete();
            // Snapshot come il titolo: "Taglia: M · Colore: rosso" congelato al
            // momento dell'acquisto. Se il venditore poi cancella la variante o
            // l'admin rinomina un valore, l'ordine di ieri resta leggibile.
            $table->string('variant_label')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('listing_variant_id');
            $table->dropColumn('variant_label');
        });

        // Stessa attenzione al contrario: prima si rimette il vecchio indice,
        // poi si toglie il nuovo.
        Schema::table('cart_items', function (Blueprint $table) {
            $table->unique(['cart_id', 'listing_id'], 'cart_items_cart_id_listing_id_unique');
        });

        Schema::table('cart_items', function (Blueprint $table) {
            $table->dropUnique('cart_items_riga_unique');
            $table->dropConstrainedForeignId('listing_variant_id');
        });

        Schema::table('listings', function (Blueprint $table) {
            $table->dropColumn('has_variants');
        });

        Schema::dropIfExists('listing_variant_values');
        Schema::dropIfExists('listing_variants');
        Schema::dropIfExists('listing_attribute_values');
        Schema::dropIfExists('listing_attributes');
    }
};
