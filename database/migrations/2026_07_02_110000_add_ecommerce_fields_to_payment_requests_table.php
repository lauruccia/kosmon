<?php

use App\Support\SchemaIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_requests', function (Blueprint $table) {
            // Riferimento libero lato integrazione (es. numero ordine WooCommerce/Magento).
            // Usato per correlare la PaymentRequest all'ordine sul sistema del negoziante.
            $table->string('external_reference', 191)->nullable()->after('description');
            $table->index(['to_account_id', 'external_reference']);

            // URL di ritorno per i flussi "hosted checkout" (creati via API e-commerce).
            $table->string('return_url', 500)->nullable()->after('external_reference');
            $table->string('cancel_url', 500)->nullable()->after('return_url');
        });
    }

    public function down(): void
    {
        // L'indice comincia per to_account_id, che e' una chiave esterna:
        // MySQL lo tiene stretto (1553). Ci pensa SchemaIndex, che rimette un
        // indice semplice sulla prima colonna e riprova (B7, 31/08).
        SchemaIndex::dropIfExists('payment_requests', 'payment_requests_to_account_id_external_reference_index');

        Schema::table('payment_requests', function (Blueprint $table) {
            $table->dropColumn(['external_reference', 'return_url', 'cancel_url']);
        });
    }
};
