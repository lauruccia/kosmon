<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\MlmCommissionBaseLedgerEntry;
use App\Models\MlmPointLedgerEntry;
use App\Models\KyCard;
use App\Models\MlmPointRule;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Assegna i "punti cliente" (PC) agli agenti in base alle azioni dei loro
 * clienti diretti (registrazione, deposito). Vedi MLM_PROPOSAL.md §4.1.
 *
 * TABELLA "PUNTI PER EVENTO" (2026-07-22, decisione di Laura — sostituisce
 * la regola "/12 + frazionari" del 2026-07-20):
 *
 *  - I punti NON vengono piu' spalmati su 12 mesi: la ricarica matura i suoi
 *    punti NEL MOMENTO in cui avviene ("l'importo vale nel mese in cui viene
 *    maturato") e restano attivi per la durata in GIORNI configurata.
 *  - L'apertura conto legge la riga 'registration' di mlm_point_rules
 *    (editabile in /admin/mlm-impostazioni; seed: 1 punto / 90 giorni).
 *  - Le RICARICHE leggono i punti dalla KY CARD acquistata (osservazione di
 *    Laura del 22/07: i tagli di ricarica reali sono le card di
 *    /admin/ky-cards, non un elenco separato). Ogni card ha mlm_points e
 *    mlm_points_duration_days, editabili card per card; 0 punti = la card
 *    non genera punti. Backfill iniziale per fascia di prezzo (>=1.200 EUR
 *    -> 2 pt / 360 gg; >=600 -> 2 pt / 180 gg; >=120 -> 2 pt / 30 gg).
 *  - Quando la card non e' nota (es. simulatore, che parte da un importo
 *    libero) si usa la card ATTIVA con prezzo esatto oppure, in mancanza,
 *    quella col prezzo piu' alto <= importo (vedi resolveCardForAmount()).
 *    Nessuna card risolta = la ricarica non genera ne' punti ne' base
 *    commissioni.
 *
 * BASE COMMISSIONI "UNA TANTUM" (stessa decisione): anche la base
 * commissionabile non e' piu' l'importo mensile deposito/12 per 12 mesi, ma
 * l'INTERO importo della ricarica, pagato UNA SOLA VOLTA dal primo run
 * mensile successivo (vedi createCommissionBaseEntry()).
 *
 * Ogni assegnazione crea UNA riga nel ledger con una finestra di validita'
 * (valid_from..valid_until): per la durata della finestra, quella riga
 * contribuisce "points" ai punti attivi dell'agente (vedi
 * User::mlmActivePoints()).
 *
 * STORICO NON RICALCOLATO: le righe ledger create con le regole precedenti
 * (scaglioni 1/12/24/36 mesi fino al 2026-07-20, "/12 + frazionari" fino al
 * 2026-07-22) restano valide cosi' come sono state emesse (stesso principio
 * della retrocessione §4.2 e del margine Prov K).
 *
 * OVERRIDE DI TEST (2026-07-13, richiesta di Laura): l'admin puo' impostare
 * in /admin/mlm-impostazioni una scadenza punti in MINUTI che sostituisce la
 * durata normale (giorni da tabella) per TUTTI i nuovi punti assegnati —
 * utile per verificare subito il calcolo qualifiche in test invece di
 * aspettare mesi. Vedi SystemSetting::mlmSettings() e resolveValidUntil().
 */
class MlmPointsService
{
    /**
     * Assegna i punti "apertura conto" (riga 'registration' della tabella
     * mlm_point_rules, seed: 1 punto / 90 giorni) all'agente risolto quando
     * un suo cliente diretto completa la registrazione. Non fa nulla se
     * l'utente non e' un cliente MLM, non ha un agente risolto (invito
     * "orfano"), o l'admin ha eliminato la riga di registrazione.
     */
    public function awardRegistrationPoints(User $client): void
    {
        if (! $client->isMlmClient() || ! $client->mlm_client_agent_id) {
            return;
        }

        $rule = MlmPointRule::registrationRule();

        if (! $rule || $rule->points <= 0) {
            return;
        }

        $from = now();

        $this->createLedgerEntry(
            client: $client,
            sourceType: 'registration',
            sourceTransferId: null,
            points: mlm_points_normalize($rule->points),
            validFrom: $from,
            validUntil: $this->resolveValidUntil($from, $rule->duration_days),
        );
    }

