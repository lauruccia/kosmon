<?php

use App\Support\SchemaIndex;
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
        // L'indice puo' essere gia' sparito (lo rimuove anche il down() di
        // 2026_06_12_200000): `dropIndex` secco darebbe 1091 e fermerebbe il
        // rollback. E la chiave esterna va tolta PRIMA dell'indice che la
        // copre, altrimenti MySQL rifiuta con 1553 (B7, 31/08).
        Schema::table('transfers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('related_transfer_id');
        });

        SchemaIndex::dropIfExists('transfers', 'transfers_related_transfer_id_index');
    }
};
