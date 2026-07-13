<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * mlm_point_ledger.valid_from/valid_until erano colonne DATE, confrontate
     * in User::mlmActivePoints() con whereDate() — quindi con granularita'
     * minima di un giorno intero. Per permettere l'override di test in minuti
     * (vedi migration precedente + MlmPointsService), serve precisione
     * datetime completa: qui si converte il TIPO di colonna e si confronta
     * con where() esatto invece di whereDate() (fatto in User::mlmActivePoints()).
     *
     * Compatibilita' con le righe GIA' esistenti: prima della conversione,
     * "valido fino al giorno X" (via whereDate) significava valido per
     * l'INTERO giorno X. Convertendo il tipo, un valore DATE 'X' diventa
     * 'X 00:00:00' — che con un confronto esatto risulterebbe scaduto fin
     * dalla mezzanotte di quel giorno, perdendo fino a 24 ore di validita'
     * rispetto al comportamento precedente. Per non alterare il calcolo
     * qualifiche di agenti reali gia' in produzione, le righe esistenti
     * vengono spostate a fine giornata (23:59:59) DOPO la conversione.
     */
    public function up(): void
    {
        Schema::table('mlm_point_ledger', function (Blueprint $table): void {
            $table->dateTime('valid_from')->change();
            $table->dateTime('valid_until')->change();
        });

        DB::table('mlm_point_ledger')->orderBy('id')->select('id', 'valid_until')
            ->chunkById(500, function ($rows): void {
                foreach ($rows as $row) {
                    $endOfDay = Carbon::parse($row->valid_until)->endOfDay();

                    DB::table('mlm_point_ledger')->where('id', $row->id)->update([
                        'valid_until' => $endOfDay,
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('mlm_point_ledger', function (Blueprint $table): void {
            $table->date('valid_from')->change();
            $table->date('valid_until')->change();
        });
    }
};
