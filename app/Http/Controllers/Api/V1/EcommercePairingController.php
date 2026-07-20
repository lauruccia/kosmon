<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\EcommercePairing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Collegamento del plugin e-commerce tramite il solo numero di conto.
 *
 * Endpoint PUBBLICI (il plugin non ha ancora un token API): protetti da
 * rate limiting stretto e dal claim_secret generato dal plugin. Nessuna
 * credenziale viene emessa senza l'approvazione esplicita dell'admin del
 * circuito da /admin/companies/{id}.
 */
class EcommercePairingController extends Controller
{
    /**
     * POST /api/v1/ecommerce/pairings
     * Il plugin chiede il collegamento: numero di conto + URL sito + URL
     * webhook + claim_secret (che il server salva solo come hash).
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            // max:32 e non 16: l'input può contenere spazi/minuscole, viene
            // normalizzato subito sotto prima della validazione di formato.
            'account_number' => ['required', 'string', 'max:32'],
            'site_url'       => ['required', 'url', 'max:500'],
            'webhook_url'    => ['required', 'url', 'max:500'],
            'claim_secret'   => ['required', 'string', 'min:20', 'max:64'],
            'platform'       => ['nullable', 'string', 'max:30'],
        ]);

        $accountNumber = strtoupper(preg_replace('/\s+/', '', $data['account_number']));

        if (! Account::hasKyAccountNumber($accountNumber)) {
            return response()->json([
                'error' => 'Numero di conto non valido: il formato è KYB (o KYP) seguito da 13 caratteri.',
            ], 422);
        }

        $account = Account::query()
            ->where('uuid', $accountNumber)
            ->where('is_system_account', false)
            ->whereNotNull('company_id')
            ->first();

        if (! $account) {
            return response()->json([
                'error' => 'Nessun conto KMoney trovato con questo numero. Controlla il numero di conto o contatta l\'assistenza del circuito.',
            ], 422);
        }

        // Una sola richiesta pendente per sito+conto: le precedenti vengono
        // sostituite (es. il negoziante ha reinstallato il plugin).
        EcommercePairing::query()
            ->where('account_id', $account->id)
            ->where('site_url', $data['site_url'])
            ->where('status', EcommercePairing::STATUS_PENDING)
            ->delete();

        $pairing = EcommercePairing::create([
            'company_id'        => $account->company_id,
            'account_id'        => $account->id,
            'account_number'    => $accountNumber,
            'site_url'          => $data['site_url'],
            'webhook_url'       => $data['webhook_url'],
            'platform'          => $data['platform'] ?? 'woocommerce',
            'claim_secret_hash' => hash('sha256', $data['claim_secret']),
            'status'            => EcommercePairing::STATUS_PENDING,
            'created_ip'        => $request->ip(),
        ]);

        AuditLog::create([
            'actor_user_id'  => null,
            'event'          => 'ecommerce.pairing_requested',
            'auditable_type' => EcommercePairing::class,
            'auditable_id'   => $pairing->id,
            'ip_address'     => $request->ip(),
            'context'        => [
                'company_id'     => $pairing->company_id,
                'account_number' => $accountNumber,
                'site_url'       => $pairing->site_url,
                'platform'       => $pairing->platform,
            ],
        ]);

        return response()->json([
            'uuid'    => $pairing->uuid,
            'status'  => $pairing->status,
            'message' => 'Richiesta di collegamento registrata. L\'amministratore del circuito KMoney deve approvarla: riprova la verifica più tardi.',
        ], 201);
    }

    /**
     * GET /api/v1/ecommerce/pairings/{uuid}?claim_secret=...
     * Il plugin verifica lo stato. Alla prima verifica dopo l'approvazione
     * riceve token API e secret webhook UNA SOLA VOLTA.
     */
    public function show(Request $request, string $uuid): JsonResponse
    {
        $claimSecret = (string) $request->query('claim_secret', '');

        $pairing = EcommercePairing::query()->where('uuid', $uuid)->first();

        // Stessa risposta per "non esiste" e "segreto errato": nessun oracolo.
        if (! $pairing || $claimSecret === '' || ! $pairing->matchesClaimSecret($claimSecret)) {
            return response()->json(['error' => 'Collegamento non trovato.'], 404);
        }

        $response = ['uuid' => $pairing->uuid, 'status' => $pairing->status];

        if ($pairing->status === EcommercePairing::STATUS_APPROVED) {
            if ($pairing->claimed_at === null && is_array($pairing->credentials)) {
                // Consegna una tantum delle credenziali, poi azzeramento.
                $response['api_token']      = $pairing->credentials['api_token'] ?? null;
                $response['webhook_secret'] = $pairing->credentials['webhook_secret'] ?? null;

                $pairing->forceFill([
                    'credentials' => null,
                    'claimed_at'  => now(),
                ])->save();

                AuditLog::create([
                    'actor_user_id'  => null,
                    'event'          => 'ecommerce.pairing_claimed',
                    'auditable_type' => EcommercePairing::class,
                    'auditable_id'   => $pairing->id,
                    'ip_address'     => $request->ip(),
                    'context'        => [
                        'company_id' => $pairing->company_id,
                        'site_url'   => $pairing->site_url,
                    ],
                ]);
            } else {
                $response['claimed'] = true;
            }
        }

        return response()->json($response);
    }
}
