<?php

namespace App\Http\Controllers\OAuth;

use App\Exceptions\OAuthException;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\OAuthService;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;

/**
 * La pagina di consenso di "Accedi con KMoney".
 *
 * Sta dietro alla catena di middleware del portale (`auth`, `verified`,
 * `twofactor`, `onboarding`, `contract`, `not.suspended`): è il punto in cui il
 * piano dello shop esterno prometteva "zero logica duplicata". Un'azienda
 * sospesa, senza email verificata o senza contratto firmato non arriva
 * nemmeno a vedere questa schermata, quindi non può entrare in Kosmoshop —
 * senza che Kosmoshop debba sapere niente di tutto ciò.
 *
 * Regola di sicurezza che governa la struttura del metodo show():
 * finché non abbiamo verificato client_id E redirect_uri **non si rimanda
 * niente a nessuno**, si mostra un errore qui. Rimandare un errore a una
 * redirect_uri non verificata è esattamente come farsi usare come trampolino.
 */
class AuthorizationController extends Controller
{
    /** Quanto resta valida una richiesta di autorizzazione in attesa di consenso. */
    private const PENDING_TTL_MINUTES = 10;

    private const SESSION_KEY = 'oauth.pending';

    public function __construct(private readonly OAuthService $oauth)
    {
    }

    /**
     * GET /oauth/authorize — mostra la schermata di consenso.
     */
    public function show(Request $request)
    {
        // ── 1. Il client esiste? ──────────────────────────────────────────
        try {
            $client = $this->oauth->client($request->query('client_id'));
        } catch (OAuthException $e) {
            return $this->localError('Applicazione sconosciuta.', $e->getMessage());
        }

        // ── 2. L'indirizzo di ritorno è fra quelli autorizzati? ───────────
        $redirectUri = (string) $request->query('redirect_uri', '');

        if (! $this->oauth->isRedirectUriAllowed($client, $redirectUri)) {
            return $this->localError(
                'Indirizzo di ritorno non autorizzato.',
                "L'applicazione ha chiesto di tornare a un indirizzo che non è nella sua lista."
            );
        }

        // ── 3. Da qui in poi gli errori possono tornare al client ─────────
        $state = (string) $request->query('state', '');

        if ($state === '') {
            return $this->redirectError($redirectUri, 'invalid_request', 'Parametro state obbligatorio.', $state);
        }

        if ($request->query('response_type') !== 'code') {
            return $this->redirectError($redirectUri, 'unsupported_response_type', 'Solo response_type=code.', $state);
        }

        $challenge = (string) $request->query('code_challenge', '');
        $method    = (string) $request->query('code_challenge_method', '');

        if ($challenge === '') {
            return $this->redirectError($redirectUri, 'invalid_request', 'PKCE obbligatorio: manca code_challenge.', $state);
        }

        if ($method !== 'S256') {
            return $this->redirectError($redirectUri, 'invalid_request', 'PKCE accettato solo nella variante S256.', $state);
        }

        try {
            $scopes = $this->oauth->validateScopes($client, $request->query('scope'));
        } catch (OAuthException $e) {
            return $this->redirectError($redirectUri, $e->error, $e->getMessage(), $state);
        }

        // ── 4. La richiesta è valida: la mettiamo da parte e chiediamo ────
        // I parametri NON viaggiano in campi nascosti del form: restano in
        // sessione, così non c'è niente da manomettere fra la domanda e la
        // risposta dell'utente.
        $request->session()->put(self::SESSION_KEY, [
            'client_id'             => $client['client_id'],
            'user_id'               => $request->user()->id,
            'redirect_uri'          => $redirectUri,
            'scopes'                => $scopes,
            'state'                 => $state,
            'code_challenge'        => $challenge,
            'code_challenge_method' => $method,
            'issued_at'             => now()->toIso8601String(),
        ]);

        return view('oauth.authorize', [
            'clientName'  => $client['name'] ?? $client['client_id'],
            'scopes'      => $scopes,
            'scopeLabels' => (array) config('oauth.scopes', []),
            'user'        => $request->user(),
        ]);
    }

    /**
     * POST /oauth/authorize — l'utente ha detto sì.
     */
    public function approve(Request $request): RedirectResponse
    {
        $pending = $this->pending($request);

        if ($pending === null) {
            return redirect()->route('portal.dashboard')
                ->with('status', 'La richiesta di collegamento è scaduta. Riprova dall\'applicazione.');
        }

        $client = $this->oauth->client($pending['client_id']);

        $code = $this->oauth->issueAuthorizationCode(
            $client,
            $request->user(),
            $pending['redirect_uri'],
            $pending['scopes'],
            $pending['code_challenge'],
            $pending['code_challenge_method'],
            $request->ip(),
        );

        $request->session()->forget(self::SESSION_KEY);

        return redirect()->away($this->appendQuery($pending['redirect_uri'], [
            'code'  => $code,
            'state' => $pending['state'],
        ]));
    }

    /**
     * DELETE /oauth/authorize — l'utente ha detto no.
     */
    public function deny(Request $request): RedirectResponse
    {
        $pending = $this->pending($request);

        if ($pending === null) {
            return redirect()->route('portal.dashboard');
        }

        AuditLog::create([
            'actor_user_id'  => $request->user()->id,
            'event'          => 'oauth.consent_denied',
            'auditable_type' => User::class,
            'auditable_id'   => $request->user()->id,
            'ip_address'     => $request->ip(),
            'context'        => [
                'client_id' => $pending['client_id'],
                'scopes'    => $pending['scopes'],
            ],
        ]);

        $request->session()->forget(self::SESSION_KEY);

        return redirect()->away($this->appendQuery($pending['redirect_uri'], [
            'error'             => 'access_denied',
            'error_description' => 'Consenso negato dall\'utente.',
            'state'             => $pending['state'],
        ]));
    }

    // =========================================================================

    /**
     * Rilegge la richiesta in attesa, verificando che sia ancora valida e che
     * sia dello stesso utente che ora sta premendo il bottone.
     *
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

        $issuedAt = Carbon::parse($pending['issued_at'] ?? '1970-01-01');

        if ($issuedAt->addMinutes(self::PENDING_TTL_MINUTES)->isPast()) {
            $request->session()->forget(self::SESSION_KEY);

            return null;
        }

        return $pending;
    }

    private function localError(string $title, string $detail)
    {
        return response()->view('oauth.error', [
            'title'  => $title,
            'detail' => $detail,
        ], 400);
    }

    private function redirectError(string $redirectUri, string $error, string $description, string $state): RedirectResponse
    {
        return redirect()->away($this->appendQuery($redirectUri, [
            'error'             => $error,
            'error_description' => $description,
            'state'             => $state,
        ]));
    }

    /**
     * @param array<string, string> $params
     */
    private function appendQuery(string $url, array $params): string
    {
        $separator = str_contains($url, '?') ? '&' : '?';

        return $url . $separator . http_build_query(array_filter(
            $params,
            static fn ($value) => $value !== '' && $value !== null
        ));
    }
}
