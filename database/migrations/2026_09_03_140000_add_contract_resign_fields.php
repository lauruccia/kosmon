<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * RIFIRMA DEL CONTRATTO DOPO UNA REVISIONE (03/09/2026, decisione di Laura).
 *
 * Il problema che risolve: modificando il testo, `contract_version` saliva ma
 * nessuno confrontava mai la versione firmata con quella attuale. Chi aveva
 * gia' firmato passava indisturbato (`if ($user->contract_signed_at) return
 * $next()`) e in Sicurezza continuava a vedere il proprio snapshot vecchio.
 * Una correzione o una revisione delle condizioni non raggiungeva nessuno.
 *
 * Due colonne:
 *   - users.contract_signed_version          quale versione ha firmato
 *   - system_settings.contract_resign_from_version   da quale versione in su
 *     serve una firma nuova (0 = nessuna rifirma richiesta)
 *
 * La regola: rifirma dovuta se contract_signed_version < contract_resign_from_version.
 * La spunta in /admin/contratto decide, revisione per revisione, se alzare la
 * soglia: un refuso corretto non trascina nessuno, una revisione delle
 * condizioni si'.
 *
 * IL BACKFILL E' LA PARTE DELICATA. Senza, ogni firma esistente vale 0 e la
 * prima spunta rimanderebbe alla firma TUTTE le aziende, comprese quelle che
 * hanno appena firmato la versione corrente. Qui la versione si legge da
 * `contract_signatures`, dove c'e' per ogni firma; per le firme antecedenti
 * agli snapshot (nessuna riga in quella tabella) si ripiega sulla versione 1,
 * che e' la piu' prudente: quelle firme sono le piu' vecchie di tutte.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->unsignedInteger('contract_signed_version')->nullable()->after('contract_signed_at');
        });

        Schema::table('system_settings', function (Blueprint $table): void {
            $table->unsignedInteger('contract_resign_from_version')->default(0)->after('contract_version');
            // Ultima CORREZIONE formale del testo della versione in vigore
            // (refuso, punteggiatura, formattazione): non alza la versione, e
            // chi ha firmato quella stessa versione deve vedere il testo
            // corretto, non piu' il refuso.
            $table->timestamp('contract_text_corrected_at')->nullable()->after('contract_resign_from_version');
        });

        // Backfill: la versione di ogni firma, dalla firma piu' recente.
        $rows = DB::table('contract_signatures')
            ->select('user_id', DB::raw('MAX(contract_version) as v'))
            ->groupBy('user_id')
            ->get();

        foreach ($rows as $row) {
            DB::table('users')->where('id', $row->user_id)
                ->update(['contract_signed_version' => (int) $row->v]);
        }

        // Firme piu' vecchie degli snapshot: nessuna riga in
        // contract_signatures, si assume la v1.
        DB::table('users')
            ->whereNotNull('contract_signed_at')
            ->whereNull('contract_signed_version')
            ->update(['contract_signed_version' => 1]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('contract_signed_version');
        });

        Schema::table('system_settings', function (Blueprint $table): void {
            $table->dropColumn(['contract_resign_from_version', 'contract_text_corrected_at']);
        });
    }
};
