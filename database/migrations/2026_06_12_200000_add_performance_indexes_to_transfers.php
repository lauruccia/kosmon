<?php

use App\Support\SchemaIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indici di lettura su `transfers`.
 *
 * B7 (31/08): questa migration eseguiva `PRAGMA index_list('transfers')` su
 * QUALSIASI connessione. Su SQLite passava, su MySQL e' un errore di sintassi
 * 1064 — quindi `php artisan migrate` si fermava qui e non esisteva un modo di
 * ricostruire il database da zero, ne' in produzione ne' in CI (il job
 * `test-mysql` di ci.yml era rosso proprio su questo passo dal 12/06). Il
 * `down()` chiamava `dropIndexIfExists()`, che in Laravel 12 non esiste.
 * Entrambe le cose ora vivono in App\Support\SchemaIndex.
 */
return new class extends Migration
{
    private const INDEXES = [
        'transfers_from_status_booked_at_index' => ['from_account_id', 'status', 'booked_at'],
        'transfers_reversed_transfer_id_index'  => ['reversed_transfer_id'],
        'transfers_related_transfer_id_index'   => ['related_transfer_id'],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $nome => $colonne) {
            if (SchemaIndex::exists('transfers', $nome)) {
                continue;
            }

            Schema::table('transfers', function (Blueprint $table) use ($nome, $colonne): void {
                $table->index($colonne, $nome);
            });
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::INDEXES) as $nome) {
            SchemaIndex::dropIfExists('transfers', $nome);
        }
    }
};
