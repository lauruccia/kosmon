<?php

namespace App\Services;

use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * WebPushService
 *
 * Invia Web Push notifications agli utenti tramite VAPID.
 * Richiede: composer require minishlink/web-push
 *
 * Le chiavi VAPID vengono lette da .env:
 *   VAPID_PUBLIC_KEY=...
 *   VAPID_PRIVATE_KEY=...
 *   VAPID_SUBJECT=mailto:noreply@kmoney.it
 */
class WebPushService
{
    private bool $available;

    public function __construct()
    {
        $this->available = class_exists(\Minishlink\WebPush\WebPush::class)
            && config('webpush.vapid.public_key')
            && config('webpush.vapid.private_key');
    }

    /**
     * Invia una notifica push a tutti i dispositivi di un utente.
     */
    public function notifyUser(User $user, string $title, string $body, array $options = []): void
    {
        if (! $this->available) {
            Log::debug('WebPush non disponibile — notifica saltata.', [
                'user_id' => $user->id,
                'title'   => $title,
            ]);
            return;
        }

        $subscriptions = PushSubscription::where('user_id', $user->id)->get();

        if ($subscriptions->isEmpty()) {
            return;
        }

        $this->sendToSubscriptions($subscriptions->all(), $title, $body, $options);
    }

    /**
     * Invia a una lista di PushSubscription.
     *
     * @param PushSubscription[] $subscriptions
     */
    public function sendToSubscriptions(array $subscriptions, string $title, string $body, array $options = []): void
    {
        if (! $this->available || empty($subscriptions)) {
            return;
        }

        try {
            $webPush = new \Minishlink\WebPush\WebPush([
                'VAPID' => [
                    'subject'    => config('webpush.vapid.subject', 'mailto:noreply@kmoney.it'),
                    'publicKey'  => config('webpush.vapid.public_key'),
                    'privateKey' => config('webpush.vapid.private_key'),
                ],
            ]);

            $payload = json_encode(array_merge([
                'title' => $title,
                'body'  => $body,
                'icon'  => '/assets/brand/icon-192.png',
                'badge' => '/assets/brand/icon-192.png',
                'tag'   => $options['tag'] ?? 'kmoney-notification',
                'url'   => $options['url'] ?? '/dashboard',
            ], $options['data'] ?? []));

            foreach ($subscriptions as $sub) {
                $notification = \Minishlink\WebPush\Notification::create()
                    ->withPayload($payload);

                $subscription = \Minishlink\WebPush\Subscription::create([
                    'endpoint'        => $sub->endpoint,
                    'publicKey'       => $sub->public_key,
                    'authToken'       => $sub->auth_token,
                    'contentEncoding' => $sub->content_encoding ?? 'aesgcm',
                ]);

                $webPush->queueNotification($notification, $subscription);
            }

            foreach ($webPush->flush() as $report) {
                if (! $report->isSuccess()) {
                    // Rimuovi subscription non valide (endpoint scaduto)
                    if ($report->isSubscriptionExpired()) {
                        PushSubscription::where('endpoint', $report->getEndpoint())->delete();
                        Log::info('Push subscription scaduta rimossa.', [
                            'endpoint' => substr($report->getEndpoint(), 0, 60),
                        ]);
                    } else {
                        Log::warning('Invio push fallito.', [
                            'reason'   => $report->getReason(),
                            'endpoint' => substr($report->getEndpoint(), 0, 60),
                        ]);
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::error('WebPushService exception: ' . $e->getMessage());
        }
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }
}
