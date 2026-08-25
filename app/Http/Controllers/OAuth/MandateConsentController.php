<?php

namespace App\Http\Controllers\OAuth;

use App\Exceptions\OAuthException;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\OAuthService;
use App\Services\PaymentMandateService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * "Consenti a Kosmoshop di addebitare fino a 50 KY per acquisto."
 *
 * La schermata con cui si concede il mandato di pagamento. Sta dietro allo
 * step-up (2FA o passkey, `RequireStepUp`): non è un consenso nascosto in un
 * checkbox durante il checkout, è un'autorizzazione che si dà con la stessa
 * cerimonia con cui si disattiva il 2FA o si crea un token API.
 *
 * Vale la stessa regola della pagina di consenso OAuth: finché client_id e
 * indirizzo di ritorno non sono verificati **non si rimanda niente a nessuno**.
 */
class MandateConsentController extends Controller
{
    private const SESSION_KEY = 'oauth.mandate_pending';

    private const PENDING_TTL_MINUTES = 10;

    public function __construct(
        private readonly OAuthService $oauth,
        private readonly PaymentMandateService $mandates,
    ) {
    }

    /**
     * GET /oauth/mandate
     */
    public function show(Request $request)
    {
        try {
            $client = $this->oauth->client($request->query('client_id'));
        } catch (OAuthException $e) {
            return $this->localError('Applicazione sconosciuta.', $e->getMessage());
        }

        $returnUrl = (string) $request->query('return_url', '');

        if (! $this->oauth->isRedirectUriAllowed($client, $returnUrl)) {
            return $this->localError(
                'Indirizzo di ritorno non autorizzato.',
                "L'applicazione ha chiesto di tornare a un indirizzo che non è nella sua lista."
            );
        }

        $account = $this->resolveAccount($request->user());

        if (! $account) {
            return $this->localError(
                'Nessun conto KY disponibile.',
                'Per autorizzare i pagamenti serve un conto attivo nel circuito.'
            );
        }

        // Il venditore per cui l'applicazione chiede l'autorizzazione: entra
        // nella lista solo adesso, con l'utente che sta guardando la schermata.
        $seller        = (string) $request->query('seller', '');
        $sellerAccount = $seller !== ''
            ? Account::where('uuid', $seller)->where('owner_type', 'company')->with('company')->first()
            : null;

        $request->session()->put(self::SESSION_KEY, [
            'client_id'  => $client['client_id'],
            'user_id'    => $request->user()->id,
            'account_id' => $account->id,
            'return_url' => $returnUrl,
            'seller'     => $sellerAccount?->uuid,
            'issued_at'  => now()->toIso8601String(),
        ]);

        return view('oauth.mandate', [
            'clientName'    => $client['name'] ?? $client['client_id'],
            'account'       => $account,
            'sellerAccount' => $sellerAccount,
            'defaultCap'    => (int) config('oauth.mandate.default_max_per_transaction', 5000),
            'minCap'        => (int) config('oauth.mandate.min_max_per_transaction', 100),
            'maxCap'        => (int) config('oauth.mandate.max_max_per_transaction', 100000),
            'months'        => (int) config('oauth.mandate.expires_months', 12),
        ]);
    }

    /**
     * POST /oauth/mandate — l'utente autorizza.
     */
    public function store(Request $request): RedirectResponse
    {
        $pending = $this->pending($request);

        if ($pending === null) {
            return redirect()->route('portal.dashboard')
                ->with('status', 'La richiesta di autorizzazione è scaduta. Riprova dall\'applicazione.');
        }

        $validated = $request->validate([
            // Sia "50.00" sia "50,50": la virgola è come scrive la gente qui, e
            // `numeric` la scarterebbe in silenzio — è già successo una volta
            // sul prezzo dei prodotti shop (12/08), non succede più.
            'max_per_transaction' => ['required', 'string', 'regex:/^\\d{1,7}([.,]\\d{1,2})?$/'],
        ]);

        $cap = ky_to_cents((string) $validated['max_per_transaction']);

        $min = (int) config('oauth.mandate.min_max_per_transaction', 100);
        $max = (int) config('oauth.mandate.max_max_per_transaction', 100000);

        if ($cap < $min || $cap > $max) {
            return back()->withErrors([
                'max_per_transaction' => 'Il tetto deve essere compreso fra ' . ky_format($min) . ' e ' . ky_format($max) . ' KY.',
            ]);
        }

        $client  = $this->oauth->client($pending['client_id']);
        $account = Account::findOrFail($pending['account_id']);

        $mandate = $this->mandates->grant(
            $request->user(),
            $account,
            $client['client_id'],
            $cap,
            array_filter([$pending['seller'] ?? null]),
            $request->ip(),
        );

        $request->session()->forget(self::SESSION_KEY);

        return redirect()->away($this->appendQuery($pending['return_url'], [
            'mandate' => 'granted',
        ]));
    }

    /**
     * DELETE /oauth/mandate — l'utente dice no.
     */
    public function deny(Request $request): RedirectResponse
    {
        $pending = $this->pending($request);

        if ($pending === null) {
            return redirect()->route('portal.dashboard');
        }

        AuditLog::create([
            'actor_user_id'  => $request->user()->id,
            'event'          => 'mandate.denied',
            'auditable_type' => User::class,
            'auditable_id'   => $request->user()->id,
            'ip_address'     => $request->ip(),
            'context'        => ['client_id' => $pending['client_id']],
        ]);

        $request->session()->forget(self::SESSION_KEY);

        return redirect()->away($this->appendQuery($pending['return_url'], [
            'mandate' => 'denied',
        ]));
    }

    // =========================================================================

    /**
     * @return array<string, mixed>|null
     */
    private function pending(Request $request): ?array
    {
        $pending = $request->session()->get(self::SESSION_KEY);

        if (! is_array($pending)) {
            return null;
        }

        if ((int) ($pending['user_id'] ?? 0) !== (int) $request->user()->id) {
            $request->session()->forget(self::SESSION_KEY);

            return null;
        }

        if (Carbon::parse($pending['issued_at'] ?? '1970-01-01')->addMinutes(self::PENDING_TTL_MINUTES)->isPast()) {
            $request->session()->forget(self::SESSION_KEY);

            return null;
        }

        return $pending;
    }

    /**
     * Stessa risoluzione del resto del portale: sottoconto → conto azienda →
     * conto personale.
     */
    private function resolveAccount(User $user): ?Account
    {
        if ($user->managed_account_id !== null) {
            $sub = Account::find($user->managed_account_id);

            return $sub?->parentAccount ?? $sub;
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

    private function localError(string $title, string $detail)
    {
        return response()->view('oauth.error', [
            'title'  => $title,
            'detail' => $detail,
        ], 400);
    }

    /**
     * @param array<string, string> $params
     */
    private function appendQuery(string $url, array $params): string
    {
        $separator = str_contains($url, '?') ? '&' : '?';

        return $url . $separator . http_build_query($params);
    }
}
