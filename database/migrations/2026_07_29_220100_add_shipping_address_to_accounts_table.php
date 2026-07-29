<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indirizzo di spedizione salvato una volta sul conto (Account), non
 * richiesto ad ogni acquisto (scelta di Laura, 2026-07-29 sera) — sia per
 * conti privati sia per conti aziendali, modificabile dalle rispettive
 * pagine profilo (portal/personal-profile-edit.blade.php e
 * portal/profile-edit.blade.php). Vive su Account (non su User/Company)
 * perché è l'entità che compra davvero nello shop, la stessa risolta da
 * ListingController::resolveAccount() — un'azienda con sottoconti potrebbe
 * in teoria avere indirizzi diversi per sottoconto, anche se nella UI
 * attuale si edita solo dal conto principale.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->string('shipping_recipient_name', 150)->nullable()->after('account_name');
            $table->string('shipping_address', 255)->nullable()->after('shipping_recipient_name');
            $table->string('shipping_city', 100)->nullable()->after('shipping_address');
            $table->string('shipping_postal_code', 12)->nullable()->after('shipping_city');
            $table->string('shipping_province', 60)->nullable()->after('shipping_postal_code');
            $table->string('shipping_phone', 30)->nullable()->after('shipping_province');
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn([
                'shipping_recipient_name',
                'shipping_address',
                'shipping_city',
                'shipping_postal_code',
                'shipping_province',
                'shipping_phone',
            ]);
        });
    }
};
