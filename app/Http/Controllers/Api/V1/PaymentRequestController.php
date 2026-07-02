<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\PaymentRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentRequestController extends Controller
{
    /**
     * GET /api/v1/payment-requests
     *
     * Lista richieste di pagamento ricevute (to_account) o inviate (from_account).
     * Parametri opzionali:
     *   ?status=pending|paid|expired|cancelled
     *   ?direction=incoming|outgoing   (default: entrambi)
     *   ?per_page=25                   (max 100)
     */
    public function index(Request $request): JsonResponse
    {
        $company = $request->attributes->get('api_company');

        $account = Account::where('company_id', $company->id)
            ->whereNull('parent_account_id')
            ->where('status', 'active')
            ->first();

        if (! $account) {
            return response()->json(['error' => 'No active account'], 404);
        }

        $status    = $request->query('status');
        $direction = $request->query('direction');
        $perPage   = min((int) ($request->query('per_page', 25)), 100);

        $query = PaymentRequest::with(['toAccount.company', 'fromAccount.company', 'transfer']);

        // Filtra per direzione
        if ($direction === 'incoming') {
            // Il conto è il destinatario (chi incassa)
            $query->where('to_account_id', $account->id);
        } elseif ($direction === 'outgoing') {
            // Il conto è il pagante
            $query->where('from_account_id', $account->id);
        } else {
            $query->where(fn ($q) => $q
                ->where('to_account_id', $account->id)
                ->orWhere('from_account_id', $account->id)
            );
        }

        // Filtra per status
        $allowedStatuses = ['pending', 'paid', 'expired', 'cancelled'];
        if ($status && in_array($status, $allowedStatuses, true)) {
            $query->where('status', $status);
        }

        $requests = $query->latest()->paginate($perPage);

        return response()->json([
            'data' => $requests->getCollection()->map(fn ($r) => $this->formatRequest($r, $account)),
            'meta' => [
                'current_page' => $requests->currentPage(),
                'last_page'    => $requests->lastPage(),
                'total'        => $requests->total(),
            ],
        ]);
    }

    /**
     * POST /api/v1/payment-requests
     *
     * Crea una richiesta di pagamento "hosted" a carico del conto del negoziante
     * autenticato (destinatario = azienda proprietaria del token). Pensata per
     * integrazioni e-commerce (WooCommerce, Magento, ecc.): il negoziante crea la
     * richiesta server-to-server, reindirizza il cliente su `pay_url`, e riceve
     * l'esito via webhook `payment_request.paid` (vedi POST /webhook nel portale)
     * oppure interrogando GET /payment-requests/{uuid}.
     *
     * Richiede ability: write.
     *
     * Body:
     *   amount               int      obbligatorio, centesimi di KY, minimo 1
     *   description           string   opzionale, max 255
     *   external_reference    string   opzionale, max 191 — riferimento ordine lato negoziante
     *                                  (es. numero ordine WooCommerce/Magento). Se una richiesta
     *                                  pending con lo stesso external_reference + amount esiste
     *                                  già per questo conto, viene restituita quella (idempotenza
     *                                  sui retry di rete lato checkout), invece di crearne una nuova.
     *   return_url            string   opzionale, max 500, URL assoluto — il portale KMoney non
     *                                  reindirizza automaticamente qui: è ad uso del negoziante,
     *                                  restituito nella risposta per costruire i propri link.
     *   cancel_url             string   opzionale, max 500
     *   expires_in_minutes     int      opzionale, 1-1440, default 30
     */
    public function store(Request $request): JsonResponse
    {
        $company = $request->attributes->get('api_company');
        $token   = $request->attributes->get('api_token');

        if (! $token->can('write')) {
            return response()->json(['error' => 'Token requires write ability'], 403);
        }

        $data = $request->validate([
            'amount'              => ['required', 'integer', 'min:1'],
            'description'         => ['nullable', 'string', 'max:255'],
            'external_reference'  => ['nullable', 'string', 'max:191'],
            'return_url'          => ['nullable', 'string', 'url', 'max:500'],
            'cancel_url'          => ['nullable', 'string', 'url', 'max:500'],
            'expires_in_minutes'  => ['nullable', 'integer', 'min:1', 'max:1440'],
        ]);

        $account = Account::where('company_id', $company->id)
            ->whereNull('parent_account_id')
            ->where('status', 'active')
            ->first();

        if (! $account) {
            return response()->json(['error' => 'No active account for this company'], 422);
        }

        // Idempotenza sui retry: se esiste già una richiesta pending con lo stesso
        // external_reference + importo per questo conto, restituisce quella invece
        // di crearne una nuova (evita QR/link duplicati se il checkout ripete la chiamata).
        if (! empty($data['external_reference'])) {
            $existing = PaymentRequest::where('to_account_id', $account->id)
                ->where('external_reference', $data['external_reference'])
                ->where('amount', (int) $data['amount'])
                ->where('status', 'pending')
                ->first();

            if ($existing && ! $existing->isExpired()) {
                return response()->json(['data' => $this->formatRequest($existing, $account)], 200);
            }
        }

        $pr = PaymentRequest::create([
            'kind'                => 'ecommerce',
            'created_by_user_id'  => $token->created_by,
            'to_account_id'       => $account->id,
            'amount'              => (int) $data['amount'],
            'description'         => $data['description'] ?? null,
            'external_reference'  => $data['external_reference'] ?? null,
            'return_url'          => $data['return_url'] ?? null,
            'cancel_url'          => $data['cancel_url'] ?? null,
            'status'              => 'pending',
            'expires_at'          => now()->addMinutes((int) ($data['expires_in_minutes'] ?? 30)),
        ]);

        return response()->json(['data' => $this->formatRequest($pr, $account)], 201);
    }

    /**
     * GET /api/v1/payment-requests/{uuid}
     */
    public function show(Request $request, string $uuid): JsonResponse
    {
        $company = $request->attributes->get('api_company');

        $account = Account::where('company_id', $company->id)
            ->whereNull('parent_account_id')
            ->where('status', 'active')
            ->first();

        $pr = PaymentRequest::with(['toAccount.company', 'fromAccount.company', 'transfer'])
            ->where('uuid', $uuid)
            ->where(fn ($q) => $q
                ->where('to_account_id', $account?->id)
                ->orWhere('from_account_id', $account?->id)
            )
            ->first();

        if (! $pr) {
            return response()->json(['error' => 'Payment request not found'], 404);
        }

        return response()->json(['data' => $this->formatRequest($pr, $account)]);
    }

    private function formatRequest(PaymentRequest $pr, ?Account $viewer): array
    {
        $direction = ($viewer && (int) $pr->to_account_id === $viewer->id)
            ? 'incoming'
            : 'outgoing';

        return [
            'uuid'        => $pr->uuid,
            'status'      => $pr->status,
            'direction'   => $direction,
            'kind'        => $pr->kind ?? 'qr_dynamic',
            'amount'      => (int) $pr->amount,
            'currency'    => 'KY',
            'description' => $pr->description,
            'external_reference' => $pr->external_reference,
            'pay_url'     => $pr->status === 'pending' ? $pr->payUrl() : null,
            'expires_at'  => $pr->expires_at?->toIso8601String(),
            'paid_at'     => $pr->paid_at?->toIso8601String(),
            'creditor'    => [
                'account_number' => $pr->toAccount?->account_number,
                'company'        => $pr->toAccount?->company?->name,
            ],
            'payer'       => $pr->fromAccount ? [
                'account_number' => $pr->fromAccount?->account_number,
                'company'        => $pr->fromAccount?->company?->name,
            ] : null,
            'transfer_uuid' => $pr->transfer?->uuid,
            'created_at'    => $pr->created_at?->toIso8601String(),
        ];
    }
}
