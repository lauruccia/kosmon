<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * FASE 0b della separazione dello shop (vedi PIANO_SHOP_ESTERNO.md §3.1).
 *
 * Oggi un ordine shop e' leggibile solo grazie alla FK `transfers.listing_id`:
 * il titolo del prodotto viene letto in join dalla tabella `listings`. Quando
 * il catalogo uscira' da kmoney-app (app esterna "kshop"), quella tabella qui
 * non ci sara' piu' e lo storico ordini diventerebbe illeggibile — un movimento
 * senza nome di prodotto.
 *
 * La soluzione e' uno *snapshot*: il movimento si porta dietro il titolo
 * dell'ordine, l'id dell'ordine nel negozio esterno e la provenienza. Cosi' una
 * ricevuta del 2026 resta leggibile fra tre anni senza interrogare nessuno.
 *
 * NOTA sulla quantita': il piano parlava di `order_quantity`, ma su `transfers`
 * esiste gia' `quantity` (migrazione 2026_07_23_100000) con esattamente quel
 * significato. Duplicarla creerebbe solo due fonti di verita': si riusa quella.
 *
 * La FK verso `listings` viene sganciata (la colonna e l'indice restano): serve
 * a poter cancellare la tabella `listings` in fase 5 senza dover prima toccare
 * `transfers`, che e' la tabella piu' delicata del sistema. Nessun dato si
 * perde: `listing_id` continua a essere valorizzato per gli ordini interni.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transfers', function (Blueprint $table) {
            // UUID dell'ordine nel negozio esterno (kshop). NULL per gli ordini
            // dello shop interno, che non hanno un "ordine" ma un solo prodotto.
            $table->string('external_order_uuid', 36)->nullable()->after('quantity');
            // Titolo leggibile congelato al momento dell'acquisto: se il
            // venditore rinomina o cancella il prodotto, l'ordine gia' fatto
            // resta storicamente corretto (stesso principio dello snapshot
            // indirizzo di 2026_07_29_220200).
            $table->string('order_title', 255)->nullable()->after('external_order_uuid');
            // 'internal_shop' | 'kshop' — da dove arriva l'ordine.
            $table->string('order_source', 20)->nullable()->after('order_title');

            $table->index('external_order_uuid');
        });

        // Backfill dello storico: ogni ordine gia' registrato prende il titolo
        // del prodotto a cui punta. Un UPDATE per prodotto (i prodotti sono
        // poche centinaia) invece di una join, cosi' funziona identico su MySQL
        // e su SQLite dei test.
        if (Schema::hasTable('listings')) {
            foreach (DB::table('listings')->select('id', 'title')->orderBy('id')->cursor() as $listing) {
                DB::table('transfers')
                    ->where('kind', 'portal_marketplace_order')
                    ->where('listing_id', $listing->id)
                    ->whereNull('order_title')
                    ->update([
                        'order_title'  => $listing->title,
                        'order_source' => 'internal_shop',
                    ]);
            }
        }

        // Sgancia la FK ma tiene colonna e indice. SQLite non sa cancellare una
        // foreign key senza ricostruire la tabella: nei test non serve, la
        // tabella `listings` c'e' sempre.
        if (DB::getDriverName() === 'mysql') {
            Schema::table('transfers', function (Blueprint $table) {
                $table->dropForeign(['listing_id']);
            });
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            Schema::table('transfers', function (Blueprint $table) {
                $table->foreign('listing_id')->references('id')->on('listings')->nullOnDelete();
            });
        }

        Schema::table('transfers', function (Blueprint $table) {
            $table->dropIndex(['external_order_uuid']);
            $table->dropColumn(['external_order_uuid', 'order_title', 'order_source']);
        });
    }
};
