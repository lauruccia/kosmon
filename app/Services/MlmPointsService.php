<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\MlmCommissionBaseLedgerEntry;
use App\Models\MlmPointLedgerEntry;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Assegna i "punti cliente" (PC) agli agenti in base alle azioni dei loro
 * clienti diretti (registrazione, deposito). Vedi MLM_PROPOSAL.md §4.1.
 *
 * Ogni assegnazione crea UNA riga nel ledger con una finestra di validita'
 * (valid_from..valid_until): per la durata della finestra, quella riga
 * contribuisce "points" ai punti attivi dell'agente (vedi User::mlmActivePoints()).
 * Non si tratta di un importo che cresce nel tempo: e' il tasso mensile
 * attribuito a quel deposito, attivo per N mesi consecutivi (stesso principio
 * di smoothing usato per la base commissioni).
 */
class MlmPointsService
{
    /**
     * Scaglioni deposito: [soglia_minima_eur_cents, punti_al_mese, durata_mesi].
     * Verificati in ordine decrescente (il primo che soddisfa la soglia vince).
     * Fonte: mlm_piano.xlsx foglio "PC PUNTI CLIENTI".
     */
    private const DEPOSIT_TIERS = [
        [360_000, 2, 36], // deposito >= 3.600 EUR
        [240_000, 2, 24], // deposito >= 2.400 EUR
        [120_000, 2, 12], // deposito >= 1.200 EUR
        [12_000, 1, 1],   // deposito >= 120 EUR (soglia minima "cliente attivo")
    ];

    /**
     * Assegna 1 punto (valido 1 mese) all'agente risolto quando un suo
     * cliente diretto completa la registrazione. Non fa nulla se l'utente
     * non e' un cliente MLM o non ha un agente risolto (invito "orfano").
     */
    public function awardRegistrationPoints(User $client): void
    {
        if (! $client->isMlmClient() || ! $client->mlm_client_agent_id) {
            return;
        }

        $this->createLedgerEntry(
            client: $client,
            sourceType: 'registration',
            sourceTransferId: null,
            points: 1,
            validFrom: now(),
            validUntil: now()->addMonth(),
        );
    }

    /**
     * Assegna i punti deposito in base alla fascia dell'importo (EUR centesimi).
     * Non fa nulla se l'utente non e' un cliente MLM, non ha un agente risolto,
     * o il deposito e' sotto la soglia minima (120 EUR).
     */
    public function awardDepositPoints(User $client, int $depositEurCents, ?int $sourceTransferId = null): void
    {
        if (! $client->isMlmClient() || ! $client->mlm_client_agent_id) {
            return;
        }

        $tier = $this->resolveTier($depositEurCents);
        if (! $tier) {
            return;
        }

        [$points, $durationMonths] = $tier;

        $this->createLedgerEntry(
            client: $client,
            sourceType: 'deposit',
            sourceTransferId: $sourceTransferId,
            points: $points,
            validFrom: now(),
            validUntil: now()->addMonths($durationMonths),
        );

        $this->createCommissionBaseEntry($client, $depositEurCents, $durationMonths, $sourceTransferId);
    }

    /**
     * Scrive la riga parallela nel ledger "importo mensile" commissionabile
     * (stessa fascia/durata dei punti, vedi resolveTier()). L'importo mensile
     * e' semplicemente il deposito diviso per la durata dello scaglione:
     * per gli scaglioni da 1.200/2.400/3.600 EUR questo da' sempre 100 EUR/mese,
     * coerente con gli esempi del glossario KNM originale.
     */
    private function createCommissionBaseEntry(User $client, int $depositEurCents, int $durationMonths, ?int $sourceTransferId): void
    {
        $agent = $client->mlmClientAgent;
        if (! $agent) {
            return;
        }

        $monthlyAmount = (int) round($depositEurCents / $durationMonths);

        MlmCommissionBaseLedgerEntry::create([
            'client_user_id' => $client->id,
            'direct_agent_id' => $agent->id,
            'source_transfer_id' => $sourceTransferId,
            'monthly_amount_eur_cents' => $monthlyAmount,
            'valid_from' => now()->toDateString(),
            'valid_until' => now()->addMonths($durationMonths)->toDateString(),
        ]);
    }

    /** @return array{0:int,1:int}|null [punti, mesi] oppure null se sotto soglia. */
    private function resolveTier(int $depositEurCents): ?array
    {
        foreach (self::DEPOSIT_TIERS as [$minEurCents, $points, $months]) {
            if ($depositEurCents >= $minEurCents) {
                return [$points, $months];
            }
        }

        return null;
    }

    private function createLedgerEntry(
        User $client,
        string $sourceType,
        ?int $sourceTransferId,
        int $points,
        Carbon $validFrom,
        Carbon $validUntil,
    ): void {
        $agent = $client->mlmClientAgent;
        if (! $agent) {
            return;
        }

        $entry = MlmPointLedgerEntry::create([
            'agent_user_id'       => $agent->id,
            'client_user_id'      => $client->id,
            'source_type'         => $sourceType,
            'source_transfer_id'  => $sourceTransferId,
            'points'              => $points,
            'valid_from'          => $validFrom->toDateString(),
            'valid_until'         => $validUntil->toDateString(),
        ]);

        AuditLog::create([
            'actor_user_id'   => $client->id,
            'event'           => 'mlm.points_awarded',
            'auditable_type'  => User::class,
            'auditable_id'    => $agent->id,
            'context'         => [
                'ledger_entry_id' => $entry->id,
                'source_type'     => $sourceType,
                'points'          => $points,
                'valid_from'      => $validFrom->toDateString(),
                'valid_until'     => $validUntil->toDateString(),
            ],
        ]);
    }
}
