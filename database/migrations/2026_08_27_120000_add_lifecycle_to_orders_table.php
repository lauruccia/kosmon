<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FASE B — l'ordine acquista un ciclo di vita.
 *
 * Fino a ieri `orders.status` aveva due valori soli, `pending_payment` e
 * `paid` (piu' `refunded`, aggiunto il 26/08 col blocco 1 dell'audit): nessuno
 * poteva sapere se il pacco fosse partito, ne' chi compra ne' chi vende.
 *
 * Qui si aggiungono solo le COLONNE del percorso di consegna. Gli stati nuovi
 * (`preparing`, `shipped`, `delivered`, `cancelled`) non hanno bisogno di
 * migrazione: `status` e' gia' una `string(30)`.
 *
 * TUTTO ADDITIVO, NESSUN BACKFILL. In produzione ci sono gia' ordini `paid` e
 * devono restare esattamente dove sono: un ordine di ieri non e' "in
 * preparazione", e fingere che lo sia sarebbe peggio che lasciarlo com'e'.
 *
 * `cancelled_at` e `cancel_reason` arrivano adesso pur essendo l'annullamento
 * un lavoro del giro successivo (muove soldi, quindi va trattato come un
 * rimborso vero): tenerle qui evita a Laura una seconda tornata di SQL a mano
 * in produzione fra qualche giorno.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Chi spedisce, e con che numero lo si insegue.
            $table->string('carrier', 60)->nullable()->after('buyer_note');
            $table->string('tracking_code', 100)->nullable()->after('carrier');

            // Quando e' successo. Sono i timestamp che la pagina dell'ordine
            // mostra come una piccola cronologia, ed e' anche il modo per
            // sapere da quanto un ordine e' fermo su uno stato.
            $table->timestamp('shipped_at')->nullable()->after('tracking_code');
            $table->timestamp('delivered_at')->nullable()->after('shipped_at');
            $table->timestamp('cancelled_at')->nullable()->after('delivered_at');
            $table->string('cancel_reason', 255)->nullable()->after('cancelled_at');

            // La pagina del venditore filtra per stato dentro la sua azienda
            // ("che cosa devo spedire oggi"): senza questo indice diventa una
            // scansione su tutta la tabella ordini del circuito.
            $table->index(['company_id', 'status', 'placed_at'], 'orders_company_status_placed_index');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_company_status_placed_index');
            $table->dropColumn([
                'carrier',
                'tracking_code',
                'shipped_at',
                'delivered_at',
                'cancelled_at',
                'cancel_reason',
            ]);
        });
    }
};
