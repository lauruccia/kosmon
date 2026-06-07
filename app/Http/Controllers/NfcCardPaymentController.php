<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Company;
use App\Models\NfcCard;
use App\Models\NfcCardAuthSession;
use App\Notifications\NfcCardPinRequestNotification;
use App\Services\TransferBookingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\View\View;

/**
 * Flusso pagamento con card NFC fisica (Opzione A).
 *
 * MERCHANT (incassa):
 *   1. POST /nfc/card/identify       — legge UUID+sig dal chip, verifica HMAC, ottiene owner info
 *   2. POST /nfc/card/request        — invia importo, crea NfcCardAuthSession, invia push al cliente
 *   3. GET  /nfc/card/status/{nonce} — polling stato autorizzazione
 *
 * CLIENTE (conferma dal proprio telefono):
 *   4. GET  /nfc/card/authorize/{nonce} — pagina di conferma (via URL firmato o sessione)
 *   5. POST /nfc/card/authorize/{nonce} — conferma pagamento (senza PIN, auth via URL firmato)
 *
 * LANDING card:
 *   GET  /nfc/{uuid}             — pagina intermedia se il telefono del merchant
 *                                   apre l'URL NFC direttamente (senza NDEFReader attivo)
 *
 * NOTA: le route authorize sono fuori dal middleware auth — gestiscono autonomamente
 *       sia l'accesso tramite sessione attiva sia tramite URL firmato temporaneo.
 */
class NfcCardPaymentController extends Controller
{
    // ─── Merchant: identifica card ───────────────────────────────────────────

