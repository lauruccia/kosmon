<?php

use App\Models\Transfer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-08-14, richiesta di Laura: gli storni dei bonus MLM annullati per errore
 * (e gli accrediti originali che annullano) non devono comparire nelle liste
 * movimenti. Marca retroattivamente le righe gia' presenti con
 * Transfer::MLM_BONUS_REVERSAL_ACTION; da qui in avanti ci pensa
 * MlmWalletService::reverseBonusPayout() al momento dello storno.
 *
 * NON cancella e NON sposta nulla: il circuito resta chiuso, i saldi identici.
 * Cambia solo la visibilita' nelle liste (Transfer::excludeTechnicalCorrections()).
 *
 * Equivalente SQL per la produzione:
 *   migrazione_prod_2026-08-14_nascondi_storni_bonus.sql
 */
return new class extends Migration
{
    /** Prefisso dell'idempotency_key scritta da MlmWalletService::reverseBonusPayout(). */
    private const REVERSAL_KEY_PREFIX = 'mlm_wallet_reverse_bonus_';

    public function up(): void
    {
        if (! Schema::hasTable('mlm_wallet_ledger_entries') || ! Schema::hasColumn('transfers', 'admin_action')) {
            return;
        }

        $reversals = DB::table('mlm_wallet_ledger_entries')
            ->where('source_type', 'bonus_payout_reversal')
            ->get(['transfer_id', 'idempotency_key']);

        if ($reversals->isEmpty()) {
            return;
        }

        $transferIds = $reversals->pluck('transfer_id')->filter()->all();

        // Dall'idempotency_key ("mlm_wallet_reverse_bonus_{id}") si risale al bonus
        // stornato e quindi all'accredito originale, che va nascosto insieme allo
        // storno: da soli, i due movimenti raccontano il contrario del vero.
        $bonusIds = $reversals
            ->map(function ($row): ?int {
                $key = (string) $row->idempotency_key;

                return str_starts_with($key, self::REVERSAL_KEY_PREFIX)
                    ? (int) substr($key, strlen(self::REVERSAL_KEY_PREFIX))
                    : null;
            })
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($bonusIds !== []) {
            $transferIds = array_merge($transferIds, DB::table('mlm_wallet_ledger_entries')
                ->where('source_type', 'bonus_payout')
                ->whereIn('source_id', $bonusIds)
                ->pluck('transfer_id')
                ->filter()
                ->all());
        }

        $transferIds = array_values(array_unique($transferIds));

        if ($transferIds === []) {
            return;
        }

        DB::table('transfers')
            ->whereIn('id', $transferIds)
            // whereNull: non sovrascrive mai un marker esistente ('refund', apertura
            // ledger...), che porta un'informazione diversa e ancora utile.
            ->whereNull('admin_action')
            ->update(['admin_action' => Transfer::MLM_BONUS_REVERSAL_ACTION]);
    }

    public function down(): void
    {
        if (! Schema::hasColumn('transfers', 'admin_action')) {
            return;
        }

        DB::table('transfers')
            ->where('admin_action', Transfer::MLM_BONUS_REVERSAL_ACTION)
            ->update(['admin_action' => null]);
    }
};
