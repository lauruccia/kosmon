<?php

namespace App\Services;

use App\Jobs\SendClientWebhookJob;

/**
 * I webhook verso le APPLICAZIONI del circuito (oggi soltanto Kosmoshop).
 *
 * Perché non bastano i webhook aziendali che esistono già: quelli appartengono
 * a un'azienda e servono a un negoziante che vuole essere avvisato dei propri
 * incassi. Kosmoshop non è un'azienda, è il posto dove vendono tutte — e
 * l'evento che gli serve di più (`company.trading_status_changed`) riguarda
 * aziende che non hanno nessun motivo di sapere che kshop esiste. Se dovessero
 * registrarsi loro l'indirizzo, chi non lo facesse continuerebbe a vendere al
 * mix KY sbagliato: esattamente il guasto che l'evento esiste per evitare.
 *
 * Il canale vive dove vivono i client OAuth — `config/oauth.php` più due righe
 * di `.env` — e ha lo stesso interruttore: finché l'URL è vuoto, il canale non
 * esiste e qui non succede niente.
 */
class ClientWebhookService
{
    /**
     * Manda un evento a UNA applicazione.
     *
     * @param array<string, mixed> $payload
     */
    public function dispatch(string $clientId, string $event, array $payload, bool $afterCommit = false): void
    {
        $endpoint = $this->endpointFor($clientId);

        if ($endpoint === null) {
            return;
        }

        $body = json_encode([
            'event'     => $event,
            'timestamp' => now()->toIso8601String(),
            'payload'   => $payload,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $job = SendClientWebhookJob::dispatch(
            $clientId,
            $event,
            $endpoint['url'],
            $endpoint['secret'],
            (string) $body,
        );

        // Gli eventi che nascono dentro una transazione (il cambio di stato
        // commerciale nasce da un movimento) vanno messi in coda solo dopo il
        // commit: altrimenti si può spedire la notizia di un saldo che il
        // rollback ha poi cancellato.
        if ($afterCommit) {
            $job->afterCommit();
        }
    }

    /**
     * Manda un evento a TUTTE le applicazioni configurate. È il caso degli
     * eventi di circuito, che non appartengono a nessun ordine e a nessuna
     * applicazione in particolare.
     *
     * @param array<string, mixed> $payload
     */
    public function broadcast(string $event, array $payload, bool $afterCommit = false): void
    {
        foreach ($this->configuredClientIds() as $clientId) {
            $this->dispatch($clientId, $event, $payload, $afterCommit);
        }
    }

    /**
     * @return array{url: string, secret: string}|null
     */
    public function endpointFor(string $clientId): ?array
    {
        foreach ((array) config('oauth.clients', []) as $client) {
            if (($client['client_id'] ?? null) !== $clientId || $clientId === '') {
                continue;
            }

            $url    = (string) ($client['webhook']['url'] ?? '');
            $secret = (string) ($client['webhook']['secret'] ?? '');

            // Un URL senza segreto sarebbe un webhook non firmato: chi lo
            // riceve non potrebbe distinguerlo da uno inventato da chiunque.
            // Meglio non spedire niente che spedire qualcosa di non verificabile.
            if ($url === '' || $secret === '') {
                return null;
            }

            return ['url' => $url, 'secret' => $secret];
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    public function configuredClientIds(): array
    {
        $ids = [];

        foreach ((array) config('oauth.clients', []) as $client) {
            $clientId = (string) ($client['client_id'] ?? '');

            if ($clientId !== '') {
                $ids[] = $clientId;
            }
        }

        return $ids;
    }
}