    /**
     * POST /nfc/card/identify
     * Riceve uuid+sig letti dal chip, verifica HMAC, restituisce info owner.
     */
    public function identify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'uuid' => ['required', 'string', 'size:36'],
            'sig'  => ['required', 'string'],
        ]);

        // Verifica firma HMAC
        if (! NfcCard::verifyHmac($data['uuid'], $data['sig'])) {
            return response()->json(['error' => 'Firma card non valida.'], 422);
        }

        $card = NfcCard::with('company')->where('uuid', $data['uuid'])->first();

        if (! $card) {
            return response()->json(['error' => 'Card non trovata.'], 404);
        }

        if ($card->isRevoked()) {
            $card->logs()->create(['event' => 'revoked', 'ip' => $request->ip()]);
            return response()->json(['error' => 'Questa card è stata revocata.'], 403);
        }

        if ($card->isBlocked()) {
            $card->logs()->create(['event' => 'blocked', 'ip' => $request->ip()]);
            return response()->json(['error' => 'Questa card è bloccata dal titolare.'], 403);
        }

        if (! $card->isActive()) {
            return response()->json(['error' => 'Card non ancora attiva.'], 403);
        }

        $card->logs()->create(['event' => 'tap', 'ip' => $request->ip()]);

        return response()->json([
            'card_uuid'    => $card->uuid,
            'owner_name'   => $card->company->name,
            'card_label'   => $card->serial_number ?? substr($card->uuid, 0, 8),
            'limits'       => [
                'per_transaction' => $card->limit_per_transaction,
                'daily'           => $card->limit_daily,
                'monthly'         => $card->limit_monthly,
                'daily_spent'     => $card->daily_spent,
                'monthly_spent'   => $card->monthly_spent,
            ],
        ]);
    }

    /**
     * POST /nfc/card/request
     * Merchant invia importo → crea sessione auth → notifica cliente.
     */
    public function createRequest(Request $request): JsonResponse
    {
        $request->merge(['amount' => str_replace(',', '.', (string) $request->input('amount'))]);

        $data = $request->validate([
            'card_uuid'   => ['required', 'string'],
            'amount'      => ['required', 'numeric', 'min:0.01', 'max:9999999'],
            'description' => ['nullable', 'string', 'max:200'],
        ]);

        $amountCents = ky_to_cents($data['amount']);

        $card = NfcCard::with('company.users')->where('uuid', $data['card_uuid'])->firstOrFail();

        if (! $card->isActive()) {
            return response()->json(['error' => 'Card non attiva.'], 403);
        }

        // Verifica limiti
        [$ok, $reason] = $card->checkLimits($amountCents);
        if (! $ok) {
            $card->logs()->create(['event' => 'limit_exceeded', 'amount' => $amountCents, 'ip' => $request->ip()]);
            return response()->json(['error' => $reason], 422);
        }

        // Risolvi account merchant (supporta KYB con company_id e KYP con owner_user_id)
        $merchantAccount = $this->resolveMerchantAccount($request->user());

        if (! $merchantAccount) {
            return response()->json(['error' => 'Nessun conto attivo associato al tuo account.'], 403);
        }

        // Annulla sessioni pending per la stessa card O per lo stesso merchant
        // (evita notifiche duplicate/push parallele per lo stesso operatore)
        NfcCardAuthSession::where('status', 'pending')
            ->where('expires_at', '>', now())
            ->where(function ($q) use ($card, $merchantAccount) {
                $q->where('nfc_card_id', $card->id)
                  ->orWhere('merchant_account_id', $merchantAccount->id);
            })
            ->update(['status' => 'cancelled']);

        // Crea sessione auth (scade in 10 minuti — tempo sufficiente per login se necessario)
        $session = NfcCardAuthSession::create([
            'nfc_card_id'          => $card->id,
            'merchant_company_id'  => $merchantAccount->company_id,
            'merchant_account_id'  => $merchantAccount->id,
            'amount'               => $amountCents,
            'description'          => $data['description'] ?? null,
            'status'               => 'pending',
            'expires_at'           => now()->addMinutes(10),
        ]);

        // URL firmato (valido 10 min) — funziona anche senza sessione attiva
        $signedUrl = URL::temporarySignedRoute(
            'nfc.card.authorize',
            now()->addMinutes(10),
            ['nonce' => $session->nonce],
        );

        // Notifica tutti gli utenti della company titolare della card
        try {
            $merchant     = $merchantAccount->company ?? Company::find($merchantAccount->company_id);
            $pushService  = app(\App\Services\WebPushService::class);
            $pushTitle    = 'Richiesta di pagamento';
            $pushBody     = sprintf('%s richiede %s KY. Tocca per confermare.',
                $merchant?->name ?? 'Un commerciante',
                ky_format($session->amount),
            );

            $card->company->users->each(function ($user) use ($session, $merchant, $signedUrl, $pushService, $pushTitle, $pushBody) {
                // Notifica database + mail + broadcast (via coda)
                $user->notify(new NfcCardPinRequestNotification($session, $merchant, $signedUrl));

                // Push inviato direttamente dal contesto web (evita limitazioni CLI del cron)
                $pushService->notifyUser($user, $pushTitle, $pushBody, [
                    'url' => $signedUrl,
                    'tag' => 'nfc-payment-request',
                ]);
            });
        } catch (\Throwable $e) {
            \Log::warning('NFC card notification failed: ' . $e->getMessage());
        }

        return response()->json([
            'nonce'      => $session->nonce,
            'expires_at' => $session->expires_at->toISOString(),
            'status_url' => route('nfc.card.status', $session->nonce),
        ]);
    }

    /**
     * GET /nfc/card/status/{nonce}
     * Polling merchant: stato corrente della sessione.
     */
    public function status(Request $request, string $nonce): JsonResponse
    {
        $session = NfcCardAuthSession::where('nonce', $nonce)->firstOrFail();

        // Scadenza automatica
        if ($session->isExpired()) {
            $session->update(['status' => 'expired']);
        }

        return response()->json([
            'status'        => $session->status,
            'is_authorized' => $session->status === 'authorized',
            'is_expired'    => in_array($session->status, ['expired', 'cancelled']),
            'transfer_uuid' => $session->transfer_uuid,
            'seconds_left'  => max(0, now()->diffInSeconds($session->expires_at, false)),
        ]);
    }

    // ─── Cliente: autorizza pagamento ────────────────────────────────────────

    /**
     * GET /nfc/card/authorize/{nonce}
     * Pagina conferma pagamento — accessibile via URL firmato (push notification)
     * oppure tramite sessione autenticata normale.
     */
    public function authorizeForm(Request $request, string $nonce): View|RedirectResponse
    {
        $session = NfcCardAuthSession::with(['card.company', 'merchant', 'merchantAccount'])
            ->where('nonce', $nonce)
            ->firstOrFail();

        if ($session->isExpired()) {
            $session->update(['status' => 'expired']);
            return redirect()->route('login')
                ->with('portal_error', 'La richiesta di pagamento è scaduta.');
        }

        abort_unless($session->status === 'pending', 403, 'Richiesta già processata.');

        // Autenticazione: URL firmato oppure sessione attiva
        if ($request->hasValidSignature()) {
            // Accesso da notifica push — auto-login del titolare della card
            $this->autoLoginCardOwner($session);
        } elseif ($user = $request->user()) {
            // Sessione attiva — verifica che sia il titolare della card
            abort_unless($session->card->company_id === $user->company_id, 403);
        } else {
            // Né URL firmato né sessione → redirect al login con intended
            return redirect()->route('login');
        }

        return view('portal.nfc-cards.authorize', [
            'pageTitle' => 'Conferma pagamento',
            'session'   => $session,
            'activeNav' => 'nfc-cards',
        ]);
    }

    /**
     * POST /nfc/card/authorize/{nonce}
     * Conferma pagamento — senza PIN, autenticazione garantita da URL firmato o sessione.
     */
    public function authorize(Request $request, string $nonce, TransferBookingService $svc): RedirectResponse
    {
        $session = NfcCardAuthSession::with(['card', 'merchant', 'merchantAccount'])
            ->where('nonce', $nonce)
            ->firstOrFail();

        abort_unless($session->isPending(), 403, 'Sessione non valida o scaduta.');

        $user = $request->user();
        abort_unless($user && $session->card->company_id === $user->company_id, 403);

        // Risolvi account cliente (titolare della card)
        $customerAccount = Account::where('company_id', $user->company_id)
            ->whereNull('parent_account_id')
            ->firstOrFail();

        // Risolvi account merchant
        $merchantAccount = $session->merchant_account_id
            ? Account::findOrFail($session->merchant_account_id)
            : Account::where('company_id', $session->merchant_company_id)
                ->whereNull('parent_account_id')
                ->firstOrFail();

        try {
            // Wrap in transazione con lock sulla card per evitare race condition
            // tra checkLimits e recordSpent su pagamenti concorrenti.
            $transfer = DB::transaction(function () use ($session, $customerAccount, $merchantAccount, $user, $nonce, $request, $svc) {
                /** @var NfcCard $card */
                $card = NfcCard::lockForUpdate()->findOrFail($session->nfc_card_id);

                // Verifica limiti con lock acquisito
                [$ok, $reason] = $card->checkLimits($session->amount);
                if (! $ok) {
                    $card->logs()->create(['event' => 'limit_exceeded', 'amount' => $session->amount, 'ip' => $request->ip()]);
                    throw new \RuntimeException('__LIMIT__:' . $reason);
                }

                $transfer = $svc->book([
                    'from_account_id' => $customerAccount->id,
                    'to_account_id'   => $merchantAccount->id,
                    'amount'          => $session->amount,
                    'description'     => $session->description ?? 'Pagamento Card NFC',
                    'kind'            => 'nfc_card',
                    'idempotency_key' => 'nfc-card-' . $nonce,
                    'initiated_by'    => $user->id,
                    'ip_address'      => $request->ip(),
                ]);

                $session->update([
                    'status'        => 'authorized',
                    'transfer_uuid' => $transfer->uuid,
                ]);

                $card->recordSpent($session->amount);
                $card->logs()->create([
                    'event'               => 'payment_ok',
                    'merchant_company_id' => $session->merchant_company_id,
                    'amount'              => $session->amount,
                    'ip'                  => $request->ip(),
                ]);

                return $transfer;
            });

        } catch (\Throwable $e) {
            $errorMsg = $e->getMessage();

            // Distingui errore limite (messaggio utente) da errore generico
            if (str_starts_with($errorMsg, '__LIMIT__:')) {
                $session->update(['status' => 'failed']);
                return back()->with('portal_error', substr($errorMsg, 10));
            }

            $session->update(['status' => 'failed']);
            $session->card->logs()->create(['event' => 'payment_fail', 'notes' => $errorMsg]);

            return back()->with('portal_error', 'Pagamento fallito: ' . $errorMsg);
        }

        $merchantName = $session->merchant?->name
            ?? $session->merchantAccount?->display_name
            ?? 'Commerciante';

        return redirect()->route('portal.dashboard')
            ->with('portal_success', 'Pagamento di ' . ky_format($session->amount) . ' KY a ' . $merchantName . ' autorizzato.');
    }

    /**
     * Auto-login del titolare della card quando accede via URL firmato (push notification).
     * Bypassa solo l'autenticazione di sessione — il flusso 2FA/onboarding non viene toccato
     * perché la route è fuori dal gruppo middleware.
     */
    private function autoLoginCardOwner(NfcCardAuthSession $session): void
    {
        if (Auth::check()) {
            return; // già loggato
        }

        $owner = $session->card->company
            ?->users()
            ->where('role', 'owner')
            ->where('is_active', true)
            ->first();

        if ($owner) {
            // remember: false — autorizzare un pagamento non equivale a un login volontario;
            // non vogliamo persistere un cookie "ricordami" sul dispositivo del cliente.
            Auth::login($owner, remember: false);
        }
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Risolve l'account principale del merchant loggato.
     * Supporta KYB (company_id), KYP (owner_user_id) e sub-account manager.
     */
    private function resolveMerchantAccount(\App\Models\User $user): ?Account
    {
        if ($user->managed_account_id !== null) {
            $sub = Account::with('parentAccount')->find($user->managed_account_id);
            return $sub ? ($sub->parentAccount ?? $sub) : null;
        }

        if ($user->company_id !== null) {
            return Account::where('company_id', $user->company_id)
                ->whereNull('parent_account_id')
                ->orderBy('id')
                ->first();
        }

        return Account::where('owner_user_id', $user->id)
            ->whereNull('parent_account_id')
            ->orderBy('id')
            ->first();
    }

    // ─── Landing NFC (apertura URL dal chip) ─────────────────────────────────

    /**
     * GET /nfc/{uuid}
     * Se il telefono del merchant apre l'URL del chip (senza NDEFReader attivo),
     * mostra pagina che spiega di usare l'app oppure redirige al form incasso.
     */
    public function scanLanding(Request $request, string $uuid): View|RedirectResponse
    {
        $sig  = $request->query('sig', '');

        if (! NfcCard::verifyHmac($uuid, $sig)) {
            abort(403, 'Card non valida.');
        }

        $card = NfcCard::with('company')->where('uuid', $uuid)->firstOrFail();

        $user = $request->user();

        if ($user && ! $user->canAccessBackoffice() && $user->company_id) {
            // Il TITOLARE ha avvicinato la PROPRIA card: non può incassare/pagare
            // se stesso. Lo mando alla gestione della card invece che al form di
            // incasso (che genererebbe il vicolo cieco "paga il tuo stesso conto").
            if ($user->company_id === $card->company_id) {
                return redirect()->route('portal.nfc-cards.show', $card->uuid)
                    ->with('portal_info', 'Questa è la tua card. Per ricevere un pagamento con essa è il commerciante a doverla avvicinare dal proprio dispositivo.');
            }

            // Un commerciante diverso ha avvicinato la card del cliente:
            // flusso di incasso con la card del cliente pre-selezionata.
            return redirect()->route('portal.incasso-nfc.form', ['card_uuid' => $uuid]);
        }

        return view('portal.nfc-cards.scan-landing', [
            'pageTitle' => 'Card NFC KMoney',
            'card'      => $card,
        ]);
    }
}
