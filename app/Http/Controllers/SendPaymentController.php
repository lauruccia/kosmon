<?php

namespace App\Http\Controllers;

use App\Mail\PaymentReceived;
use App\Mail\PaymentSent;
use App\Models\Account;
use App\Models\SavedBeneficiary;
use App\Models\SystemSetting;
use App\Models\Transfer;
use App\Notifications\PaymentReceivedNotification;
use App\Services\TransferBookingService;
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
        $recentRecipients = Transfer::where('from_account_id', $currentAccount->id)
            ->where('status', 'booked')
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

    // ── GET /invia/cerca (AJAX) ───────────────────────────────────────────────

    public function search(Request $request): JsonResponse
    {
        abort_unless($request->expectsJson() || $request->ajax(), 400);

        if ($redirect = $this->redirectBackofficeUser($request->user())) {
            abort(403);
        }

        [$currentAccount] = $this->resolveCurrentContext(
            $request->user(),
            $this->requestedCompanyId($request)
        );

        $q = strtolower(trim((string) $request->query('q', '')));

        $results = Account::query()
            ->with(['company', 'ownerUser'])
            ->where('status', 'active')
            ->whereKeyNot($currentAccount->id)
            ->where(function ($query) use ($q) {
                $query->whereHas('company', fn($c) => $c->where('name', 'like', "%{$q}%"))
                      ->orWhereHas('ownerUser', fn($u) => $u->where('name', 'like', "%{$q}%")
                          ->orWhere('email', 'like', "%{$q}%")
                          ->orWhere('phone', 'like', "%{$q}%"))
                      ->orWhere('ky_account_number', 'like', "%{$q}%");
            })
            ->limit(10)
            ->get()
            ->map(fn(Account $a) => [
                'id'     => $a->id,
                'name'   => $a->display_name,
                'number' => $a->ky_account_number ?? '',
                'type'   => $a->account_type ?? '',
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
            'pin_hash'      => ['nullable', 'string', 'size:64'],
        ]);

        $amountCents = ky_to_cents($validated['amount']);

        // ── Verifica PIN se richiesto ─────────────────────────────────────────
        $settings     = SystemSetting::userLimitDefaults();
        $pinThreshold = $settings->payment_pin_threshold;
        $hasPin       = !is_null($currentUser->payment_pin_hash);

        if ($hasPin && $pinThreshold !== null && $amountCents >= (int) $pinThreshold) {
            if (empty($validated['pin_hash'])) {
                return back()->with('portal_error', 'Inserisci il PIN di pagamento per confermare questa transazione.');
            }
            if (!hash_equals($currentUser->payment_pin_hash, $validated['pin_hash'])) {
                return back()->with('portal_error', 'PIN di pagamento errato. Riprova.');
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

    public function setPin(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'pin_hash' => ['required', 'string', 'size:64', 'regex:/^[0-9a-f]+$/'],
        ]);

        $request->user()->forceFill(['payment_pin_hash' => $validated['pin_hash']])->save();

        return back()->with('portal_success', 'PIN di pagamento impostato correttamente.');
    }

    // ── POST /invia/pin/rimuovi ───────────────────────────────────────────────

    public function removePin(Request $request): RedirectResponse
    {
        $request->user()->forceFill(['payment_pin_hash' => null])->save();

        return back()->with('portal_success', 'PIN di pagamento rimosso.');
    }
}
