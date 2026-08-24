<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Aggiunge un identificativo pubblico agli utenti.
 *
 * `companies` e `accounts` hanno già la loro colonna `uuid`; `users` no, aveva
 * solo l'id numerico progressivo. Serve adesso perché con "Accedi con KMoney"
 * l'identità dell'utente esce dal perimetro dell'applicazione e finisce dentro
 * un token letto da un'altra app: mettere lì l'id numerico significherebbe
 * dichiarare quanti utenti ha il circuito e legare per sempre le due
 * applicazioni alla chiave primaria di questa tabella.
 *
 * La colonna è additiva: nessun codice esistente la usa, nessuna query cambia.
 */
return new class extends Migration
{
    public function up(): void
    {
        // La colonna si aggiunge IN FONDO, senza `after()`: su MySQL/MariaDB
        // infilarla in mezzo ricostruirebbe tutta la tabella `users` a sito
        // acceso, mentre in fondo è istantaneo. Per Laravel l'ordine delle
        // colonne non conta (stessa regola già seguita nella fase 0b).
        Schema::table('users', function (Blueprint $table) {
            $table->uuid('uuid')->nullable();
        });

        // Backfill dello storico, a blocchi: la tabella può essere grande e
        // questa migrazione gira anche in produzione, a sito acceso.
        DB::table('users')->select('id')->whereNull('uuid')->orderBy('id')
            ->chunk(500, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('users')->where('id', $row->id)->update([
                        'uuid' => (string) Str::uuid(),
                    ]);
                }
            });

        // L'indice unico si aggiunge DOPO il backfill: prima le righe esistenti
        // avrebbero tutte `null` e su alcuni motori il vincolo si arrabbia.
        Schema::table('users', function (Blueprint $table) {
            $table->unique('uuid');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['uuid']);
            $table->dropColumn('uuid');
        });
    }
};
