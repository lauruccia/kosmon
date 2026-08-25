<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * FASE B, seconda parte: aggancia gli ordini ai movimenti già esistenti e
 * ricostruisce lo storico.
 *
 * Due colonne nuove, nessuna colonna modificata, nessun vincolo rimosso.
 *
 * NOTA su `marketplace_order_payments.transfer_id`, che il piano dava per
 * "vincolo da smontare": è rimasto com'è, unique compreso. Riguardandolo con
 * l'ordine in mano il problema si scioglie da solo — un ordine ha un solo
 * venditore, quindi un solo movimento, quindi una sola quota in euro. Il
 * rapporto fra movimento e pagamento resta uno a uno anche col carrello, e
 * l'unique continua a proteggere esattamente ciò che deve. La fase B non ha
 * quindi nessuna modifica distruttiva.
 *
 * Il BACKFILL: ogni movimento `portal_marketplace_order` già esistente diventa
 * un ordine con una riga. Serve perché senza di lui ogni pagina che mostra
 * ordini dovrebbe gestire per sempre due formati, "vecchio stile" (leggo dal
 * movimento) e "nuovo stile" (leggo dall'ordine) — ed è lì che nascono i bug
 * che si scoprono mesi dopo. Gli ordini ricostruiti sono marcati con
 * `backfilled_at`: il prezzo unitario di un ordine vecchio è DEDOTTO dividendo
 * il totale per la quantità, non letto da nessuna parte, e chi legge deve
 * poterlo sapere.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Colonne aggiunte IN FONDO, senza `after()`: su `transfers` (tabella
        // grande, sito acceso) è istantaneo e non blocca, mentre inserirle in
        // mezzo ricostruirebbe tutta la tabella. Per Laravel l'ordine delle
        // colonne non conta.
        Schema::table('transfers', function (Blueprint $table) {
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
        });

        Schema::table('marketplace_order_payments', function (Blueprint $table) {
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
        });

        $this->backfill();
    }

    public function down(): void
    {
        // Gli ordini se ne vanno TUTTI, non solo quelli ricostruiti.
        //
        // Sembra drastico, ed è invece l'unica cosa coerente: questo down()
        // toglie `transfers.order_id`, cioè l'unico filo che lega un ordine al
        // suo movimento. Gli ordini che restassero sarebbero orfani —
        // irraggiungibili da qualsiasi pagina — e al primo `migrate` successivo
        // il backfill, che riparte da "tutti i movimenti senza order_id",
        // creerebbe un secondo ordine per lo stesso acquisto. Meglio tornare
        // davvero indietro e lasciare che sia il backfill a ricostruirli.
        //
        // La verità contabile non è qui e non viene toccata: i movimenti e il
        // ledger restano intatti, ed è da lì che gli ordini si rifanno.
        DB::table('orders')->delete();

        Schema::table('marketplace_order_payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('order_id');
        });

        Schema::table('transfers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('order_id');
        });
    }

    /**
     * Ricostruisce un ordine (e la sua unica riga) per ogni movimento di
     * acquisto shop che non ne ha ancora uno.
     */
    private function backfill(): void
    {
        DB::table('transfers')
            ->where('kind', 'portal_marketplace_order')
            ->whereNull('order_id')
            ->orderBy('id')
            ->chunkById(500, function ($movimenti) {
                foreach ($movimenti as $t) {
                    $sellerAccount = DB::table('accounts')->find($t->to_account_id);
                    if (! $sellerAccount || ! $sellerAccount->company_id) {
                        // Movimento verso un conto che non è di un'azienda: non
                        // è un ordine shop ricostruibile, lo si lascia com'è.
                        continue;
                    }

                    $payment = DB::table('marketplace_order_payments')
                        ->where('transfer_id', $t->id)
                        ->first();

                    $quantity   = max(1, (int) ($t->quantity ?? 1));
                    $shippingKy = (int) ($t->shipping_ky_amount ?? 0);
                    $totalKy    = (int) $t->amount;
                    $totalEur   = (int) ($payment->amount ?? 0);

                    // Prezzo unitario DEDOTTO: (totale - spedizione) / quantità.
                    // Per questo l'ordine viene marcato `backfilled_at`.
                    $unitKy  = intdiv(max(0, $totalKy - $shippingKy), $quantity);
                    $unitEur = intdiv($totalEur, $quantity);
                    $unitPieno = $unitKy + $unitEur;
                    $kyPercentage = $unitPieno > 0
                        ? (int) round($unitKy * 100 / $unitPieno)
                        : 100;

                    $titolo = trim((string) ($t->order_title ?? ''));
                    if ($titolo === '' && $t->listing_id) {
                        $titolo = (string) DB::table('listings')->where('id', $t->listing_id)->value('title');
                    }
                    if ($titolo === '') {
                        $titolo = 'Ordine shop';
                    }

                    $stato = ($payment && $payment->status !== 'paid') ? 'pending_payment' : 'paid';
                    $adesso = now();

                    $orderId = DB::table('orders')->insertGetId([
                        'uuid'                    => (string) Str::uuid(),
                        'buyer_account_id'        => $t->from_account_id,
                        'buyer_user_id'           => $t->initiated_by,
                        'company_id'              => $sellerAccount->company_id,
                        'seller_account_id'       => $t->to_account_id,
                        'status'                  => $stato,
                        'total_ky'                => $totalKy,
                        'total_eur'               => $totalEur,
                        'shipping_ky'             => $shippingKy,
                        'shipping_eur'            => 0, // non ricostruibile dai dati storici
                        'shipping_recipient_name' => $t->shipping_recipient_name ?? null,
                        'shipping_address'        => $t->shipping_address ?? null,
                        'shipping_city'           => $t->shipping_city ?? null,
                        'shipping_postal_code'    => $t->shipping_postal_code ?? null,
                        'shipping_province'       => $t->shipping_province ?? null,
                        'shipping_phone'          => $t->shipping_phone ?? null,
                        'source'                  => $t->order_source ?: 'internal_shop',
                        'placed_at'               => $t->created_at,
                        'backfilled_at'           => $adesso,
                        'created_at'              => $t->created_at,
                        'updated_at'              => $adesso,
                    ]);

                    DB::table('order_items')->insert([
                        'order_id'        => $orderId,
                        'listing_id'      => $t->listing_id,
                        'title'           => $titolo,
                        'quantity'        => $quantity,
                        'unit_price_ky'   => $unitPieno,
                        'ky_percentage'   => $kyPercentage,
                        'unit_ky_amount'  => $unitKy,
                        'unit_eur_amount' => $unitEur,
                        'line_ky_amount'  => $unitKy * $quantity,
                        'line_eur_amount' => $unitEur * $quantity,
                        'created_at'      => $t->created_at,
                        'updated_at'      => $adesso,
                    ]);

                    DB::table('transfers')->where('id', $t->id)->update(['order_id' => $orderId]);

                    if ($payment) {
                        DB::table('marketplace_order_payments')
                            ->where('id', $payment->id)
                            ->update(['order_id' => $orderId]);
                    }
                }
            });
    }
};
