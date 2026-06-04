<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Aggiunge related_transfer_id a transfers per collegare i transfer di commissione
     * (portal_fee) al movimento originale che li ha generati.
     */
    public function up(): void
    {
        Schema::table('transfers', function (Blueprint $table) {
            $table->foreignId('related_transfer_id')
                ->nullable()
                ->after('reversed_transfer_id')
                ->constrained('transfers')
                ->nullOnDelete();

            $table->index(['related_transfer_id']);
        });
    }

    public function down(): void
    {
        Schema::table('transfers', function (Blueprint $table) {
            $table->dropIndex(['related_transfer_id']);
            $table->dropConstrainedForeignId('related_transfer_id');
        });
    }
};
