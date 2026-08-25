<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FASE B del piano carrello (PIANO_CARRELLO_VARIANTI.md): l'ordine diventa
 * un'entità sua, invece di essere il movimento bancario.
 *
 * Fino a oggi un acquisto shop era UN Transfer con dentro `listing_id`,
 * `quantity` e l'indirizzo. Funzionava finché un acquisto = un prodotto. Il
 * carrello ha bisogno di un posto dove dire "queste tre righe sono lo stesso
 * ordine", e quel posto non poteva essere il movimento: un movimento è un
 * pagamento a UN destinatario, e il carrello ne genererà uno per venditore.
 *
 * Il rapporto fra i due resta esplicito e a senso unico: l'ordine sa qual è il
 * suo movimento (`transfers.order_id` punta all'ordine), la banca non sa niente
 * degli ordini. Se queste due tabelle sparissero, la contabilità resterebbe
 * in piedi identica.
 *
 * Le righe sono SNAPSHOT: titolo, prezzo e mix KY/EUR vengono congelati al
 * momento dell'acquisto, come già si fa con `transfers.order_title` dalla fase
 * 0b. Se il venditore poi rinomina il prodotto, ne cambia il prezzo o lo
 * cancella, l'ordine di ieri resta leggibile per sempre.
 *
 * Tutti gli importi sono in CENTESIMI, come price_ky e transfers.amount.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            // Chi compra e chi vende. I conti sono quelli che hanno realmente
            // mosso il denaro: il conto dell'acquirente e il conto business
            // principale del venditore (mai un sottoconto, vedi
            // ShopPurchaseGuardsTest).
            $table->foreignId('buyer_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('buyer_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('seller_account_id')->constrained('accounts')->restrictOnDelete();

            // pending_payment = c'è una quota in euro ancora da saldare fuori
            // dal circuito. paid = non c'è più niente da incassare.
            // La fase E aggiungerà spedito/consegnato/annullato: qui ci sono
            // solo gli stati che servono davvero oggi.
            $table->string('status', 30)->default('paid');

            $table->unsignedBigInteger('total_ky')->default(0);   // centesimi di KY
            $table->unsignedBigInteger('total_eur')->default(0);  // centesimi di EUR
            $table->unsignedBigInteger('shipping_ky')->default(0);
            $table->unsignedBigInteger('shipping_eur')->default(0);

            // Indirizzo fotografato al momento dell'acquisto: se il cliente
            // cambia poi il profilo, l'ordine già fatto resta corretto.
            $table->string('shipping_recipient_name')->nullable();
            $table->string('shipping_address')->nullable();
            $table->string('shipping_city')->nullable();
            $table->string('shipping_postal_code', 20)->nullable();
            $table->string('shipping_province', 10)->nullable();
            $table->string('shipping_phone', 40)->nullable();

            // Stesse costanti di transfers.order_source (Transfer::ORDER_SOURCE_*).
            $table->string('source', 20)->default('internal_shop');

            $table->timestamp('placed_at')->nullable();

            // Valorizzata solo per gli ordini RICOSTRUITI dai movimenti storici
            // (vedi la migrazione successiva). Serve a non spacciare per
            // registrato un dato che è stato dedotto: il prezzo unitario di un
            // ordine vecchio è ricavato dividendo il totale, non letto da
            // nessuna parte.
            $table->timestamp('backfilled_at')->nullable();

            $table->timestamps();

            $table->index(['buyer_account_id', 'placed_at']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();

            // Il collegamento al catalogo è un comodo, non una dipendenza: se
            // il prodotto viene cancellato la riga resta leggibile grazie allo
            // snapshot qui sotto.
            $table->foreignId('listing_id')->nullable()->constrained()->nullOnDelete();

            $table->string('title');
            $table->unsignedInteger('quantity')->default(1);

            // Snapshot del prezzo: prezzo pieno unitario applicato (già
            // scontato se c'era un'offerta attiva) e mix KY/EUR di quel
            // momento, più i due importi che ne derivano.
            $table->unsignedBigInteger('unit_price_ky');
            $table->unsignedTinyInteger('ky_percentage');
            $table->unsignedBigInteger('unit_ky_amount');
            $table->unsignedBigInteger('unit_eur_amount');
            $table->unsignedBigInteger('line_ky_amount');
            $table->unsignedBigInteger('line_eur_amount');

            $table->timestamps();

            $table->index('listing_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
    }
};
