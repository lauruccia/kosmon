<?php

namespace App\Services;

use App\Models\Account;
use App\Models\MlmBonusPayout;
use App\Models\MlmCommission;
use App\Models\MlmWalletLedgerEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * "Cassetto kmoney" (2026-07-30, richiesta di Laura — vedi
 * MLM_PROPOSAL.md §10, che questa modifica supera parzialmente): compensi
 * diretti, indiretti, estesi e bonus vengono accreditati SUBITO in KY sul
 * conto dell'agente, non appena il job li calcola (mercoledi' per i bonus,
 * il 1° del mese per le provvigioni) — niente piu' attesa dell'approvazione
 * admin per diventare spendibili. A differenza del resto del saldo KY,
 * l'importo accreditato qui resta anche "prelevabile" (convertibile in €,
 * vedi MlmPayoutService) finche' l'agente non lo spende in negozio: vedi
 * withdrawableBalance().
 *
 * Chi paga il KY: il conto sistema (Cassa Circuito), stesso conto che emette
 * il KY per le KY Card e per il bonus segnalazione "attivita'" (vedi
 * ReferralBonusService::fundingAccountFor()) — e' moneta nuova creata dal
 * circuito per compensare il lavoro di rete, non uno spostamento tra utenti.
 *
 * REGOLA DIFENSIVA: creditFromCommission()/creditFromBonusPayout() non
 * lanciano MAI eccezioni. Sono chiamate SUBITO DOPO che
 * MlmCommissionEngine/MlmBonusService/MlmAwardService creano la riga
 * mlm_commissions/mlm_bonus_payouts, durante un job schedulato: un problema
 * qui (conto agente assente, conto sistema assente, ecc.) non deve MAI far
 * fallire il calcolo dei compensi già maturato — stesso principio di
 * ReferralBonusService::awardTier(). Le operazioni di prelievo
 * (reserveForPayout/releaseReservation), invece, sono azioni sincrone
 * scatenate dall'agente o dall'admin: LANCIANO eccezione se qualcosa non
 * torna, cosi' MlmPayoutService puo' mostrare un errore chiaro invece di
 * lasciare un prelievo a metà.
 */
class MlmWalletService
{
    /** Le 4 "voci" richieste da Laura: diretti, indiretti, estesi (oltre il 5° livello), bonus. */
    public const CATEGORY_DIRECT = 'diretta';
    public const CATEGORY_INDIRECT = 'indiretta';
    public const CATEGORY_EXTENDED = 'estesa';
    public const CATEGORY_BONUS = 'bonus';

    /**
     * Accredita subito in KY l'importo di una commissione appena creata
     * (chiamato da MlmCommissionEngine::calculateDirect()/calculateIndirect()).
     * "Estesa" = commissione indiretta oltre il 5° livello (§5.2 slide
     * "Compensi indiretti estesi"), stessa riga mlm_commissions ma level > 5.
     */
    public function creditFromCommission(MlmCommission $commission): void
    {
        $category = $commission->type === 'diretta'
            ? self::CATEGORY_DIRECT
            : (($commission->level !== null && $commission->level > 5) ? self::CATEGORY_EXTENDED : self::CATEGORY_INDIRECT);

        $this->credit(
            agentId: (int) $commission->agent_user_id,
            amountCents: (int) $commission->amount_eur_cents,
            category: $category,
            sourceType: 'commission',
            sourceId: $commission->id,
            idempotencyKey: "mlm_wallet_credit_commission_{$commission->id}",
            description: 'Compenso ' . $commission->type . ' — cassetto kmoney',
        );
    }

    /**
     * Accredita subito in KY l'importo di un bonus appena creato — bonus di
     * struttura (MlmBonusService), Bonus Diretti KNM o Extra Bonus
     * (MlmAwardService): tutti e tre confluiscono nell'unica categoria
     * "bonus" richiesta da Laura (4 contatori, non di più).
     */
    public function creditFromBonusPayout(MlmBonusPayout $bonus): void
    {
        $this->credit(
            agentId: (int) $bonus->beneficiary_user_id,
            amountCents: (int) $bonus->amount_eur_cents,
            category: self::CATEGORY_BONUS,
            sourceType: 'bonus_payout',
            sourceId: $bonus->id,
            idempotencyKey: "mlm_wallet_credit_bonus_{$bonus->id}",
            description: 'Bonus ' . $bonus->kind . ' — cassetto kmoney',
        );
    }

