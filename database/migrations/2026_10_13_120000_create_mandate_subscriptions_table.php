<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gli abbonamenti che i portali del circuito (oggi Lamèss) addebitano sul
 * conto KMoney dell'utente col mandato di pagamento.
 *
 * Il portale resta il padrone dell'abbonamento — prodotti, calendario,
 * spedizione — e ne manda qui una COPIA a ogni cambiamento, perché l'utente
 * possa vedere in un posto solo tutto ciò che gli viene addebitato e, se vuole,
 * fermarlo. La riga non muove denaro: il denaro resta nei `transfers`.
 *
 * `blocked_at` è l'unica colonna che il portale NON può scrivere: appartiene
 * all'utente, e un nuovo invio dal portale non la tocca mai.
 *
 * Gli addebiti si legano all'abbonamento con `payment_mandate_charges.
 * mandate_subscription_id` (colonna in fondo, nullable: su una tabella viva è
 * istantaneo e gli addebiti vecchi restano validi senza legame).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mandate_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // L'applicazione (config/oauth.php) e l'identificativo che il
            // portale dà all'abbonamento: insieme sono la chiave naturale.
            $table->string('client_id', 100);
            $table->string('external_id', 64);

            $table->string('title');
            $table->text('items')->nullable();                 // JSON: righe leggibili, "1 × Box mensile"
            $table->string('seller_account_number', 32)->nullable();
            $table->unsignedInteger('amount')->nullable();     // centesimi di KY per ciclo, se il portale lo sa
            $table->unsignedTinyInteger('interval_months')->default(1);

            $table->string('status', 20)->default('active');   // active | paused | cancelled (lo dice il portale)
            $table->timestamp('next_charge_at')->nullable();
            $table->timestamp('last_charged_at')->nullable();
            $table->string('last_error')->nullable();

            $table->timestamp('blocked_at')->nullable();       // SOLO l'utente
            $table->timestamp('cancelled_at')->nullable();

            $table->timestamps();

            $table->unique(['client_id', 'external_id'], 'mandate_subscriptions_external_unique');
            $table->index(['user_id', 'status']);
        });

        Schema::table('payment_mandate_charges', function (Blueprint $table) {
            $table->foreignId('mandate_subscription_id')->nullable()->constrained('mandate_subscriptions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payment_mandate_charges', function (Blueprint $table) {
            $table->dropConstrainedForeignId('mandate_subscription_id');
        });

        Schema::dropIfExists('mandate_subscriptions');
    }
};
