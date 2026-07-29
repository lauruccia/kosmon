<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Snapshot dell'indirizzo di spedizione al momento dell'acquisto (solo per
 * kind = portal_marketplace_order con prodotto di tipo "spedizione") + costo
 * di spedizione realmente addebitato in quell'ordine. Campi "pass-through"
 * sullo stesso Transfer, stesso pattern di listing_id/quantity aggiunti il
 * 2026-07-23 (vedi 2026_07_23_100000_add_marketplace_order_fields.php):
 * copiati da Account::shipping_* al momento del book() così che l'ordine
 * resti storicamente corretto anche se il cliente cambia poi l'indirizzo sul
 * proprio profilo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transfers', function (Blueprint $table) {
            $table->string('shipping_recipient_name', 150)->nullable()->after('quantity');
            $table->string('shipping_address', 255)->nullable()->after('shipping_recipient_name');
            $table->string('shipping_city', 100)->nullable()->after('shipping_address');
            $table->string('shipping_postal_code', 12)->nullable()->after('shipping_city');
            $table->string('shipping_province', 60)->nullable()->after('shipping_postal_code');
            $table->string('shipping_phone', 30)->nullable()->after('shipping_province');
            // Centesimi di KY, quota di spedizione già mixata KY/EUR come il resto
            // dell'ordine — qui salviamo solo la QUOTA KY effettivamente addebitata
            // nel circuito (coerente con transfers.amount, che è sempre e solo KY).
            $table->unsignedInteger('shipping_ky_amount')->nullable()->after('shipping_phone');
        });
    }

    public function down(): void
    {
        Schema::table('transfers', function (Blueprint $table) {
            $table->dropColumn([
                'shipping_recipient_name',
                'shipping_address',
                'shipping_city',
                'shipping_postal_code',
                'shipping_province',
                'shipping_phone',
                'shipping_ky_amount',
            ]);
        });
    }
};
