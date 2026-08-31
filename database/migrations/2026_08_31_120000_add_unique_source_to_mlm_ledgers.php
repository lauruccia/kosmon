<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Una sorgente = una riga. Indice UNIQUE sui due ledger MLM alimentati da una
 * ricarica: e' l'unico arbitro possibile quando due richieste concorrenti
 * leggono "non c'e'" nello stesso istante e scrivono entrambe.
 *
 * Il caso reale: dal 28/08 il webhook Stripe funziona (prima rispondeva 419
 * per il CSRF), quindi l'accredito di una ricarica puo' partire da DUE strade
 * simultanee — webhook e pagina di successo. I KY erano gia' protetti dalla
 * idempotency_key del transfer; i punti e la base commissionabile no, e la
 * seconda riga di mlm_commission_base_ledger viene pagata in euro veri dal
 * run di MlmCommissionEngine il 1° del mese.
 *
 * source_transfer_id NULL resta libero di ripetersi (registrazione,
 * simulatore): non e' una sorgente identificabile, e in MySQL/MariaDB come in
 * SQLite un indice UNIQUE ammette piu' NULL.
 *
 * NOTA PRODUZIONE: qui le migration non vengono eseguite (vedi la nota del
 * 14/08 sulle migrazioni disallineate). L'equivalente SQL, con il controllo
 * dei duplicati DA ESEGUIRE PRIMA, e' in database/sql/2026_08_31_mlm_unique_source.sql.
 */
return new class extends Migration
{
    private const POINT_INDEX = 'mlm_point_ledger_source_unique';
    private const BASE_INDEX  = 'mlm_commission_base_ledger_source_unique';

    public function up(): void
    {
        if (! $this->hasIndex('mlm_point_ledger', self::POINT_INDEX)) {
            Schema::table('mlm_point_ledger', function (Blueprint $table): void {
                $table->unique(['source_type', 'source_transfer_id'], self::POINT_INDEX);
            });
        }

        if (! $this->hasIndex('mlm_commission_base_ledger', self::BASE_INDEX)) {
            Schema::table('mlm_commission_base_ledger', function (Blueprint $table): void {
                $table->unique(['source_transfer_id'], self::BASE_INDEX);
            });
        }
    }

    public function down(): void
    {
        if ($this->hasIndex('mlm_point_ledger', self::POINT_INDEX)) {
            Schema::table('mlm_point_ledger', function (Blueprint $table): void {
                $table->dropUnique(self::POINT_INDEX);
            });
        }

        if ($this->hasIndex('mlm_commission_base_ledger', self::BASE_INDEX)) {
            Schema::table('mlm_commission_base_ledger', function (Blueprint $table): void {
                $table->dropUnique(self::BASE_INDEX);
            });
        }
    }

    /**
     * Esistenza di un indice, per driver. Niente PRAGMA sparato su qualsiasi
     * connessione (e' il difetto di
     * 2026_06_12_200000_add_performance_indexes_to_transfers, che su MySQL fa
     * fallire l'intera migrate) e niente information_schema: l'utente di
     * produzione non ha il permesso di leggerlo (nota del 24/08 sui due
     * server). SHOW INDEX funziona sia su MySQL sia su MariaDB.
     */
    private function hasIndex(string $table, string $index): bool
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            return collect(DB::select("PRAGMA index_list('{$table}')"))
                ->pluck('name')
                ->contains($index);
        }

        return collect(DB::select("SHOW INDEX FROM `{$table}`"))
            ->pluck('Key_name')
            ->contains($index);
    }
};
