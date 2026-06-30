<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Estende le Card NFC a tutti i partecipanti del circuito:
 * non più solo aziende (company_id) ma anche conti privati (owner_user_id).
 *
 * Regola: esattamente uno tra company_id e owner_user_id è valorizzato.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nfc_cards', function (Blueprint $table) {
            $table->foreignId('owner_user_id')
                ->nullable()
                ->after('company_id')
                ->constrained('users')
                ->nullOnDelete();
        });

        // Rende company_id nullable (i privati non hanno azienda).
        Schema::table('nfc_cards', function (Blueprint $table) {
            $table->unsignedBigInteger('company_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('nfc_cards', function (Blueprint $table) {
            $table->dropConstrainedForeignId('owner_user_id');
        });

        Schema::table('nfc_cards', function (Blueprint $table) {
            $table->unsignedBigInteger('company_id')->nullable(false)->change();
        });
    }
};
