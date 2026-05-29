<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\PaymentRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * PaymentLinkController
 *
 * Gestisce i link di pagamento condivisibili (kind = 'link').
 * A differenza del QR dinamico (10 min), questi link hanno scadenza
 * configurabile (default 7 giorni) e possono essere condivisi via
 * WhatsApp, email, o copia link.
 *
 *   GET  /portal/link-pagamento           -> lista link creati dall'utente
 *   GET  /portal/link-pagamento/crea      -> form creazione
 *   POST /portal/link-pagamento           -> store
 *   GET  /portal/link-pagamento/{token}   -> pagina condivisione (QR + pulsanti)
 *   POST /portal/link-pagamento/{token}/annulla -> cancella link
 */
class PaymentLinkController extends Controller
{
    /** Lista dei link di pagamento creati dall'utente corrente. */
    public function index(Request $request): View|RedirectResponse
    {
        $user = $request->user();

        if ($user->canAccessBackoffice()) {
            return redirect()->route('admin.dashboard');
        }

        $account = $this->resolveAccount($user);

        $links = PaymentRequest::where('to_account_id', $account->id)
            ->where('kind', 'link')
            ->latest()
            ->paginate(20)
            ->withQueryString();

        // Aggiorna i link scaduti on-the-fly
        foreach ($links as $link) {
            if ($link->status === 'pending' && $link->expires_at->isPast()) {
                $link->update(['status' => 'expired']);
                $link->status = 'expired';
            }
        }

        return view('portal.payment-links', [
            'pageTitle' => 'Link di pagamento',
            'links'     => $links,
            'account'   => $account,
            'activeNav' => 'link-pagamento',
        ]);
    }

    /** Form di creazione link. */
    public function create(Request $request): View|RedirectResponse
    {
        $user = $request->user();

        if ($user->canAccessBackoffice()) {
            return redirect()->route('admin.dashboard');
        }

        $account = $this->resolveAccount($user);

        if ($account->status !== 'active') {
            return redirect()->route('portal.payment-links.index')
                ->with('portal_error', 'Il tuo conto non è attivo. Non puoi creare link di pagamento.');
        }

        return view('portal.payment-link-create', [
            'pageTitle' => 'Nuovo link di pagamento',
            'account'   => $account,
            'activeNav' => 'link-pagamento',
        ]);
    }

    /** Crea il link e mostra la pagina di condivisione. */
    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user->canAccessBackoffice()) {
            return redirect()->route('admin.dashboard');
        }

        $account = $this->resolveAccount($user);

        if ($account->status !== 'active') {
            return back()->with('portal_error', 'Il tuo conto non è attivo.');
        }

        $validated = $request->validate([
            'amount'      => ['required', 'integer', 'min:1', 'max:9999999'],
            'description' => ['nullable', 'string', 'max:200'],
            'expires_days' => ['required', 'integer', 'min:1', 'max:90'],
        ], [
            'amount.required'       => "Inserisci l'importo.",
            'amount.min'            => "L'importo minimo è 1 KY.",
            'expires_days.required' => 'Seleziona la scadenza.',
        ]);

        $pr = PaymentRequest::create([
            'kind'                => 'link',
            'created_by_user_id'  => $user->id,
            'to_account_id'       => $account->id,
            'amount'              => (int) $validated['amount'],
            'description'         => $validated['description'] ?? null,
            'status'              => 'pending',
            'expires_at'          => now()->addDays((int) $validated['expires_days']),
        ]);

        return redirect()->route('portal.payment-links.show', $pr->token);
    }

    /** Pagina di condivisione del link (QR + pulsanti). */
    public function show(Request $request, string $token): View|RedirectResponse
    {
        $user = $request->user();

        if ($user->canAccessBackoffice()) {
            return redirect()->route('admin.dashboard');
        }

        $pr = PaymentRequest::with(['toAccount.company', 'toAccount.ownerUser', 'fromAccount.company'])
            ->where('token', $token)
            ->where('kind', 'link')
            ->firstOrFail();

        $account = $this->resolveAccount($user);
        abort_unless($pr->to_account_id === $account->id, 403);

        // Aggiorna stato scadenza
        if ($pr->status === 'pending' && $pr->expires_at->isPast()) {
            $pr->update(['status' => 'expired']);
            $pr->refresh();
        }

        $payUrl = route('portal.pay-request.show', $pr->token);

        return view('portal.payment-link-show', [
            'pageTitle' => 'Link di pagamento',
            'pr'        => $pr,
            'account'   => $account,
            'payUrl'    => $payUrl,
            'activeNav' => 'link-pagamento',
        ]);
    }

    /** Cancella il link. */
    public function cancel(Request $request, string $token): RedirectResponse
    {
        $user = $request->user();

        $pr = PaymentRequest::where('token', $token)
            ->where('kind', 'link')
            ->firstOrFail();

        $account = $this->resolveAccount($user);
        abort_unless($pr->to_account_id === $account->id, 403);

        if ($pr->status === 'pending') {
            $pr->update(['status' => 'cancelled']);
        }

        return redirect()->route('portal.payment-links.index')
            ->with('portal_success', 'Link di pagamento annullato.');
    }

    // -------------------------------------------------------------------------

    private function resolveAccount(User $user): Account
    {
        if ($user->managed_account_id !== null) {
            $sub = Account::with(['company', 'ownerUser', 'parentAccount'])
                ->findOrFail($user->managed_account_id);
            return $sub->parentAccount ?? $sub;
        }

        if ($user->company_id !== null) {
            return Account::with(['company', 'ownerUser'])
                ->where('company_id', $user->company_id)
                ->whereNull('parent_account_id')
                ->orderBy('id')
                ->firstOrFail();
        }

        return Account::with(['company', 'ownerUser'])
            ->where('owner_user_id', $user->id)
            ->whereNull('parent_account_id')
            ->orderBy('id')
            ->firstOrFail();
    }
}
