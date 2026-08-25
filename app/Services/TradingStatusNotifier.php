<?php

namespace App\Services;

use App\Models\Account;

/**
 * `company.trading_status_changed` — §3.2 di PIANO_SHOP_ESTERNO.md.
 *
 * È l'aggancio più stretto fra la banca e il negozio, e l'unico che non si può
 * semplicemente tagliare. Oggi `Account::syncListingsKyPercentage()` fa una
 * UPDATE diretta sulla tabella `listings`: appena un'azienda va in debito i suoi
 * prodotti passano d'ufficio al 100% KY, e appena ne esce tornano alla
 * percentuale che aveva scelto. Quando il catalogo sarà in un'altra
 * applicazione, quella UPDATE non si potrà più fare — e senza qualcosa che la
 * sostituisca **le aziende in debito venderebbero al mix sbagliato**, cioè
 * incasserebbero euro che secondo le regole del circuito non dovrebbero
 * incassare.
 *
 * Quel qualcosa è questo evento. Il calcolo resta in banca (`tradingStatus()`,
 * `allowedKyPercentages()`, `requiredKyPercentage()`): chi lo riceve non deve
 * reimplementare nessuna regola, deve solo applicare quello che gli viene
 * detto. Finché lo shop interno è vivo le due cose convivono — la UPDATE per il
 * catalogo di casa, il webhook per quello di fuori — e la UPDATE sparirà con lo
 * shop interno, in fase 5.
 */
class TradingStatusNotifier
{
    public function __construct(
        private readonly ClientWebhookService $clientWebhooks,
        private readonly WebhookService $webhooks,
    ) {
    }

    public function announce(Account $account, string $previousStatus, string $currentStatus): void
    {
        $company = $account->company;

        if ($company === null) {
            return;
        }

        $payload = [
            'company_uuid'           => $company->uuid,
            'company_name'           => $company->name,
            'account_number'         => $account->account_number,
            'trading_status'         => $currentStatus,
            'previous_trading_status' => $previousStatus,

            // Le regole già applicate, non i dati grezzi per riapplicarle.
            // `required_ky_percentage` non è ridondante rispetto a
            // `allowed_ky_percentages`: dice la differenza fra "puoi scegliere
            // solo 100" e "devi stare a 100", che è la stessa differenza fra un
            // negozio che sceglie e un negozio a cui è stato imposto.
            'can_sell'               => $account->canSell(),
            'allowed_ky_percentages' => $account->allowedKyPercentages(),
            'required_ky_percentage' => $account->requiredKyPercentage(),

            'changed_at'             => now()->toIso8601String(),
        ];

        // Alle applicazioni del circuito: è a loro che serve davvero, perché
        // hanno il catalogo in casa propria.
        $this->clientWebhooks->broadcast('company.trading_status_changed', $payload, afterCommit: true);

        // E all'azienda stessa, se si è registrata un webhook: chi ha un
        // gestionale collegato ha diritto di sapere che da adesso può vendere
        // solo al 100% KY, senza scoprirlo dal primo ordine rifiutato.
        $this->webhooks->dispatch('company.trading_status_changed', $payload, $company, afterCommit: true);
    }
}
