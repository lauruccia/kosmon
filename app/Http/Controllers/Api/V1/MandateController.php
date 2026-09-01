<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\MandateException;
use App\Http\Controllers\Controller;
use App\Models\OAuthAccessToken;
use App\Models\PaymentMandate;
use App\Services\PaymentMandateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

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

            // Dove riportare l'utente su kshop se l'acquisto va confermato a
            // mano. Confrontato con l'elenco chiuso del client prima di essere
            // salvato da qualsiasi parte: non è un campo libero.
            'return_url'            => ['nullable', 'string', 'max:500'],
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

        // Quote del circuito (01/09/2026). Il middleware EnsureRegistrationFeePaid
        // e' agganciato al gruppo `web` e questa rotta sta in routes/api.php:
        // di qui non passa. Senza questo controllo, chi deve una quota poteva
        // concedere un mandato dal portale (la pagina del consenso non e' fra
        // le rotte bloccate) e poi far pagare l'app collegata per suo conto —
        // cioe' spendere KY con il conto che dovrebbe essere fermo.
        //
        // Si rifiuta e basta, con 403: il 402 di questa API vuol dire "manda
        // l'utente a confermare l'acquisto", e qui non c'e' nessun acquisto
        // da confermare finche' la quota non e' saldata.
        $quotaPrivati = app(\App\Services\RegistrationFeeService::class);
        $quotaAgente  = app(\App\Services\AgentCodeFeeService::class);

        if ($quotaPrivati->isDueFor($token->user) || $quotaAgente->isDueFor($token->user)) {
            return response()->json([
                'status'  => 'refused',
                'reason'  => 'circuit_fee_due',
                'message' => 'Il conto non può effettuare pagamenti finché la quota del circuito non è saldata.',
            ], 403)->header('Cache-Control', 'no-store');
        }

        try {
            $esito = $this->mandates->charge($mandate, $validated, $request->ip());
        } catch (MandateException $e) {
            return $this->confirmationResponse($e, $mandate, $validated, $request);
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
     * Il 402 di fase 2a diceva soltanto "chiediglielo". Adesso dice anche
     * *dove*: `payment_request_uuid` e l'URL su cui mandare l'utente.
     *
     * Il contratto per chi integra non cambia — è lo stesso `if` di prima, con
     * due campi in più — e non cambia nemmeno se qui qualcosa va storto: se la
     * richiesta di conferma non si riesce a creare, la risposta resta il 402
     * nudo di fase 2a. Meglio un client che ripiega sul redirect classico di
     * uno che riceve un 500 in mezzo a un checkout.
     *
     * I 422 non passano di qui: quelli non sono cose che una conferma
     * dell'utente possa aggiustare.
     *
     * @param array<string, mixed> $validated
     * @return array<string, mixed>
     */
    private function confirmationResponse(
        MandateException $e,
        PaymentMandate $mandate,
        array $validated,
        Request $request,
    ): JsonResponse {
        $body = $e->toArray();

        if ($e->status !== 402) {
            return $this->jsonNoStore($body, $e->status);
        }

        try {
            $riga = $this->mandates->requestConfirmation(
                $mandate,
                $validated,
                $e->reason,
                $validated['return_url'] ?? null,
                $request->ip(),
            );
        } catch (MandateException $inner) {
            // La richiesta di conferma non sta in piedi (venditore inesistente,
            // indirizzo di ritorno non autorizzato): quello è un errore di chi
            // chiama, e va detto come tale — con il suo stato — invece di
            // essere nascosto dentro un 402 che l'utente non potrebbe comunque
            // risolvere.
            return $this->jsonNoStore($inner->toArray(), $inner->status);
        } catch (\Throwable $inner) {
            Log::error('mandate.confirmation_request_failed', [
                'mandate_uuid' => $mandate->uuid,
                'reason'       => $e->reason,
                'error'        => $inner->getMessage(),
            ]);

            return $this->jsonNoStore($body, 402);
        }

        $richiesta = $riga->paymentRequest;

        if ($richiesta === null) {
            return $this->jsonNoStore($body, 402);
        }

        $body['payment_request_uuid']    = $richiesta->uuid;
        $body['confirmation_url']        = $richiesta->payUrl();
        $body['confirmation_expires_at'] = $richiesta->expires_at->toIso8601String();

        return $this->jsonNoStore($body, 402);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function jsonNoStore(array $body, int $status): JsonResponse
    {
        return response()->json($body, $status)->header('Cache-Control', 'no-store');
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
