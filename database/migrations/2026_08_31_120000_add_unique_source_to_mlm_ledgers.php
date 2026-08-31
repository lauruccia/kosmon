<?php

use App\Support\SchemaIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
        if (! SchemaIndex::exists('mlm_point_ledger', self::POINT_INDEX)) {
            Schema::table('mlm_point_ledger', function (Blueprint $table): void {
                $table->unique(['source_type', 'source_transfer_id'], self::POINT_INDEX);
            });
        }

        if (! SchemaIndex::exists('mlm_commission_base_ledger', self::BASE_INDEX)) {
            Schema::table('mlm_commission_base_ledger', function (Blueprint $table): void {
                $table->unique(['source_transfer_id'], self::BASE_INDEX);
            });
        }
    }

    public function down(): void
    {
        SchemaIndex::dropIfExists('mlm_point_ledger', self::POINT_INDEX);
        SchemaIndex::dropIfExists('mlm_commission_base_ledger', self::BASE_INDEX);
    }
};
