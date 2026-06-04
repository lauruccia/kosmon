<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nfc_card_auth_sessions', function (Blueprint $table) {
            // Aggiunge riferimento diretto all'account del merchant (supporta KYP senza company)
            $table->unsignedBigInteger('merchant_account_id')->nullable()->after('merchant_company_id');
            $table->foreign('merchant_account_id')->references('id')->on('accounts')->cascadeOnDelete();

            // Rende nullable merchant_company_id per compatibilità con account personali
            $table->unsignedBigInteger('merchant_company_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('nfc_card_auth_sessions', function (Blueprint $table) {
            $table->dropForeign(['merchant_account_id']);
            $table->dropColumn('merchant_account_id');
            $table->unsignedBigInteger('merchant_company_id')->nullable(false)->change();
        });
    }
};
