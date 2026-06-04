<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Company;
use App\Models\NfcCard;
use App\Models\NfcCardAuthSession;
use App\Services\TransferBookingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Flusso pagamento con card NFC fisica (Opzione A).
 *
 * MERCHANT (incassa):
 *   1. POST /nfc/card/identify   — legge UUID+sig dal chip, verifica HMAC, ottiene owner info
 *   2. POST /nfc/card/request    — invia importo, crea NfcCardAuthSession, invia push al cliente
 *   3. GET  /nfc/card/status/{nonce} — polling stato autorizzazione
 *
 * CLIENTE (paga):
 *   4. GET  /nfc/card/authorize/{nonce} — pagina PIN
 *   5. POST /nfc/card/authorize/{nonce} — verifica PIN, esegue transfer
 *
 * LANDING card:
 *   GET  /nfc/{uuid}             — pagina intermedia se il telefono del merchant
 *                                   apre l'URL NFC direttamente (senza NDEFReader attivo)
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

        $card = NfcCard::with('company')->where('uuid', $data['card_uuid'])->firstOrFail();

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

        // Crea sessione auth (scade in 3 minuti)
        $session = NfcCardAuthSession::create([
            'nfc_card_id'          => $card->id,
            'merchant_company_id'  => $merchantAccount->company_id,
            'merchant_account_id'  => $merchantAccount->id,
            'amount'               => $amountCents,
            'description'          => $data['description'] ?? null,
            'status'               => 'pending',
            'expires_at'           => now()->addMinutes(3),
        ]);

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
     * Pagina cliente per inserire PIN.
     */
    public function authorizeForm(Request $request, string $nonce): View|RedirectResponse
    {
        $session = NfcCardAuthSession::with(['card.company', 'merchant'])
            ->where('nonce', $nonce)
            ->firstOrFail();

        if ($session->isExpired()) {
            $session->update(['status' => 'expired']);
            return redirect()->route('portal.dashboard')
                ->with('portal_error', 'La richiesta di pagamento è scaduta.');
        }

        abort_unless($session->status === 'pending', 403, 'Richiesta già processata.');

        // Verifica che sia la card del cliente loggato
        $user = $request->user();
        abort_unless($session->card->company_id === $user->company_id, 403);

        return view('portal.nfc-cards.authorize', [
            'pageTitle' => 'Autorizza pagamento',
            'session'   => $session,
            'activeNav' => 'nfc-cards',
        ]);
    }

    /**
     * POST /nfc/card/authorize/{nonce}
     * Cliente verifica PIN → esegue transfer.
     */
    public function authorize(Request $request, string $nonce, TransferBookingService $svc): RedirectResponse
    {
        $session = NfcCardAuthSession::with(['card', 'merchant'])
            ->where('nonce', $nonce)
            ->firstOrFail();

        abort_unless($session->isPending(), 403, 'Sessione non valida o scaduta.');

        $user = $request->user();
        abort_unless($session->card->company_id === $user->company_id, 403);

        $request->validate([
            'pin' => ['required', 'string', 'min:4', 'max:8'],
        ]);

        $card = $session->card;

        // Verifica PIN (con auto-lock dopo 3 tentativi)
        if (! $card->verifyPin($request->pin)) {
            $msg = $card->isPinLocked()
                ? 'Troppi tentativi errati. Card bloccata per 30 minuti.'
                : 'PIN errato. Riprova.';

            return back()->with('portal_error', $msg);
        }

        // Ricontrolla limiti (potrebbero essere cambiati nel frattempo)
        [$ok, $reason] = $card->checkLimits($session->amount);
        if (! $ok) {
            $session->update(['status' => 'failed']);
            return back()->with('portal_error', $reason);
        }

        // Risolvi account cliente
        $customerAccount = Account::where('company_id', $user->company_id)
            ->whereNull('parent_account_id')
            ->firstOrFail();

        // Risolvi account merchant (merchant_account_id se disponibile, altrimenti company_id)
        $merchantAccount = $session->merchant_account_id
            ? Account::findOrFail($session->merchant_account_id)
            : Account::where('company_id', $session->merchant_company_id)
                ->whereNull('parent_account_id')
                ->firstOrFail();

        // Esegui il transfer
        try {
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

            // Aggiorna sessione
            $session->update([
                'status'        => 'authorized',
                'transfer_uuid' => $transfer->uuid,
            ]);

            // Aggiorna contatori card
            $card->recordSpent($session->amount);
            $card->logs()->create([
                'event'               => 'payment_ok',
                'merchant_company_id' => $session->merchant_company_id,
                'amount'              => $session->amount,
                'ip'                  => $request->ip(),
            ]);

        } catch (\Throwable $e) {
            $session->update(['status' => 'failed']);
            $card->logs()->create(['event' => 'payment_fail', 'notes' => $e->getMessage()]);

            return back()->with('portal_error', 'Pagamento fallito: ' . $e->getMessage());
        }

        return redirect()->route('portal.dashboard')
            ->with('portal_success', "Pagamento di {$session->amount} KY a {$session->merchant->name} autorizzato con successo.");
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
