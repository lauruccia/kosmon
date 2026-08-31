<?php

namespace App\Support;

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Esistenza e rimozione di un indice, senza sintassi legata a un solo motore.
 *
 * Nasce da B7: `2026_06_12_200000_add_performance_indexes_to_transfers` sparava
 * `PRAGMA index_list('transfers')` su QUALSIASI connessione. Su SQLite (dev e
 * test) funziona; su MySQL e' un errore di sintassi 1064, quindi
 * `php artisan migrate` si fermava li' e **non esisteva un modo di ricostruire
 * il database da zero** — ne' in produzione ne' in CI. E i `down()` chiamavano
 * `dropIndexIfExists()`, un metodo che in Laravel 12 non esiste (Blueprint ha
 * solo `dropIndex()`): quindi anche il rollback era rotto, su tutti i driver.
 *
 * Due regole, entrambe imparate sul campo su questo progetto:
 *
 * - **niente `information_schema`**: l'utente del database di produzione non ha
 *   il permesso di leggerlo (nota del 24/08 sui due server). `SHOW INDEX`
 *   funziona sempre, e uguale su MySQL e MariaDB — che qui sono due motori
 *   diversi su due server diversi (nota del 27/08).
 * - **una sola copia**: la stessa espressione duplicata in quattro migration e'
 *   esattamente il modo in cui il bug dello step-up e' sopravvissuto in due
 *   punti diversi (28/08). Chi deve aggiungere un indice chiama questa classe.
 */
final class SchemaIndex
{
    /**
     * L'indice $index esiste sulla tabella $table?
     *
     * Tabella inesistente = nessun indice: cosi' un `down()` puo' essere
     * eseguito anche dopo che la tabella e' stata rimossa da una migration
     * successiva, senza esplodere.
     */
    public static function exists(string $table, string $index): bool
    {
        if (! Schema::hasTable($table)) {
            return false;
        }

        if (DB::getDriverName() === 'sqlite') {
            return collect(DB::select('PRAGMA index_list(' . self::quote($table) . ')'))
                ->pluck('name')
                ->contains($index);
        }

        return collect(DB::select('SHOW INDEX FROM ' . self::quote($table)))
            ->pluck('Key_name')
            ->contains($index);
    }

    /**
     * Rimuove l'indice se c'e'. Il sostituto di `dropIndexIfExists()`, che in
     * Laravel 12 non esiste: il controllo lo facciamo noi prima di chiamare
     * `dropIndex()`, che invece esiste.
     */
    public static function dropIfExists(string $table, string $index): void
    {
        if (! self::exists($table, $index)) {
            return;
        }

        try {
            self::drop($table, $index);

            return;
        } catch (QueryException $e) {
            // 1553 = "needed in a foreign key constraint". MySQL pretende che
            // ogni chiave esterna sia coperta da un indice che COMINCI con la
            // sua colonna, e quando un indice composto e' l'unico a farlo se
            // lo tiene stretto: toglierlo e' rifiutato. Non e' un guasto, e'
            // il motore che difende un vincolo — ma bloccava il rollback su
            // tre migration diverse (B7, 31/08).
            if ((int) ($e->errorInfo[1] ?? 0) !== 1553) {
                throw $e;
            }
        }

        // Si rimette un indice semplice sulla prima colonna, che e' quello che
        // la chiave esterna avrebbe avuto per conto suo, e si riprova.
        $colonna = self::firstColumn($table, $index);

        if ($colonna === null) {
            throw new RuntimeException("Impossibile rimuovere l'indice {$index}: serve alla chiave esterna e non riesco a leggerne la prima colonna.");
        }

        // Il nome che MySQL da' da solo all'indice di una chiave esterna. Non
        // puo' coincidere con quello che stiamo rimuovendo — cosa che invece
        // succedeva usando il suffisso `_index`, quando l'indice da togliere e'
        // gia' un `<tabella>_<colonna>_index`: il sostituto risultava "gia'
        // presente", non veniva creato, e il secondo tentativo falliva uguale.
        $sostituto = "{$table}_{$colonna}_foreign";

        if (! self::exists($table, $sostituto)) {
            Schema::table($table, function (Blueprint $blueprint) use ($colonna, $sostituto): void {
                $blueprint->index($colonna, $sostituto);
            });
        }

        self::drop($table, $index);
    }

    private static function drop(string $table, string $index): void
    {
        Schema::table($table, function (Blueprint $blueprint) use ($index): void {
            $blueprint->dropIndex($index);
        });
    }

    /** Prima colonna di un indice (Seq_in_index = 1), o null se non si legge. */
    private static function firstColumn(string $table, string $index): ?string
    {
        foreach (DB::select('SHOW INDEX FROM ' . self::quote($table)) as $riga) {
            if ($riga->Key_name === $index && (int) $riga->Seq_in_index === 1) {
                return $riga->Column_name;
            }
        }

        return null;
    }

    /**
     * I nomi di tabella arrivano dalle nostre migration, mai da input esterno;
     * la ripulitura serve a non poter comporre SQL per sbaglio, visto che
     * PRAGMA e SHOW INDEX non accettano segnaposto in modo affidabile.
     */
    private static function quote(string $table): string
    {
        $pulito = preg_replace('/[^A-Za-z0-9_]/', '', $table);

        return DB::getDriverName() === 'sqlite' ? "'{$pulito}'" : "`{$pulito}`";
    }
}
