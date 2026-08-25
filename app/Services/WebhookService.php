<?php

namespace App\Services;

use App\Jobs\SendWebhookJob;
use App\Models\Company;
use App\Models\Webhook;

class WebhookService
{
    /**
     * Dispatcha i webhook attivi per una company che ascoltano l'evento dato.
     */
    public function dispatch(string $event, array $payload, Company $company, bool $afterCommit = false): void
    {
        $webhooks = Webhook::query()
            ->where('company_id', $company->id)
            ->where('is_active', true)
            ->get();

        foreach ($webhooks as $webhook) {
            if ($webhook->listensTo($event)) {
                $job = SendWebhookJob::dispatch($webhook, $event, $payload);

                // Gli eventi che nascono DENTRO una transazione (il cambio di
                // stato commerciale nasce da un movimento) vanno messi in coda
                // solo dopo il commit: altrimenti si rischia di spedire la
                // notizia di un saldo che il rollback ha poi cancellato.
                // I chiamanti storici non passano niente e si comportano
                // esattamente come prima.
                if ($afterCommit) {
                    $job->afterCommit();
                }
            }
        }
    }

    /**
     * Utility: chiama dispatch sia per il mittente che per il destinatario.
     */
    public function dispatchForBoth(string $event, array $payload, ?Company $from, ?Company $to): void
    {
        if ($from) {
            $this->dispatch($event, $payload, $from);
        }
        if ($to && $to->id !== $from?->id) {
            $this->dispatch($event, $payload, $to);
        }
    }
}
