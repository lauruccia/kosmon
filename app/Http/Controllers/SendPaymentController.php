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
use App\Support\PaymentIdempotency;
use App\Support\PaymentPin;
use Illuminate\Database\QueryException;
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
            // 'paga' e non 'conto' (09/09/2026): da quando /paga porta qui,
            // questa E' la voce «Invia KY» del menu, e deve accendersi lei —
            // prima si accendeva «Dashboard» nella barra del telefono.
            'activeNav'          => 'paga',
            // Identifica QUESTO caricamento della pagina. Finisce in un campo
            // nascosto del form e da' la chiave di idempotenza dell'invio: due
            // reinvii dello stesso form portano lo stesso token, un nuovo
            // caricamento ne porta uno diverso. Vedi App\Support\PaymentIdempotency.
            'invioToken'         => PaymentIdempotency::freshToken(),
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
                      // Match esatto sul numero di conto, in qualunque forma
                      // sia scritto e digitato maiuscolo o minuscolo:
                      // Account::scopeWhereAccountNumber() copre anche i conti
                      // il cui numero non e' sulla colonna uuid ma calcolato
                      // dall'id (09/09/2026 — prima quei numeri, che sono
                      // quelli stampati sulla tessera, non trovavano niente).
                      ->orWhere(fn ($n) => $n->whereAccountNumber($q));
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
            'invio_token'   => ['nullable', 'string', 'max:64'],
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
                    // Chi non ha ancora impostato il PIN non sta forzando
                    // niente: non concorre al blocco del conto.
                    'reason_class'    => TransferBookingService::RIFIUTO_CONTABILE,
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
                        // Campo lasciato vuoto: distrazione, non attacco.
                        'reason_class'    => TransferBookingService::RIFIUTO_CONTABILE,
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
                        // SICUREZZA, per scelta di Laura (09/09/2026). Un PIN
                        // sbagliato dice qualcosa su CHI sta operando, quindi
                        // concorre al blocco del conto: tre in cinque minuti e
                        // il conto si ferma per mezz'ora. Conseguenza voluta:
                        // il limite piu' morbido di PaymentPin::verify() (5
                        // tentativi, 15 minuti, e ferma il PIN non il conto)
                        // di fatto non si raggiunge quasi mai — arriva prima
                        // questo. Le altre due voci qui sopra restano
                        // contabili: «non ho il PIN» e «ho lasciato il campo
                        // vuoto» non sono tentativi di indovinare niente.
                        'reason_class'    => TransferBookingService::RIFIUTO_SICUREZZA,
                        'from_account_id' => $currentAccount->id,
                        'to_account_id'   => (int) $validated['to_account_id'],
                        'amount'          => $amountCents,
                    ],
                ]);
                return back()->with('portal_error', $pinError);
            }
        }

        // ── Step-up per importi elevati (09/09/2026) ─────────────────────────
        // La seconda soglia del circuito (payment_confirm_totp_threshold)
        // viveva solo sul flusso /paga: chi passava di qui — cioe' dal pulsante
        // grande della dashboard, che e' la strada che fanno quasi tutti — non
        // se la vedeva chiedere mai. Due porte sulla stessa stanza con due
        // serrature diverse: bastava usare l'altra.
        //
        // Al ritorno dalla verifica si rientra su /invia con destinatario,
        // importo e causale gia' compilati (la pagina li rilegge dalla query
        // string), cosi' la sicurezza non si paga ribattendo tutto.
        $totpThreshold = $settings->payment_confirm_totp_threshold;

        if ($totpThreshold !== null
            && $amountCents >= (int) $totpThreshold
            && ! \App\Http\Middleware\RequireStepUp::isVerified($request)) {

            $request->session()->put('step_up_return_url', route('portal.invia', array_filter([
                'to'     => (int) $validated['to_account_id'],
                'amount' => ky_input($amountCents),
                'desc'   => $validated['description'] ?? null,
            ])));

            return redirect()->route('portal.step-up.show')
                ->with('step_up_reason', 'Per importi elevati devi confermare la tua identità prima di procedere.');
        }

        // ── Esegui il trasferimento ───────────────────────────────────────────
        $idempotencyKey = PaymentIdempotency::forForm('invia', $currentAccount, $validated['invio_token'] ?? null, [
            (int) $validated['to_account_id'],
            $amountCents,
            $validated['description'] ?? '',
        ]);

        // Invio gia' registrato (tasto indietro, reinvio del form, retry di
        // rete): si mostra la ricevuta di QUELLO, dicendolo. Senza questo ramo
        // book() restituirebbe in silenzio il transfer vecchio e l'utente
        // vedrebbe una ricevuta identica a un pagamento nuovo — e partirebbero
        // di nuovo le due email.
        if ($giaInviato = Transfer::where('idempotency_key', $idempotencyKey)->first()) {
            return redirect()->route('portal.invia.ricevuta', $giaInviato->uuid)
                ->with('portal_warning', 'Questo pagamento era gia\' stato inviato: qui sotto la ricevuta. Non e\' stato addebitato una seconda volta.');
        }

        try {
            $transfer = $bookingService->book([
                'initiated_by'    => $currentUser->id,
                'from_account_id' => $currentAccount->id,
                'to_account_id'   => (int) $validated['to_account_id'],
                'amount'          => $amountCents,
                'description'     => $validated['description'] ?? null,
                'kind'            => 'portal_payment',
                'idempotency_key' => $idempotencyKey,
                'ip_address'      => $request->ip(),
            ]);
        } catch (\RuntimeException $e) {
            return back()->withInput()->with('portal_error', $e->getMessage());
        } catch (QueryException $e) {
            // Due richieste identiche arrivate nello STESSO istante: il
            // controllo qui sopra le ha viste entrambe come "mai inviato" e
            // l'indice UNIQUE su transfers.idempotency_key ha fermato la
            // seconda. E' il vincolo che fa il suo lavoro, non un guasto — ma
            // solo se la riga vincente esiste davvero.
            $vincente = Transfer::where('idempotency_key', $idempotencyKey)->first();

            if (! $vincente) {
                throw $e;
            }

            return redirect()->route('portal.invia.ricevuta', $vincente->uuid)
                ->with('portal_warning', 'Questo pagamento era gia\' stato inviato: qui sotto la ricevuta. Non e\' stato addebitato una seconda volta.');
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
