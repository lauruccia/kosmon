<?php

namespace App\Http\Controllers;

use App\Http\Requests\SetPaymentPinRequest;
use App\Mail\PaymentReceived;
use App\Mail\PaymentSent;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\SavedBeneficiary;
use App\Models\SystemSetting;
use App\Models\Transfer;
use App\Notifications\PaymentReceivedNotification;
use App\Services\TransferBookingService;
use App\Support\PaymentPin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\View\View;

class SendPaymentController extends PortalController
{
    // ── GET /invia ────────────────────────────────────────────────────────────

    public function show(Request $request): View|RedirectResponse
    {
        if ($redirect = $this->redirectBackofficeUser($request->user())) {
            return $redirect;
        }

        [$currentAccount, $currentUser] = $this->resolveCurrentContext(
            $request->user(),
            $this->requestedCompanyId($request)
        );

        abort_unless(
            $currentAccount->status === 'active' && $request->user()->canSendFromAccount($currentAccount),
            403
        );

        // Beneficiari salvati
        $savedBeneficiaries = SavedBeneficiary::query()
            ->where('owner_account_id', $currentAccount->id)
            ->with(['beneficiaryAccount.company', 'beneficiaryAccount.ownerUser'])
            ->orderByDesc('updated_at')
            ->take(8)
            ->get()
            ->filter(fn($b) => $b->beneficiaryAccount && $b->beneficiaryAccount->status === 'active');

        // Ultimi destinatari (non ancora salvati come beneficiari)
        $savedIds = $savedBeneficiaries->pluck('beneficiary_account_id')->toArray();
        // Escludi kind interni (fee, cashback, credit note, rimborso) per evitare
        // che compaiano conti sistema nella lista rapida destinatari.
        $recentRecipients = Transfer::where('from_account_id', $currentAccount->id)
            ->where('status', 'booked')
            ->whereNotIn('kind', ['portal_fee', 'portal_cashback', 'portal_credit_note', 'portal_refund'])
            ->with('toAccount')
            ->orderByDesc('booked_at')
            ->get()
            ->pluck('toAccount')
            ->filter(fn($a) => $a && !$a->is_system_account && $a->id !== $currentAccount->id)
            ->reject(fn($a) => in_array($a->id, $savedIds))
            ->unique('id')
            ->take(5)
            ->values();

        $settings      = SystemSetting::userLimitDefaults();
        $pinThreshold  = $settings->payment_pin_threshold;
        $hasPin        = !is_null($currentUser->payment_pin_hash);

        return view('portal.invia', [
            'pageTitle'          => 'Invia KY',
            'currentAccount'     => $currentAccount,
            'currentUser'        => $currentUser,
            'savedBeneficiaries' => $savedBeneficiaries,
            'recentRecipients'   => $recentRecipients,
            'pinThreshold'       => $pinThreshold,
            'hasPin'             => $hasPin,
            'activeNav'          => 'conto',
        ]);
    }

    // ── GET /invia/destinatario/{id} (AJAX) ──────────────────────────────────
    // Restituisce dettagli destinatario + flag "primo pagamento"

    public function recipientInfo(Request $request, int $accountId): JsonResponse
    {
        abort_unless($request->expectsJson() || $request->ajax(), 400);

        [$currentAccount] = $this->resolveCurrentContext(
            $request->user(),
            $this->requestedCompanyId($request)
        );

        $recipient = Account::with(['company', 'ownerUser'])
            ->where('status', 'active')
            ->findOrFail($accountId);

        $isFirst = ! Transfer::where('from_account_id', $currentAccount->id)
            ->where('to_account_id', $recipient->id)
            ->where('status', 'booked')
            ->exists();

        $logoUrl = null;
        if ($recipient->owner_type === 'company') {
            $logoUrl = $recipient->company?->logo_url ?? null;
        } else {
            $logoUrl = $recipient->ownerUser?->avatar_url ?? null;
        }

        // Ultimi 3 importi distinti usati verso questo beneficiario
        $recentAmounts = Transfer::where('from_account_id', $currentAccount->id)
            ->where('to_account_id', $recipient->id)
            ->where('status', 'booked')
            ->whereNotIn('kind', ['portal_fee', 'portal_cashback'])
            ->orderByDesc('booked_at')
            ->limit(20)
            ->pluck('amount')
            ->unique()
            ->take(3)
            ->values()
            ->toArray();

        return response()->json([
            'id'             => $recipient->id,
            'name'           => $recipient->display_name,
            'number'         => $recipient->account_number,
            'type'           => $recipient->owner_type ?? 'company',
            'logo_url'       => $logoUrl,
            'is_first'       => $isFirst,
            'recent_amounts' => $recentAmounts,
        ]);
    }

    // ── GET /invia/cerca (AJAX) ───────────────────────────────────────────────

