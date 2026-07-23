<?php

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
        Schema::table('transfers', function (Blueprint $table) {
            $table->dropIndex(['listing_id']);
            $table->dropConstrainedForeignId('listing_id');
            $table->dropColumn('quantity');
        });

        Schema::table('listings', function (Blueprint $table) {
            $table->dropColumn('stock_quantity');
        });
    }
};
