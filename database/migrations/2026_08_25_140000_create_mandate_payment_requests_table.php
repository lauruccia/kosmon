<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Il ramo della conferma — fase 2b di PIANO_SHOP_ESTERNO.md (§5).
 *
 * Quando l'addebito in un clic non si può fare da solo (sopra il tetto, primo
 * acquisto da quel venditore, mandato sospeso, saldo insufficiente) la risposta
 * non è "no": è **una richiesta di pagamento da confermare**. Questa tabella è
 * il ponte fra le due cose, e serve a tre scopi che nessuna delle due tabelle
 * già esistenti potrebbe reggere da sola:
 *
 *  1. **Un ordine, una richiesta.** `UNIQUE(payment_mandate_id,
 *     idempotency_key)`: se kshop ritenta l'addebito dieci volte perché la rete
 *     va e viene, l'utente non si ritrova dieci link di conferma da pagare per
 *     lo stesso carrello. Riceve sempre lo stesso.
 *  2. **Il ritorno a 200.** La conferma a mano registra una
 *     `PaymentMandateCharge` con la STESSA `idempotency_key` che aveva chiesto
 *     kshop. Così il primo retry dopo la conferma trova l'addebito già fatto e
 *     risponde 200 con il movimento: kshop non ha bisogno di sapere che c'è
 *     stata una conferma di mezzo, né di stare in ascolto.
 *  3. **Il confine dell'antifurto.** Un acquisto confermato a mano dall'utente
 *     non è un addebito automatico e non deve contare nei "10 in un'ora": è
 *     questa riga a distinguerli (vedi `PaymentMandate::recentChargesCount()`).
 *
 * La richiesta di pagamento vera resta una `PaymentRequest` normale, con il suo
 * `kind` (`kshop_order`): riusa la pagina di pagamento del portale, il link
 * "Ricarica ora" con ritorno automatico, e il webhook `payment_request.paid`
 * che i venditori già conoscono. Qui dentro non c'è denaro: c'è il contesto
 * dell'ordine e il motivo per cui è servito disturbare l'utente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mandate_payment_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('payment_mandate_id')->constrained()->cascadeOnDelete();

            // La richiesta di pagamento attualmente in piedi per questo ordine.
            // Non è una colonna immutabile: se la prima scade senza essere
            // pagata e kshop richiede l'addebito, qui viene puntata la nuova.
            $table->unsignedBigInteger('payment_request_id')->index();

            $table->string('client_id', 100)->index();
            $table->string('seller_account_number', 32);
            $table->unsignedInteger('amount');            // centesimi di KY

            $table->string('external_order_uuid', 64)->nullable();
            $table->string('order_title')->nullable();
            $table->unsignedInteger('quantity')->default(1);

            // La chiave che aveva chiesto kshop: è quella che tornerà a 200.
            $table->string('idempotency_key', 100);

            // Perché è servita la conferma (amount_above_limit,
            // seller_not_authorized, mandate_suspended, limit_exceeded, ...).
            // Serve a spiegarlo all'utente nella pagina di conferma, e a noi
            // per sapere quale delle protezioni sta scattando davvero.
            $table->string('reason', 40);

            // Dove tornare su kshop dopo il pagamento. Validato contro l'elenco
            // chiuso di config/oauth.php PRIMA di finire qui: in questa colonna
            // non entra mai un indirizzo non autorizzato.
            $table->string('return_url', 500)->nullable();

            $table->timestamp('confirmed_at')->nullable();
            $table->unsignedBigInteger('payment_mandate_charge_id')->nullable()->index();

            $table->timestamps();

            // Il cuore della tabella: un ordine, una richiesta di conferma.
            $table->unique(['payment_mandate_id', 'idempotency_key'], 'mandate_payment_requests_key_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mandate_payment_requests');
    }
};