    /**
     * Assegna i punti ricarica secondo la KY CARD acquistata: mlm_points
     * per mlm_points_duration_days giorni (niente piu' spalmatura /12: i
     * punti maturano subito). Se la card non viene passata (es. simulatore,
     * che parte da un importo libero) viene risolta dal prezzo con
     * resolveCardForAmount(). Non fa nulla se l'utente non e' un cliente
     * MLM, non ha un agente risolto, o nessuna card corrisponde all'importo.
     */
    public function awardDepositPoints(User $client, int $depositEurCents, ?int $sourceTransferId = null, ?KyCard $card = null): void
    {
        if (! $client->isMlmClient() || ! $client->mlm_client_agent_id) {
            return;
        }

        $card ??= $this->resolveCardForAmount($depositEurCents);

        if (! $card) {
            return;
        }

        $from = now();

        if ($card->mlm_points > 0 && $card->mlm_points_duration_days > 0) {
            $this->createLedgerEntry(
                client: $client,
                sourceType: 'deposit',
                sourceTransferId: $sourceTransferId,
                points: mlm_points_normalize($card->mlm_points),
                validFrom: $from,
                validUntil: $this->resolveValidUntil($from, $card->mlm_points_duration_days),
            );
        }

        $this->createCommissionBaseEntry($client, $depositEurCents, $sourceTransferId);
    }

    /**
     * Risolve la KY Card di una ricarica quando e' noto solo l'importo
     * (simulatore): prima la card ATTIVA con prezzo esattamente uguale,
     * altrimenti quella attiva col prezzo piu' alto <= importo (un importo
     * fuori taglio ricade sul taglio inferiore). A parita' di prezzo vince
     * il sort_order del catalogo. NULL se nessuna card attiva ha prezzo
     * <= importo.
     */
    private function resolveCardForAmount(int $depositEurCents): ?KyCard
    {
        return KyCard::query()
            ->where('is_active', true)
            ->where('price_eur_cents', '<=', $depositEurCents)
            ->orderByDesc('price_eur_cents')
            ->orderBy('sort_order')
            ->first();
    }

    /**
     * Scrive la riga nel ledger della base commissionabile: l'INTERO importo
     * della ricarica, pagato UNA SOLA VOLTA ("una tantum nel mese", decisione
     * 2026-07-22 che sostituisce lo smoothing deposito/12 per 12 mesi).
     *
     * La finestra di validita' e' il 1° del mese SUCCESSIVO alla ricarica
     * (valid_from = valid_until): il motore commissioni gira il 1° di ogni
     * mese sulle righe attive quel giorno (MlmCommissionEngine::runForMonth),
     * quindi la riga viene catturata esattamente dal primo run utile e mai
     * piu' — stessa tempistica di prima per il primo pagamento (una ricarica
     * di luglio paga al run del 1° agosto), senza le 11 mensilita'
     * successive. La finestra di un solo giorno evita anche il doppio
     * pagamento nel caso limite di una ricarica fatta il 1° del mese prima
     * delle 02:00 (l'orario del run schedulato).
     *
     * La colonna si chiama ancora monthly_amount_eur_cents per compatibilita'
     * con lo storico (righe pre-2026-07-22 = importo mensile deposito/12,
     * ancora attive fino a scadenza naturale): per le righe nuove contiene
     * l'importo pieno della ricarica.
     *
     * Resta fuori dall'override di test dei punti: le commissioni girano su
     * un ciclo mensile a prescindere, l'override serve solo a velocizzare la
     * verifica delle QUALIFICHE (punti), non delle commissioni.
     */
    private function createCommissionBaseEntry(User $client, int $depositEurCents, ?int $sourceTransferId): void
    {
        $agent = $client->mlmClientAgent;
        if (! $agent) {
            return;
        }

        $nextRunDate = now()->addMonthNoOverflow()->startOfMonth()->toDateString();

        MlmCommissionBaseLedgerEntry::create([
            'client_user_id' => $client->id,
            'direct_agent_id' => $agent->id,
            'source_transfer_id' => $sourceTransferId,
            'monthly_amount_eur_cents' => $depositEurCents,
            // Snapshot del margine KNM ("Prov K") al momento del deposito:
            // le commissioni si calcolano su importo x margine/100, e un
            // futuro cambio del margine in admin non deve riscrivere
            // retroattivamente i depositi gia' fatti (2026-07-16, slide
            // "Esempio compensi" — vedi MlmCommissionEngine).
            'knm_margin_percent' => SystemSetting::mlmSettings()->mlmKnmMarginPercent(),
            'valid_from' => $nextRunDate,
            'valid_until' => $nextRunDate,
        ]);
    }

    /**
     * Calcola la scadenza di una riga del ledger punti. Se l'admin ha
     * impostato un override di test (in minuti), lo usa al posto della
     * durata normale di business. Altrimenti usa la durata in GIORNI dalla
     * tabella mlm_point_rules, spinta a FINE GIORNATA (23:59:59): preserva
     * il comportamento storico pre-2026-07-13, quando valid_until era una
     * semplice DATE confrontata con whereDate() — cioe' valida per l'intera
     * giornata indicata, non solo fino all'istante esatto N giorni dopo.
     */
    private function resolveValidUntil(Carbon $from, int $normalDurationDays): Carbon
    {
        $overrideMinutes = SystemSetting::mlmSettings()->mlm_points_validity_override_minutes;

        if ($overrideMinutes) {
            return $from->copy()->addMinutes($overrideMinutes);
        }

        return $from->copy()->addDays($normalDurationDays)->endOfDay();
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
