<?php

use App\Support\SchemaIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Collega i movimenti generati dall'acquisto di un prodotto shop al prodotto
     * stesso (listing_id + quantity su transfers), e aggiunge la gestione dello
     * stock ai prodotti (stock_quantity su listings, NULL = illimitato).
     */
    public function up(): void
    {
        Schema::table('transfers', function (Blueprint $table) {
            $table->foreignId('listing_id')
                ->nullable()
                ->after('related_transfer_id')
                ->constrained('listings')
                ->nullOnDelete();
            $table->unsignedInteger('quantity')->nullable()->after('listing_id');

            $table->index(['listing_id']);
        });

        Schema::table('listings', function (Blueprint $table) {
            // NULL = stock illimitato (comportamento storico). Un numero >= 0
            // attiva la gestione dello stock: il prodotto risulta esaurito a 0.
            $table->unsignedInteger('stock_quantity')->nullable()->after('ky_percentage');
        });
    }

    public function down(): void
    {
        // Prima la chiave esterna, poi l'indice: nell'ordine opposto MySQL
        // rifiuta con 1553, perche' finche' la FK esiste quell'indice le serve
        // (B7, 31/08). Togliendo la colonna l'indice se ne va da solo: la
        // chiamata dopo e' una rete di sicurezza, non fa nulla se non c'e'.
        Schema::table('transfers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('listing_id');
            $table->dropColumn('quantity');
        });

        SchemaIndex::dropIfExists('transfers', 'transfers_listing_id_index');

        Schema::table('listings', function (Blueprint $table) {
            $table->dropColumn('stock_quantity');
        });
    }
};
