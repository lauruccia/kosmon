<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\MlmCommissionBaseLedgerEntry;
use App\Models\MlmPointLedgerEntry;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Assegna i "punti cliente" (PC) agli agenti in base alle azioni dei loro
 * clienti diretti (registrazione, deposito). Vedi MLM_PROPOSAL.md §4.1.
 *
 * REGOLA "/12" DELLA SLIDE (2026-07-20, decisione di Laura "sempre /12 come
 * slide", che sostituisce gli scaglioni del foglio mlm_piano.xlsx):
 *
 *  - L'importo del deposito viene SEMPRE diviso per 12 e imputato per 12
 *    mesi dal mese di fatturazione (slide "L'Importo Personale Mensile").
 *  - I punti sono FRAZIONARI: 1 punto ogni 50 EUR di importo personale
 *    mensile (slide "Importo Personale Mensile", righe "/50": 2.400 EUR/mese
 *    = 48 punti; 10 EUR/mese = 0,2 punti). Coerente con i vecchi scaglioni
 *    sui tagli standard: 1.200 EUR -> 100 EUR/mese -> 2 punti.
 *  - Resta la soglia minima "cliente attivo" di 120 EUR (mlm_piano.xlsx),
 *    sotto la quale un deposito non genera punti ne' base commissioni.
 *
 * Ogni assegnazione crea UNA riga nel ledger con una finestra di validita'
 * (valid_from..valid_until): per la durata della finestra, quella riga
 * contribuisce "points" ai punti attivi dell'agente (vedi User::mlmActivePoints()).
 * Non si tratta di un importo che cresce nel tempo: e' il tasso mensile
 * attribuito a quel deposito, attivo per 12 mesi consecutivi (stesso
 * principio di smoothing usato per la base commissioni).
 *
 * STORICO NON RICALCOLATO: le righe ledger create prima del 2026-07-20 con
 * gli scaglioni 1/12/24/36 mesi restano valide cosi' come sono state emesse
 * (stesso principio della retrocessione §4.2 e del margine Prov K).
 *
 * OVERRIDE DI TEST (2026-07-13, richiesta di Laura): l'admin puo' impostare
 * in /admin/mlm-impostazioni una scadenza punti in MINUTI che sostituisce la
 * durata normale (1/12 mesi) per TUTTI i nuovi punti assegnati — utile
 * per verificare subito il calcolo qualifiche in test invece di aspettare
 * mesi. Vedi SystemSetting::mlmSettings() e resolveValidUntil().
 */
class MlmPointsService
{
    /** Soglia minima "cliente attivo" (EUR centesimi): sotto i 120 EUR il deposito non genera nulla. */
    private const MIN_DEPOSIT_EUR_CENTS = 12_000;

    /** Mesi di imputazione dell'importo personale mensile ("dividendo per 12, se non diversamente previsto"). */
    private const DURATION_MONTHS = 12;

    /** 1 punto ogni 50 EUR di importo personale mensile (slide "Importo Personale Mensile"). */
    private const EUR_CENTS_PER_POINT = 5_000;

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

        $from = now();

        $this->createLedgerEntry(
            client: $client,
            sourceType: 'registration',
            sourceTransferId: null,
            points: 1,
            validFrom: $from,
            validUntil: $this->resolveValidUntil($from, normalDurationMonths: 1),
        );
    }

    /**
     * Assegna i punti deposito con la regola "/12" della slide: importo
     * mensile = deposito / 12, punti mensili = importo mensile / 50 EUR
     * (frazionari, arrotondati a 2 decimali), validi 12 mesi. Non fa nulla
     * se l'utente non e' un cliente MLM, non ha un agente risolto, o il
     * deposito e' sotto la soglia minima (120 EUR).
     */
    public function awardDepositPoints(User $client, int $depositEurCents, ?int $sourceTransferId = null): void
    {
        if (! $client->isMlmClient() || ! $client->mlm_client_agent_id) {
            return;
        }

        if ($depositEurCents < self::MIN_DEPOSIT_EUR_CENTS) {
            return;
        }

        $monthlyAmount = (int) round($depositEurCents / self::DURATION_MONTHS);
        $points = round($monthlyAmount / self::EUR_CENTS_PER_POINT, 2);

        if ($points <= 0) {
            return;
        }

        $from = now();

        $this->createLedgerEntry(
            client: $client,
            sourceType: 'deposit',
            sourceTransferId: $sourceTransferId,
            points: $points,
            validFrom: $from,
            validUntil: $this->resolveValidUntil($from, self::DURATION_MONTHS),
        );

        $this->createCommissionBaseEntry($client, $monthlyAmount, $sourceTransferId);
    }

    /**
     * Scrive la riga parallela nel ledger "importo mensile" commissionabile:
     * stesso importo mensile (deposito / 12) e stessa finestra di 12 mesi
     * dei punti, coerente con la slide "L'Importo Personale Mensile"
     * ("imputato dal mese stesso di fatturazione e per altri 11 mesi").
     *
     * Resta su durata mensile "normale" anche quando l'override di test e'
     * attivo: le commissioni girano su un ciclo mensile a prescindere (vedi
     * MlmCommissionEngine::runForMonth()), l'override serve solo a
     * velocizzare la verifica delle QUALIFICHE (punti), non delle commissioni.
     */
    private function createCommissionBaseEntry(User $client, int $monthlyAmountEurCents, ?int $sourceTransferId): void
    {
        $agent = $client->mlmClientAgent;
        if (! $agent) {
            return;
        }

        MlmCommissionBaseLedgerEntry::create([
            'client_user_id' => $client->id,
            'direct_agent_id' => $agent->id,
            'source_transfer_id' => $sourceTransferId,
            'monthly_amount_eur_cents' => $monthlyAmountEurCents,
            // Snapshot del margine KNM ("Prov K") al momento del deposito:
            // le commissioni si calcolano su monthly_amount x margine/100,
            // e un futuro cambio del margine in admin non deve riscrivere
            // retroattivamente i depositi gia' fatti (2026-07-16, slide
            // "Esempio compensi" — vedi MlmCommissionEngine).
            'knm_margin_percent' => SystemSetting::mlmSettings()->mlmKnmMarginPercent(),
            'valid_from' => now()->toDateString(),
            'valid_until' => now()->addMonths(self::DURATION_MONTHS)->toDateString(),
        ]);
    }

    /**
     * Calcola la scadenza di una riga del ledger punti. Se l'admin ha
     * impostato un override di test (in minuti), lo usa al posto della
     * durata normale di business. Altrimenti usa la durata normale in mesi,
     * spinta a FINE GIORNATA (23:59:59): preserva il comportamento storico
     * pre-2026-07-13, quando valid_until era una semplice DATE confrontata
     * con whereDate() — cioe' valida per l'intera giornata indicata, non solo
     * fino all'istante esatto N mesi dopo.
     */
    private function resolveValidUntil(Carbon $from, int $normalDurationMonths): Carbon
    {
        $overrideMinutes = SystemSetting::mlmSettings()->mlm_points_validity_override_minutes;

        if ($overrideMinutes) {
            return $from->copy()->addMinutes($overrideMinutes);
        }

        return $from->copy()->addMonths($normalDurationMonths)->endOfDay();
    }

    private function createLedgerEntry(
        User $client,
        string $sourceType,
        ?int $sourceTransferId,
        int|float $points,
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
            'valid_from'          => $validFrom,
            'valid_until'         => $validUntil,
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
                'valid_from'      => $validFrom->toDateTimeString(),
                'valid_until'     => $validUntil->toDateTimeString(),
            ],
        ]);
    }
}
