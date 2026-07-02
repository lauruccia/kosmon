<?php

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
        Schema::table('payment_requests', function (Blueprint $table) {
            $table->dropIndex(['to_account_id', 'external_reference']);
            $table->dropColumn(['external_reference', 'return_url', 'cancel_url']);
        });
    }
};