    /**
     * Quanto l'agente può ancora prelevare/convertire in € ORA: la somma
     * delle righe del cassetto (accrediti − riserve), limitata dal saldo KY
     * realmente disponibile sul conto — se l'agente ha già speso parte del
     * cassetto in negozio (o altrove), quella parte non è più prelevabile,
     * a differenza degli accrediti mai spesi.
     */
    public function withdrawableBalance(User $agent): int
    {
        $ledgerBalance = (int) MlmWalletLedgerEntry::where('agent_user_id', $agent->id)->sum('amount_cents');
        if ($ledgerBalance <= 0) {
            return 0;
        }

        $account = $this->personalAccountFor($agent->id);
        if (! $account) {
            return 0;
        }

        return max(0, min($ledgerBalance, (int) $account->available_balance));
    }

    /**
     * Totale storico ACCREDITATO (non il saldo residuo) per ciascuna delle 4
     * categorie — per i 4 contatori informativi richiesti da Laura
     * ("separati, visualizzabili"), mentre il saldo spendibile/prelevabile
     * resta unico (withdrawableBalance()). Le righe di riserva/rilascio
     * prelievo non hanno categoria e non contano qui.
     *
     * @return array{diretta:int, indiretta:int, estesa:int, bonus:int}
     */
    public function categoryBreakdown(User $agent): array
    {
        $sums = MlmWalletLedgerEntry::where('agent_user_id', $agent->id)
            ->whereNotNull('category')
            ->selectRaw('category, SUM(amount_cents) as total')
            ->groupBy('category')
            ->pluck('total', 'category');

        return [
            self::CATEGORY_DIRECT   => (int) ($sums[self::CATEGORY_DIRECT] ?? 0),
            self::CATEGORY_INDIRECT => (int) ($sums[self::CATEGORY_INDIRECT] ?? 0),
            self::CATEGORY_EXTENDED => (int) ($sums[self::CATEGORY_EXTENDED] ?? 0),
            self::CATEGORY_BONUS    => (int) ($sums[self::CATEGORY_BONUS] ?? 0),
        ];
    }

    /**
     * Riserva $amountCents del cassetto per la liquidazione in corso (vedi
     * MlmPayoutService::requestWithdrawal()/attachAgentPeriod()): sposta
     * davvero il KY dal conto dell'agente al conto sistema, cosi' non è più
     * spendibile in negozio né ri-prelevabile mentre la liquidazione è in
     * corso. Idempotente su $idempotencyKey (una sola volta per
     * payout/importo). Lancia eccezione se non può essere completata (mai
     * silenziosa: è un'azione sincrona scatenata dall'agente/admin).
     */
    public function reserveForPayout(User $agent, int $amountCents, string $idempotencyKey, string $description): void
    {
        $this->moveReservation($agent, $amountCents, fromAgent: true, sourceType: 'withdrawal_reserve', idempotencyKey: $idempotencyKey, description: $description);
    }

    /** Rilascia una riserva (liquidazione rifiutata): il KY torna disponibile e ri-prelevabile. */
    public function releaseReservation(User $agent, int $amountCents, string $idempotencyKey, string $description): void
    {
        $this->moveReservation($agent, $amountCents, fromAgent: false, sourceType: 'withdrawal_release', idempotencyKey: $idempotencyKey, description: $description);
    }