    public function search(Request $request): JsonResponse
    {
        abort_unless($request->expectsJson() || $request->ajax(), 400);

        if ($redirect = $this->redirectBackofficeUser($request->user())) {
            abort(403);
        }

        // Throttle dedicato: max 30 ricerche/minuto per utente
        $throttleKey = 'recipient_search_' . $request->user()->id;
        if (\Illuminate\Support\Facades\RateLimiter::tooManyAttempts($throttleKey, 30)) {
            abort(429, 'Troppe ricerche. Riprova tra poco.');
        }
        \Illuminate\Support\Facades\RateLimiter::hit($throttleKey, 60);

        [$currentAccount] = $this->resolveCurrentContext(
            $request->user(),
            $this->requestedCompanyId($request)
        );

        $q = trim((string) $request->query('q', ''));

        // Minimo 3 caratteri per prevenire enumerazione
        if (mb_strlen($q) < 3) {
            return response()->json([]);
        }

        // Cerca per numero di conto (match esatto) o per nome (substring su nome pubblico)
        // Email e telefono: match esatto (non LIKE) per prevenire enumerazione
        $results = Account::query()
            ->with(['company', 'ownerUser'])
            ->where('status', 'active')
            ->where('is_system_account', false)
            ->whereKeyNot($currentAccount->id)
            ->where(function ($query) use ($q) {
                $query->where('account_name', 'like', "%{$q}%")  // nome personalizzato conto/sottoconto
                      ->orWhereHas('company', fn($c) => $c->where('name', 'like', "%{$q}%"))
                      ->orWhereHas('ownerUser', function ($u) use ($q) {
                          $u->where('name', 'like', "%{$q}%")
                            ->orWhere('email', $q)       // match esatto
                            ->orWhere('phone', $q);      // match esatto
                      })
                      ->orWhere('uuid', $q);  // match esatto sul numero conto (account_number è un accessor su uuid, non una colonna)
            })
            ->limit(10)
            ->get()
            ->map(fn(Account $a) => [
                'id'     => $a->id,
                'name'   => $a->display_name,
                'number' => $a->account_number,
                'type'   => $a->account_type ?? '',
                // email e phone non vengono mai restituiti
            ]);

        return response()->json($results);
    }

    // ── POST /invia/esegui ────────────────────────────────────────────────────

