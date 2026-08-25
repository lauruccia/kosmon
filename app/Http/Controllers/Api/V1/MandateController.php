<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\MandateException;
use App\Http\Controllers\Controller;
use App\Models\OAuthAccessToken;
use App\Models\PaymentMandate;
use App\Services\PaymentMandateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Gli endpoint che l'applicazione collegata usa per pagare in KY senza
 * rimbalzare l'utente su KMoney a ogni acquisto.
 *
 * Chi parla qui è il server di kshop, con il token OAuth dell'utente e lo scope
 * `mandate`. Due sole operazioni: "che autorizzazione ho?" e "addebita".
 *
 * La forma delle risposte è pensata perché dall'altra parte si possa scrivere
 * un `if` solo:
 *   200  fatto, ecco il movimento
 *   402  serve la conferma dell'utente (il `reason` dice perché)
 *   422  la richiesta non sta in piedi (venditore inesistente, importo assurdo)
 *
 * In fase 2b il 402 portera' anche `payment_request_uuid` e l'URL su cui
 * mandare l'utente a confermare; il contratto qui non cambia.
 */
class MandateController extends Controller
{
    public function __construct(private readonly PaymentMandateService $mandates)
    {
    }

    /**
     * GET /api/v1/mandates — le autorizzazioni vive di questo utente per
     * QUESTA applicazione. Serve a kshop per sapere se può proporre il
     * pagamento in un clic, e con che tetto.
     */
    public function index(Request $request): JsonResponse
    {
        /** @var OAuthAccessToken $token */
        $token = $request->attributes->get('oauth_token');

        $mandates = $this->mandates
            ->activeMandatesFor($token->user, $token->client_id)
            ->orderByDesc('id')
            ->get()
            ->map(fn (PaymentMandate $m) => $this->present($m));

        return response()->json(['mandates' => $mandates])->header('Cache-Control', 'no-store');
    }

    /**
     * POST /api/v1/mandates/{uuid}/charge
     */
    public function charge(Request $request, string $uuid): JsonResponse
    {
        /** @var OAuthAccessToken $token */
        $token = $request->attributes->get('oauth_token');

        $validated = $request->validate([
            'seller_account_number' => ['required', 'string', 'max:32'],
            'amount'                => ['required', 'integer', 'min:1'],
            'external_order_uuid'   => ['nullable', 'string', 'max:64'],
            'order_title'           => ['nullable', 'string', 'max:255'],
            'quantity'              => ['nullable', 'integer', 'min:1', 'max:999999'],
            'idempotency_key'       => ['required', 'string', 'max:100'],
        ]);

        // Il mandato deve essere di QUESTO utente e di QUESTA applicazione:
        // un token rubato di un'altra app non può spendere questo permesso.
        $mandate = PaymentMandate::query()
            ->where('uuid', $uuid)
            ->where('user_id', $token->user_id)
            ->where('client_id', $token->client_id)
            ->first();

        if (! $mandate) {
            return response()->json([
                'status'  => 'refused',
                'reason'  => 'mandate_not_found',
                'message' => 'Autorizzazione non trovata.',
            ], 404)->header('Cache-Control', 'no-store');
        }

        try {
            $esito = $this->mandates->charge($mandate, $validated, $request->ip());
        } catch (MandateException $e) {
            return response()->json($e->toArray(), $e->status)->header('Cache-Control', 'no-store');
        }

        return response()->json([
            'status'        => 'booked',
            'repeated'      => $esito['repeated'],   // true = era un retry, non un secondo addebito
            'transfer_uuid' => $esito['transfer']->uuid,
            'charge_uuid'   => $esito['charge']->uuid,
            'amount'        => $esito['charge']->amount,
            'booked_at'     => optional($esito['transfer']->booked_at)->toIso8601String(),
            'mandate'       => $this->present($mandate->fresh()),
        ])->header('Cache-Control', 'no-store');
    }

    /**
     * @return array<string, mixed>
     */
    private function present(PaymentMandate $mandate): array
    {
        return [
            'uuid'                => $mandate->uuid,
            'max_per_transaction' => $mandate->max_per_transaction,
            'authorized_sellers'  => $mandate->authorized_sellers,
            'expires_at'          => $mandate->expires_at->toIso8601String(),
            'suspended'           => $mandate->isSuspended(),
            'charges_count'       => $mandate->charges_count,
            'last_used_at'        => optional($mandate->last_used_at)->toIso8601String(),
        ];
    }
}
