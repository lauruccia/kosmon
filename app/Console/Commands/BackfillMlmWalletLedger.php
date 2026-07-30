<?php

namespace App\Console\Commands;

use App\Models\MlmBonusPayout;
use App\Models\MlmCommission;
use App\Models\MlmPayout;
use App\Services\MlmWalletService;
use Illuminate\Console\Command;

/**
 * Backfill una tantum (2026-07-30, cassetto kmoney): accredita nel cassetto
 * KY le commissioni/bonus maturati PRIMA che il cassetto esistesse.
 *
 * Senza questo comando il cassetto resta a zero per tutto cio' che era gia'
 * in mlm_commissions/mlm_bonus_payouts al momento del deploy: MlmWalletService
 * viene chiamato SOLO dentro MlmCommissionEngine/MlmBonusService/
 * MlmAwardService, cioe' al momento in cui una riga viene creata — le righe
 * gia' esistenti non passano mai piu' di li'. Segnalato da Laura il
 * 2026-07-30 (screenshot: "Da pagare" mostrava 8.900 € ma "Il tuo cassetto
 * kmoney" restava a 0 €).
 *
 * Righe 'paid' (gia' liquidate in € col vecchio flusso, bonifico gia'
 * eseguito) NON vengono accreditate: quel denaro e' gia' uscito dal
 * circuito, accreditarlo ora in KY creerebbe KY dal nulla per un compenso
 * gia' saldato.
 *
 * Righe 'pending'/'approved' gia' agganciate a una liquidazione APERTA
 * (mlm_payout_id valorizzato, MlmPayout in stato pending/approved) vengono
 * accreditate E SUBITO RI-RISERVATE per quella stessa liquidazione (stessa
 * chiave di idempotenza che userebbe MlmPayoutService), cosi' non
 * diventano spendibili/prelevabili una seconda volta mentre la
 * liquidazione e' gia' in corso di approvazione/pagamento.
 *
 * Idempotente: si puo' rilanciare piu' volte senza doppi accrediti —
 * creditFromCommission()/creditFromBonusPayout()/reserveForPayout()
 * controllano gia' da soli l'idempotency_key prima di muovere KY.
 */
class BackfillMlmWalletLedger extends Command
{
    protected $signature = 'mlm:backfill-wallet-ledger';

    protected $description = 'Accredita nel cassetto kmoney le commissioni/bonus maturati prima che il cassetto esistesse (una tantum, idempotente)';

    public function handle(MlmWalletService $wallet): int
    {
        $examinedCommissions = 0;
        $examinedBonuses = 0;
        $reservedPayouts = 0;

        MlmCommission::whereIn('status', ['pending', 'approved'])
            ->orderBy('id')
            ->chunkById(200, function ($commissions) use ($wallet, &$examinedCommissions): void {
                foreach ($commissions as $commission) {
                    $wallet->creditFromCommission($commission);
                    $examinedCommissions++;
                }
            });

        MlmBonusPayout::whereIn('status', ['pending', 'approved'])
            ->orderBy('id')
            ->chunkById(200, function ($bonuses) use ($wallet, &$examinedBonuses): void {
                foreach ($bonuses as $bonus) {
                    $wallet->creditFromBonusPayout($bonus);
                    $examinedBonuses++;
                }
            });

        // Liquidazioni gia' aperte (pending/approved, non ancora pagate): la
        // quota gia' agganciata va subito ri-riservata, altrimenti resta
        // spendibile/prelevabile una seconda volta mentre e' gia' in corso.
        MlmPayout::whereIn('status', ['pending', 'approved'])
            ->orderBy('id')
            ->chunkById(200, function ($payouts) use ($wallet, &$reservedPayouts): void {
                foreach ($payouts as $payout) {
                    if ($payout->total_eur_cents <= 0 || ! $payout->agent) {
                        continue;
                    }

                    $wallet->reserveForPayout(
                        $payout->agent,
                        $payout->total_eur_cents,
                        "mlm_wallet_reserve_payout_{$payout->id}_{$payout->total_eur_cents}",
                        "Riserva cassetto kmoney per liquidazione #{$payout->id}",
                    );
                    $reservedPayouts++;
                }
            });

        $this->info("Commissioni esaminate: {$examinedCommissions}. Bonus esaminati: {$examinedBonuses}. Liquidazioni aperte ri-riservate: {$reservedPayouts}.");
        $this->info("Nota: e' idempotente — le righe gia' accreditate/riservate da una chiamata precedente vengono ricontrollate ma non ri-accreditate.");

        return self::SUCCESS;
    }
}