    public function execute(Request $request, TransferBookingService $bookingService): RedirectResponse
    {
        if ($redirect = $this->redirectBackofficeUser($request->user())) {
            return $redirect;
        }

        [$currentAccount, $currentUser] = $this->resolveCurrentContext(
            $request->user(),
            $this->requestedCompanyId($request)
        );

        abort_unless(
            $currentAccount->status === 'active' && $request->user()->canSendFromAccount($currentAccount),
            403
        );

        // Normalizza virgola → punto prima della validazione
        $request->merge(['amount' => str_replace(',', '.', (string) $request->input('amount'))]);

        $validated = $request->validate([
            'to_account_id' => ['required', 'integer', 'exists:accounts,id'],
            'amount'        => ['required', 'numeric', 'min:0.01'],
            'description'   => ['nullable', 'string', 'max:200'],
            'pin'           => ['nullable', 'string', 'size:6', 'regex:/^\d{6}$/'],
        ]);

        $amountCents = ky_to_cents($validated['amount']);

        // ── Verifica PIN se richiesto ─────────────────────────────────────────
        $settings     = SystemSetting::userLimitDefaults();
        $pinThreshold = $settings->payment_pin_threshold;
        $hasPin       = !is_null($currentUser->payment_pin_hash);

        // Se la soglia è configurata e l'importo la supera:
        // - utente SENZA PIN → blocca e chiedi di impostarlo
        // - utente CON PIN   → verifica PIN inserito
        if ($pinThreshold !== null && $amountCents >= (int) $pinThreshold && ! $hasPin) {
            AuditLog::create([
                'actor_user_id'  => $currentUser->id,
                'event'          => 'transfer.rejected',
                'auditable_type' => \App\Models\Transfer::class,
                'auditable_id'   => null,
                'ip_address'     => $request->ip(),
                'context'        => [
                    'reason'          => 'pin_not_set',
                    'from_account_id' => $currentAccount->id,
                    'to_account_id'   => (int) $validated['to_account_id'],
                    'amount'          => $amountCents,
                ],
            ]);
            return back()->withInput()->with(
                'portal_warning',
                'Per inviare importi superiori a ' . ky_format((int) $pinThreshold) . ' KY devi prima impostare un PIN di pagamento. '
                . 'Vai in <a href="' . route('portal.personal-profile.edit') . '" class="underline">Profilo → Sicurezza</a> per configurarlo.'
            );
        }

        if ($hasPin && $pinThreshold !== null && $amountCents >= (int) $pinThreshold) {
            if (empty($validated['pin'])) {
                AuditLog::create([
                    'actor_user_id'  => $currentUser->id,
                    'event'          => 'transfer.rejected',
                    'auditable_type' => \App\Models\Transfer::class,
                    'auditable_id'   => null,
                    'ip_address'     => $request->ip(),
                    'context'        => [
                        'reason'          => 'pin_missing',
                        'from_account_id' => $currentAccount->id,
                        'to_account_id'   => (int) $validated['to_account_id'],
                        'amount'          => $amountCents,
                    ],
                ]);
                return back()->with('portal_error', 'Inserisci il PIN di pagamento per confermare questa transazione.');
            }

            [$pinOk, $pinError] = PaymentPin::verify($currentUser, $validated['pin']);
            if (! $pinOk) {
                AuditLog::create([
                    'actor_user_id'  => $currentUser->id,
                    'event'          => 'transfer.rejected',
                    'auditable_type' => \App\Models\Transfer::class,
                    'auditable_id'   => null,
                    'ip_address'     => $request->ip(),
                    'context'        => [
                        'reason'          => 'pin_wrong',
                        'from_account_id' => $currentAccount->id,
                        'to_account_id'   => (int) $validated['to_account_id'],
                        'amount'          => $amountCents,
                    ],
                ]);
                return back()->with('portal_error', $pinError);
            }
        }

        // ── Esegui il trasferimento ───────────────────────────────────────────
        try {
            $transfer = $bookingService->book([
                'initiated_by'    => $currentUser->id,
                'from_account_id' => $currentAccount->id,
                'to_account_id'   => (int) $validated['to_account_id'],
                'amount'          => $amountCents,
                'description'     => $validated['description'] ?? null,
                'kind'            => 'portal_payment',
                'idempotency_key' => (string) Str::uuid(),
                'ip_address'      => $request->ip(),
            ]);
        } catch (\RuntimeException $e) {
            return back()->withInput()->with('portal_error', $e->getMessage());
        }

        // ── Salva beneficiario automaticamente ───────────────────────────────
        SavedBeneficiary::firstOrCreate([
            'owner_account_id'      => $currentAccount->id,
            'beneficiary_account_id' => $transfer->to_account_id,
        ]);

        // ── Notifiche ─────────────────────────────────────────────────────────
        $toAccount = $transfer->toAccount;
        $toOwner   = $toAccount?->ownerUser ?? $toAccount?->company?->users()->first();

        if ($toOwner) {
            Mail::to($toOwner->email)->queue(new PaymentReceived(
                recipient: $toOwner,
                transfer: $transfer,
                fromAccount: $transfer->fromAccount,
                toAccount: $toAccount,
                balanceAfter: (int) $toAccount->available_balance,
            ));
            $toOwner->notify(new PaymentReceivedNotification(
                transfer: $transfer,
                fromAccount: $transfer->fromAccount,
                toAccount: $toAccount,
            ));
        }

        Mail::to($currentUser->email)->queue(new PaymentSent(
            sender: $currentUser,
            transfer: $transfer,
            fromAccount: $currentAccount,
            toAccount: $toAccount,
            balanceAfter: (int) $currentAccount->available_balance,
        ));

        return redirect()->route('portal.invia.ricevuta', $transfer->uuid);
    }

    // ── GET /invia/ricevuta/{uuid} ────────────────────────────────────────────

    public function receipt(Request $request, string $uuid): View|RedirectResponse
    {
        if ($redirect = $this->redirectBackofficeUser($request->user())) {
            return $redirect;
        }

        $transfer = Transfer::with([
            'fromAccount.company',
            'fromAccount.ownerUser',
            'toAccount.company',
            'toAccount.ownerUser',
            'feeTransfers',
        ])->where('uuid', $uuid)->firstOrFail();

        [$currentAccount, $currentUser] = $this->resolveCurrentContext(
            $request->user(),
            $this->requestedCompanyId($request)
        );

        abort_unless(
            $transfer->from_account_id === $currentAccount->id
            || $transfer->to_account_id === $currentAccount->id
            || $request->user()->canAccessBackoffice(),
            403
        );

        $isOutgoing   = $transfer->from_account_id === $currentAccount->id;
        $counterparty = $isOutgoing ? $transfer->toAccount : $transfer->fromAccount;

        return view('portal.transfer-receipt', [
            'pageTitle'    => 'Ricevuta pagamento',
            'transfer'     => $transfer,
            'currentAccount' => $currentAccount,
            'isOutgoing'   => $isOutgoing,
            'counterparty' => $counterparty,
            'activeNav'    => 'conto',
        ]);
    }

    // ── POST /invia/pin/imposta ───────────────────────────────────────────────
    // Permette all'utente di impostare/cambiare il proprio PIN di pagamento

    public function setPin(SetPaymentPinRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $request->user()->forceFill(['payment_pin_hash' => PaymentPin::hash($validated['pin'])])->save();

        return back()->with('portal_success', 'PIN di pagamento impostato correttamente.');
    }

    // ── POST /invia/pin/rimuovi ───────────────────────────────────────────────

    public function removePin(Request $request): RedirectResponse
    {
        $request->user()->forceFill(['payment_pin_hash' => null])->save();

        return back()->with('portal_success', 'PIN di pagamento rimosso.');
    }
}
