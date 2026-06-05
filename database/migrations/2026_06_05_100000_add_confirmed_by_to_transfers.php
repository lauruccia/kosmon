<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Aggiunge confirmed_by alla tabella transfers.
 *
 * Prima di questo fix, confirmRequest() sovrascriveva initiated_by con l'ID
 * del confermante, perdendo l'informazione di chi aveva creato la richiesta.
 * Ora initiated_by conserva chi ha CREATO il pending transfer,
 * mentre confirmed_by registra chi lo ha CONFERMATO.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transfers', function (Blueprint $table) {
            $table->foreignId('confirmed_by')
                ->nullable()
                ->after('initiated_by')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('transfers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('confirmed_by');
        });
    }
};
