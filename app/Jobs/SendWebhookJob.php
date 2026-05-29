<?php

namespace App\Jobs;

use App\Models\Webhook;
use App\Models\WebhookDelivery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SendWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 60; // secondi tra i retry

    public function __construct(
        public readonly Webhook $webhook,
        public readonly string  $event,
        public readonly array   $payload,
    ) {}

    public function handle(): void
    {
        $body = json_encode([
            'event'      => $this->event,
            'timestamp'  => now()->toIso8601String(),
            'payload'    => $this->payload,
        ], JSON_UNESCAPED_UNICODE);

        // Firma HMAC-SHA256
        $signature = hash_hmac('sha256', $body, $this->webhook->secret);

        $delivery = WebhookDelivery::create([
            'webhook_id' => $this->webhook->id,
            'event'      => $this->event,
            'payload'    => $this->payload,
            'success'    => false,
        ]);

        try {
            $response = Http::withHeaders([
                'Content-Type'          => 'application/json',
                'X-KMoney-Signature'    => 'sha256=' . $signature,
                'X-KMoney-Event'        => $this->event,
                'User-Agent'            => 'KMoney-Webhook/1.0',
            ])
            ->timeout(10)
            ->withBody($body, 'application/json')
            ->post($this->webhook->url);

            $success = $response->successful();

            $delivery->update([
                'response_status' => $response->status(),
                'response_body'   => substr($response->body(), 0, 2000),
                'success'         => $success,
                'delivered_at'    => now(),
            ]);

            if ($success) {
                $this->webhook->update([
                    'failure_count'      => 0,
                    'last_triggered_at'  => now(),
                ]);
            } else {
                $this->incrementFailureCount();
            }
        } catch (\Throwable $e) {
            Log::warning('Webhook delivery failed: ' . $e->getMessage(), [
                'webhook_id' => $this->webhook->id,
                'event'      => $this->event,
            ]);

            $delivery->update([
                'response_body' => $e->getMessage(),
                'success'       => false,
                'delivered_at'  => now(),
            ]);

            $this->incrementFailureCount();

            throw $e; // lascia che il job venga ri-schedulato
        }
    }

    private function incrementFailureCount(): void
    {
        $newCount = $this->webhook->failure_count + 1;
        $updates  = ['failure_count' => $newCount];

        if ($newCount >= Webhook::MAX_FAILURES) {
            $updates['is_active'] = false;
            Log::warning('Webhook disattivato per troppi fallimenti', ['webhook_id' => $this->webhook->id]);
        }

        $this->webhook->update($updates);
    }
}
