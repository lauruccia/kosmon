<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tipo di consegna/erogazione del prodotto shop (richiesta di Laura,
 * 2026-07-29 sera): un'azienda può vendere un prodotto fisico da spedire, un
 * prodotto/servizio da ritirare in sede, oppure un servizio online o
 * comunque non spedibile. Solo il primo caso richiede un indirizzo di
 * spedizione dal cliente e prevede un eventuale costo di spedizione fissato
 * dall'azienda venditrice (vedi App\Models\Listing::DELIVERY_TYPES).
 *
 * Il costo di spedizione segue lo stesso mix KY/EUR del prodotto (scelta di
 * Laura): non è quindi un importo "sempre KY" o "sempre EUR" a parte, ma un
 * supplemento sul prezzo totale che si divide con la stessa percentuale.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            // Default 'servizio' (il meno vincolante: nessun indirizzo richiesto)
            // così i prodotti già esistenti restano acquistabili senza modifiche.
            $table->string('delivery_type', 20)->default('servizio')->after('delivery_note');
            // Centesimi di KY, stessa convenzione di price_ky. Nullable: non tutti
            // i prodotti "spedizione" hanno necessariamente un costo di spedizione
            // (es. spedizione gratuita).
            $table->unsignedInteger('shipping_cost')->nullable()->after('delivery_type');
        });
    }

    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->dropColumn(['delivery_type', 'shipping_cost']);
        });
    }
};