    private function moveReservation(User $agent, int $amountCents, bool $fromAgent, string $sourceType, string $idempotencyKey, string $description): void
    {
        if ($amountCents <= 0) {
            return;
        }

        if (MlmWalletLedgerEntry::where('idempotency_key', $idempotencyKey)->exists()) {
            return;
        }

        $agentAccount = $this->personalAccountFor($agent->id);
        $systemAccount = Account::systemAccount();

        if (! $agentAccount || ! $systemAccount) {
            throw new \RuntimeException('Impossibile elaborare il prelievo: conto agente o conto sistema non trovato.');
        }

        // Serve sempre un super admin come initiator (stesso vincolo di
        // ReferralBonusService::awardTierOrFail()): è l'unico ruolo che
        // TransferBookingService lascia bypassare sia l'autorizzazione
        // esplicita del titolare del conto addebitato sia i limiti di
        // fido/massimale — voluto qui perché l'agente ha già maturato
        // legittimamente questo importo, la riserva non deve mai bloccarsi
        // per un fido insufficiente.
        $superAdmin = User::where('is_super_admin', true)->where('is_active', true)->first();
        if (! $superAdmin) {
            throw new \RuntimeException('Impossibile elaborare il prelievo: nessun super admin disponibile per autorizzare il movimento.');
        }

        DB::transaction(function () use ($agent, $agentAccount, $systemAccount, $superAdmin, $amountCents, $fromAgent, $sourceType, $idempotencyKey, $description): void {
            $transfer = app(TransferBookingService::class)->book([
                'initiated_by'    => $superAdmin->id,
                'from_account_id' => $fromAgent ? $agentAccount->id : $systemAccount->id,
                'to_account_id'   => $fromAgent ? $systemAccount->id : $agentAccount->id,
                'amount'          => $amountCents,
                'description'     => $description,
                'kind'            => 'mlm_wallet_withdrawal',
                'idempotency_key' => $idempotencyKey . '_transfer',
            ]);

            MlmWalletLedgerEntry::create([
                'agent_user_id'   => $agent->id,
                'category'        => null,
                'amount_cents'    => $fromAgent ? -$amountCents : $amountCents,
                'source_type'     => $sourceType,
                'source_id'       => null,
                'transfer_id'     => $transfer->id,
                'idempotency_key' => $idempotencyKey,
            ]);
        });
    }

    private function credit(
        int $agentId,
        int $amountCents,
        string $category,
        string $sourceType,
        int $sourceId,
        string $idempotencyKey,
        string $description,
    ): void {
        if ($amountCents <= 0) {
            return;
        }

        if (MlmWalletLedgerEntry::where('idempotency_key', $idempotencyKey)->exists()) {
            return; // già accreditato
        }

        try {
            DB::transaction(function () use ($agentId, $amountCents, $category, $sourceType, $sourceId, $idempotencyKey, $description): void {
                $agentAccount = $this->personalAccountFor($agentId);
                $systemAccount = Account::systemAccount();

                if (! $agentAccount || ! $systemAccount) {
                    Log::warning("MlmWalletService: impossibile accreditare il cassetto per l'agente {$agentId} (conto agente o conto sistema mancante).");
                    return;
                }

                $superAdmin = User::where('is_super_admin', true)->where('is_active', true)->first();
                if (! $superAdmin) {
                    Log::warning('MlmWalletService: nessun super admin trovato per accreditare il cassetto kmoney.');
                    return;
                }

                $transfer = app(TransferBookingService::class)->book([
                    'initiated_by'    => $superAdmin->id,
                    'from_account_id' => $systemAccount->id,
                    'to_account_id'   => $agentAccount->id,
                    'amount'          => $amountCents,
                    'description'     => $description,
                    'kind'            => 'mlm_wallet_credit',
                    'idempotency_key' => $idempotencyKey . '_transfer',
                ]);

                MlmWalletLedgerEntry::create([
                    'agent_user_id'   => $agentId,
                    'category'        => $category,
                    'amount_cents'    => $amountCents,
                    'source_type'     => $sourceType,
                    'source_id'       => $sourceId,
                    'transfer_id'     => $transfer->id,
                    'idempotency_key' => $idempotencyKey,
                ]);
            });
        } catch (\Throwable $e) {
            Log::warning("MlmWalletService: accredito cassetto fallito per l'agente {$agentId} ({$sourceType} #{$sourceId}): " . $e->getMessage());
        }
    }

    private function personalAccountFor(int $userId): ?Account
    {
        return Account::where('owner_user_id', $userId)
            ->whereNull('parent_account_id')
            ->where('status', 'active')
            ->first();
    }
}
