<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MandateSubscription;
use App\Models\OAuthAccessToken;
use App\Services\MandateSubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Gli abbonamenti, dal lato del portale che li addebita.
 *
 * Il portale (con il token OAuth dell'utente e lo scope `mandate`) fa due cose:
 *
 *   PUT /api/v1/subscriptions/{external_id}   "ecco com'è fatto adesso"
 *   GET /api/v1/subscriptions                 "cosa sa KMoney dei miei abbonamenti?"
 *
 * Il PUT è idempotente e si può rifare a ogni cambiamento senza pensarci: crea
 * la copia la prima volta e la aggiorna le volte dopo. Non può MAI sbloccare un
 * abbonamento — il blocco è dell'utente — ma dice nella risposta se è
 * bloccato, così il portale può smettere di insistere invece di scoprirlo al
 * primo addebito rifiutato.
 */
class SubscriptionController extends Controller
{
    public function __construct(private readonly MandateSubscriptionService $subscriptions)
    {
    }

    /**
     * GET /api/v1/subscriptions — gli abbonamenti di questo utente per
     * QUESTA applicazione.
     */
    public function index(Request $request): JsonResponse
    {
        /** @var OAuthAccessToken $token */
        $token = $request->attributes->get('oauth_token');

        $items = MandateSubscription::query()
            ->where('user_id', $token->user_id)
            ->where('client_id', $token->client_id)
            ->orderByDesc('id')
            ->get()
            ->map(fn (MandateSubscription $s) => $this->present($s));

        return response()->json(['subscriptions' => $items])->header('Cache-Control', 'no-store');
    }

    /**
     * PUT /api/v1/subscriptions/{external_id}
     */
    public function upsert(Request $request, string $externalId): JsonResponse
    {
        /** @var OAuthAccessToken $token */
        $token = $request->attributes->get('oauth_token');

        $validated = $request->validate([
            'title'                 => ['required', 'string', 'max:255'],
            'items'                 => ['nullable', 'array', 'max:20'],
            'items.*'               => ['string', 'max:255'],
            'seller_account_number' => ['nullable', 'string', 'max:32'],
            // Centesimi di KY per ciclo. Nullable: il portale può non saperlo
            // prima del primo ordine.
            'amount'                => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'interval_months'       => ['nullable', 'integer', 'min:1', 'max:60'],
            'status'                => ['required', 'string', 'in:' . implode(',', MandateSubscription::STATUSES)],
            'next_charge_at'        => ['nullable', 'date'],
            'last_error'            => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $subscription = $this->subscriptions->sync(
                $token->user,
                $token->client_id,
                $externalId,
                $validated,
            );
        } catch (RuntimeException $e) {
            return response()->json([
                'status'  => 'refused',
                'reason'  => 'subscription_owner_mismatch',
                'message' => $e->getMessage(),
            ], 409)->header('Cache-Control', 'no-store');
        }

        return response()->json($this->present($subscription))
            ->setStatusCode($subscription->wasRecentlyCreated ? 201 : 200)
            ->header('Cache-Control', 'no-store');
    }

    /**
     * @return array<string, mixed>
     */
    private function present(MandateSubscription $s): array
    {
        return [
            'uuid'            => $s->uuid,
            'external_id'     => $s->external_id,
            'title'           => $s->title,
            'status'          => $s->status,
            'blocked'         => $s->isBlocked(),
            'blocked_at'      => optional($s->blocked_at)->toIso8601String(),
            'amount'          => $s->amount,
            'interval_months' => $s->interval_months,
            'next_charge_at'  => optional($s->next_charge_at)->toIso8601String(),
            'last_charged_at' => optional($s->last_charged_at)->toIso8601String(),
        ];
    }
}
