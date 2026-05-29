<?php

namespace App\Http\Controllers;

use App\Models\PushSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * PushSubscriptionController
 *
 * Gestisce le sottoscrizioni Web Push del browser.
 *
 *   POST   /push/subscribe    -> salva o aggiorna la subscription
 *   DELETE /push/subscribe    -> rimuove la subscription
 *   GET    /push/vapid-key    -> restituisce la public key VAPID
 */
class PushSubscriptionController extends Controller
{
    /** Restituisce la chiave pubblica VAPID (usata dal client per subscribe). */
    public function vapidKey(): JsonResponse
    {
        return response()->json([
            'publicKey' => config('webpush.vapid.public_key', ''),
        ]);
    }

    /** Salva o aggiorna la push subscription dell'utente corrente. */
    public function subscribe(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'endpoint'         => ['required', 'string', 'max:1000'],
            'keys.p256dh'      => ['required', 'string'],
            'keys.auth'        => ['required', 'string'],
            'contentEncoding'  => ['nullable', 'string', 'max:20'],
        ]);

        PushSubscription::updateOrCreate(
            ['endpoint' => $validated['endpoint']],
            [
                'user_id'          => $request->user()->id,
                'public_key'       => $validated['keys']['p256dh'],
                'auth_token'       => $validated['keys']['auth'],
                'content_encoding' => $validated['contentEncoding'] ?? 'aesgcm',
            ]
        );

        return response()->json(['status' => 'subscribed'], 201);
    }

    /** Rimuove la push subscription. */
    public function unsubscribe(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'endpoint' => ['required', 'string', 'max:1000'],
        ]);

        PushSubscription::where('user_id', $request->user()->id)
            ->where('endpoint', $validated['endpoint'])
            ->delete();

        return response()->json(['status' => 'unsubscribed']);
    }
}
