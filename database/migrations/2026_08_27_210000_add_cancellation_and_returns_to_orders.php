<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Annullamenti e resi: le colonne che mancavano (giro 2 della fase B, 27/08/2026).
 *
 * La fase B aveva gia' portato `cancelled_at` e `cancel_reason`, ma nessuno li
 * scriveva: l'annullamento non esisteva come azione perche' muove denaro e
 * l'avevamo rimandato. Qui arriva il resto.
 *
 * `stock_restored_at` e' la colonna che tiene in piedi tutto il meccanismo.
 * Fino a ieri "le scorte sono gia' tornate in magazzino?" si deduceva dallo
 * stato `refunded`, e finche' c'era una sola strada per rimborsare bastava.
 * Adesso le strade sono tre - il rimborso dai movimenti, l'annullamento, il
 * reso accettato - e due di loro lasciano l'ordine in stati diversi. Senza un
 * segno esplicito, un ordine annullato e poi rimborsato a mano dai movimenti
 * si vedrebbe restituire i pezzi DUE VOLTE, e il magazzino direbbe una
 * quantita' che il negozio non ha.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Quando la merce e' tornata disponibile. NULL = non ancora.
            $table->timestamp('stock_restored_at')->nullable()->after('cancel_reason');

            // Chi ha annullato: il venditore o l'admin per conto suo. Il
            // registro AuditLog racconta la stessa cosa, ma qui si legge senza
            // andarlo a cercare - e la pagina dell'ordine deve dirlo.
            $table->unsignedBigInteger('cancelled_by_user_id')->nullable()->after('stock_restored_at');

            // Il movimento KY che ha restituito i soldi, se c'e' stato.
            $table->unsignedBigInteger('refund_transfer_id')->nullable()->after('cancelled_by_user_id');
        });

        Schema::create('order_return_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();

            // Chi ha chiesto il reso: il compratore. Nullable perche' un utente
            // cancellato non deve portarsi via la storia della pratica.
            $table->unsignedBigInteger('requested_by_user_id')->nullable();

            $table->string('status', 20)->default('pending');
            $table->text('reason');

            // La risposta del venditore (o dell'admin per conto suo).
            $table->unsignedBigInteger('decided_by_user_id')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->boolean('decided_by_admin')->default(false);
            $table->text('decision_note')->nullable();

            $table->timestamps();

            // La domanda che si fa la pagina dell'ordine ad ogni apertura:
            // "c'e' una pratica aperta su questo ordine?".
            $table->index(['order_id', 'status'], 'order_return_requests_order_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_return_requests');

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['stock_restored_at', 'cancelled_by_user_id', 'refund_transfer_id']);
        });
    }
};
