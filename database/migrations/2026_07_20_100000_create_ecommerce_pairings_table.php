<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Collegamento semplificato del plugin e-commerce (WooCommerce) al conto.
 *
 * Il negoziante inserisce SOLO il numero di conto (KYB...) nel plugin: il
 * plugin invia una richiesta di collegamento (pairing) a KMoney; l'admin del
 * circuito la approva da /admin/companies/{id}; alla prima verifica successiva
 * il plugin riceve automaticamente token API e secret del webhook, una sola
 * volta, autenticandosi con il claim_secret generato dal plugin stesso.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ecommerce_pairings', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('account_number', 16);          // KYB... inserito nel plugin
            $table->string('site_url', 500);               // home del negozio
            $table->string('webhook_url', 500);            // endpoint webhook del plugin
            $table->string('platform', 30)->default('woocommerce');
            $table->string('claim_secret_hash', 64);       // sha256 del segreto del plugin
            $table->string('status', 20)->default('pending'); // pending|approved|rejected
            $table->foreignId('api_token_id')->nullable()->constrained('api_tokens')->nullOnDelete();
            $table->foreignId('webhook_id')->nullable()->constrained('webhooks')->nullOnDelete();
            $table->text('credentials')->nullable();       // token+secret cifrati, azzerati al ritiro
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->string('created_ip', 45)->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ecommerce_pairings');
    }
};
