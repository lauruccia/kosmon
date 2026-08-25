<?php

namespace App\Jobs;

use App\Models\ClientWebhookDelivery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Consegna un evento all'endpoint di un'applicazione del circuito.
 *
 * Gemello di `SendWebhookJob` (i webhook aziendali) e volutamente separato da
 * lui: quello risponde a un'azienda che ha registrato il proprio indirizzo dal
 * portale e si spegne da solo dopo dieci fallimenti; questo risponde a un
 * pezzo di infrastruttura del circuito, configurato nel `.env`, che non deve
 * spegnersi da solo perché nessuno se ne accorgerebbe. Le firme e gli header
 * sono identici, così chi integra scrive una sola funzione di verifica.
 *
 * I ritentativi sono più lunghi e più radi di quelli aziendali: un evento come
 * `company.trading_status_changed` che si perde lascia un catalogo che vende al
 * mix sbagliato, quindi vale la pena insistere per un'ora invece che per tre
 * minuti.
 */
class SendClientWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public function __construct(
        public readonly string $clientId,
        public readonly string $event,
        public readonly string $url,
        public readonly string $secret,
        public readonly string $body,
    ) {
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function handle(): void
    {
        $signature = hash_hmac('sha256', $this->body, $this->secret);

        $delivery = ClientWebhookDelivery::create([
            'client_id' => $this->clientId,
            'event'     => $this->event,
            'url'       => $this->url,
            'body'      => $this->body,
            'attempts'  => $this->attempts(),
            'success'   => false,
        ]);

        try {
            $response = Http::withHeaders([
                'Content-Type'       => 'application/json',
                'X-KMoney-Signature' => 'sha256=' . $signature,
                'X-KMoney-Event'     => $this->event,
                'X-KMoney-Delivery'  => $delivery->uuid,
                'User-Agent'         => 'KMoney-Webhook/1.0',
            ])
                ->timeout(10)
                ->withBody($this->body, 'application/json')
                ->post($this->url);

            $delivery->update([
                'response_status' => $response->status(),
                'response_body'   => substr($response->body(), 0, 2000),
                'success'         => $response->successful(),
                'delivered_at'    => now(),
            ]);

            if (! $response->successful()) {
                // Fa fallire il job apposta: è così che scattano i ritentativi.
                throw new \RuntimeException('Risposta ' . $response->status() . ' da ' . $this->url);
            }
        } catch (\Throwable $e) {
            Log::warning('client_webhook.delivery_failed', [
                'client_id' => $this->clientId,
                'event'     => $this->event,
                'error'     => $e->getMessage(),
            ]);

            $delivery->update([
                'response_body' => substr($e->getMessage(), 0, 2000),
                'success'       => false,
                'delivered_at'  => now(),
            ]);

            throw $e;
        }
    }
}
