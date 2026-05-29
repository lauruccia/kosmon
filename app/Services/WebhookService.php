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
    public function dispatch(string $event, array $payload, Company $company): void
    {
        $webhooks = Webhook::query()
            ->where('company_id', $company->id)
            ->where('is_active', true)
            ->get();

        foreach ($webhooks as $webhook) {
            if ($webhook->listensTo($event)) {
                SendWebhookJob::dispatch($webhook, $event, $payload);
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
