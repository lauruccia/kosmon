<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\View\View;

class EmailChangeController extends Controller
{
    /**
     * Quanti codici sbagliati prima che la richiesta si annulli da sola.
     * Il conteggio vive in cache (nessuna colonna nuova, nessuna migrazione)
     * e si azzera a ogni nuova richiesta e a ogni cambio riuscito.
     */
    private const MAX_TENTATIVI = 5;

    private function resolveAccount(\App\Models\User $user): ?\App\Models\Account
    {
        if ($user->managed_account_id !== null) {
            return \App\Models\Account::with(['company', 'ownerUser'])->find($user->managed_account_id);
        }
        if ($user->company_id !== null) {
            return \App\Models\Account::query()
                ->with(['company', 'ownerUser'])
                ->where('company_id', $user->company_id)
                ->whereNull('parent_account_id')
                ->where('status', 'active')
                ->orderBy('id')
                ->first();
        }
        return \App\Models\Account::query()
            ->with(['company', 'ownerUser'])
            ->where('owner_user_id', $user->id)
            ->whereNull('parent_account_id')
            ->where('status', 'active')
            ->orderBy('id')
            ->first();
    }

    // ── Stato della richiesta in corso ──────────────────────────────────────

    /** Spegne la richiesta pendente. Prima questi 4 campi erano azzerati a mano in 5 punti. */
    private function clearPendingChange(\App\Models\User $user): void
    {
        $user->update([
            'pending_email'             => null,
            'email_change_token'        => null,
            'email_change_expires_at'   => null,
            'email_change_cancel_token' => null,
        ]);
    }

    private function attemptsKey(\App\Models\User $user): string
    {
        return 'email_change_attempts:' . $user->id;
    }

    private function registerFailedAttempt(\App\Models\User $user): int
    {
        $key  = $this->attemptsKey($user);
        $next = ((int) Cache::get($key, 0)) + 1;

        // Poco piu' della finestra dei 30 minuti: il contatore non deve
        // sopravvivere alla richiesta a cui si riferisce.
        Cache::put($key, $next, now()->addMinutes(35));

        return $next;
    }

    private function forgetAttempts(\App\Models\User $user): void
    {
        Cache::forget($this->attemptsKey($user));
    }

    // ── Pagine ──────────────────────────────────────────────────────────────

    public function show(Request $request): View
    {
        return view('portal.email-change', [
            'pageTitle'      => 'Cambia email',
            'activeNav'      => 'settings',
            'currentUser'    => $request->user(),
            'currentAccount' => $this->resolveAccount($request->user()),
            'hasPending'     => $request->user()->pending_email !== null,
        ]);
    }

    public function request(Request $request): RedirectResponse
    {
        $request->validate([
            'new_email'        => ['required', 'email', 'max:255', 'unique:users,email'],
            'current_password' => ['required', 'string'],
        ]);

        $user = $request->user();

        if (! Hash::check($request->current_password, $user->password)) {
            return back()->withErrors(['current_password' => 'Password non corretta.'])->withInput();
        }

        if (strtolower($request->new_email) === strtolower($user->email)) {
            return back()->withErrors(['new_email' => 'La nuova email coincide con quella attuale.'])->withInput();
        }

        $token       = strtoupper(substr(md5(uniqid('', true)), 0, 8));
        $cancelToken = Str::random(64);
        $expires     = now()->addMinutes(30);
        $oldEmail    = $user->email;

        $user->update([
            'pending_email'             => $request->new_email,
            'email_change_token'        => $token,
            'email_change_expires_at'   => $expires,
            'email_change_cancel_token' => $cancelToken,
        ]);

        // Richiesta nuova, tentativi da capo.
        $this->forgetAttempts($user);

        AuditLog::create([
            'actor_user_id'  => $user->id,
            'event'          => 'user.email_change_requested',
            'auditable_type' => User::class,
            'auditable_id'   => $user->id,
            'ip_address'     => $request->ip(),
            'context'        => ['from' => $oldEmail, 'to' => $request->new_email],
        ]);

        // OTP al nuovo indirizzo
        try {
            Mail::raw(
                "Il tuo codice di verifica per il cambio email KMoney:\n\n{$token}\n\nIl codice scade tra 30 minuti.",
                function ($m) use ($request, $token) {
                    $m->to($request->new_email)
                      ->subject('[KMoney] Codice verifica cambio email: ' . $token);
                }
            );
        } catch (\Throwable $e) {
            Log::warning('email_change.verification_mail_failed', ['new_email' => $request->new_email, 'error' => $e->getMessage()]);
        }

        // Link di revoca alla vecchia email
        $cancelUrl = route('email-change.cancel-by-token', ['token' => $cancelToken]);
        $body = "E' stata richiesta la modifica dell'email del tuo account KMoney" .
                " al nuovo indirizzo: {$request->new_email}\n\n" .
                "Se sei stato tu, non devi fare nulla.\n\n" .
                "Se NON hai autorizzato questa modifica, annullala entro 30 minuti:\n\n" .
                $cancelUrl . "\n\n" .
                "In alternativa, accedi al portale e vai su Impostazioni -> Cambia email.";
        try {
            Mail::raw($body, function ($m) use ($oldEmail) {
                $m->to($oldEmail)
                  ->subject('[KMoney] Richiesta cambio email - verifica se sei stato tu');
            });
        } catch (\Throwable $e) {
            Log::warning('email_change.alert_mail_failed', ['old_email' => $oldEmail, 'error' => $e->getMessage()]);
        }

        return redirect()->route('portal.email-change.verify-form')
            ->with('info', 'Abbiamo inviato un codice a ' . $request->new_email . '. Inseriscilo per confermare. Hai ricevuto anche un\'email su ' . $oldEmail . ' per annullare se non eri stato tu.');
    }

    public function verifyForm(Request $request): View
    {
        abort_unless($request->user()->pending_email !== null, 404);

        return view('portal.email-change-verify', [
            'pageTitle'      => 'Verifica nuova email',
            'activeNav'      => 'settings',
            'currentUser'    => $request->user(),
            'currentAccount' => $this->resolveAccount($request->user()),
        ]);
    }

    public function verify(Request $request): RedirectResponse
    {
        $request->validate(['token' => ['required', 'string', 'size:8']]);

        $user = $request->user();

        if (! $user->pending_email || ! $user->email_change_token) {
            return redirect()->route('portal.email-change')
                ->withErrors(['token' => 'Nessuna richiesta di cambio email in attesa.']);
        }

        if ($user->email_change_expires_at === null || now()->gt($user->email_change_expires_at)) {
            $this->clearPendingChange($user);
            $this->forgetAttempts($user);

            return redirect()->route('portal.email-change')
                ->withErrors(['token' => 'Il codice e\' scaduto. Riprova.']);
        }

        // ── IL CONTROLLO CHE MANCAVA ────────────────────────────────────────
        // Fino al 28/08/2026 il codice inserito non veniva MAI confrontato con
        // quello salvato: otto caratteri qualsiasi confermavano il cambio e
        // marcavano la nuova casella come verificata senza che nessuno avesse
        // mai letto la mail. hash_equals confronta in tempo costante, cosi' il
        // tempo di risposta non dice quanti caratteri iniziali erano giusti.
        $inserito = strtoupper(trim((string) $request->input('token')));

        if (! hash_equals((string) $user->email_change_token, $inserito)) {
            $tentativi = $this->registerFailedAttempt($user);

            if ($tentativi >= self::MAX_TENTATIVI) {
                $pendingEmail = $user->pending_email;
                $this->clearPendingChange($user);
                $this->forgetAttempts($user);

                AuditLog::create([
                    'actor_user_id'  => $user->id,
                    'event'          => 'user.email_change_blocked',
                    'auditable_type' => User::class,
                    'auditable_id'   => $user->id,
                    'ip_address'     => $request->ip(),
                    'context'        => ['to' => $pendingEmail, 'tentativi' => $tentativi],
                ]);

                return redirect()->route('portal.email-change')
                    ->withErrors(['token' => 'Troppi codici errati: la richiesta e\' stata annullata. Se sei stato tu, ricominciala.']);
            }

            return back()->withErrors([
                'token' => 'Codice non valido. Tentativi rimasti: ' . (self::MAX_TENTATIVI - $tentativi) . '.',
            ]);
        }

        // L'unicita' era stata controllata al momento della richiesta, fino a 30
        // minuti fa: nel frattempo quell'indirizzo puo' essere stato registrato
        // da un altro account. Senza questo controllo l'update sbatterebbe
        // sull'indice unique con un 500.
        $giaPresa = User::where('email', $user->pending_email)
            ->where('id', '!=', $user->id)
            ->exists();

        if ($giaPresa) {
            $this->clearPendingChange($user);
            $this->forgetAttempts($user);

            return redirect()->route('portal.email-change')
                ->withErrors(['token' => 'Quell\'indirizzo nel frattempo e\' stato registrato da un altro account. Riprova con un altro indirizzo.']);
        }

        $oldEmail = $user->email;
        $newEmail = $user->pending_email;

        $user->update([
            'email'                     => $newEmail,
            'pending_email'             => null,
            'email_change_token'        => null,
            'email_change_expires_at'   => null,
            'email_change_cancel_token' => null,
            'email_verified_at'         => now(),
        ]);

        $this->forgetAttempts($user);

        AuditLog::create([
            'actor_user_id'  => $user->id,
            'event'          => 'user.email_changed',
            'auditable_type' => User::class,
            'auditable_id'   => $user->id,
            'ip_address'     => $request->ip(),
            'context'        => ['from' => $oldEmail, 'to' => $newEmail],
        ]);

        try {
            Mail::raw(
                "L'indirizzo email del tuo account KMoney e' stato aggiornato a {$newEmail}.\n" .
                "Se non hai autorizzato questa modifica, contatta immediatamente il supporto.",
                function ($m) use ($oldEmail) {
                    $m->to($oldEmail)->subject('[KMoney] Email aggiornata');
                }
            );
        } catch (\Throwable $e) {
            Log::warning('email_change.confirmation_mail_failed', ['old_email' => $oldEmail, 'error' => $e->getMessage()]);
        }

        return redirect()->route('portal.dashboard')
            ->with('success', 'Email aggiornata con successo a ' . $newEmail . '.');
    }

    public function cancel(Request $request): RedirectResponse
    {
        $user = $request->user();

        $this->clearPendingChange($user);
        $this->forgetAttempts($user);

        return redirect()->route('portal.email-change')->with('info', 'Richiesta di cambio email annullata.');
    }

    /**
     * Revoca il cambio email via link unauthenticated inviato alla vecchia email.
     */
    public function cancelByToken(string $token): RedirectResponse
    {
        $user = User::where('email_change_cancel_token', $token)
            ->whereNotNull('pending_email')
            ->first();

        if (! $user) {
            return redirect()->route('login')
                ->with('error', 'Il link di annullamento non e\' valido o e\' gia\' stato usato.');
        }

        if ($user->email_change_expires_at && now()->gt($user->email_change_expires_at)) {
            $this->clearPendingChange($user);
            $this->forgetAttempts($user);

            return redirect()->route('login')
                ->with('info', 'La richiesta era gia\' scaduta. Nessuna modifica applicata.');
        }

        $pendingEmail = $user->pending_email;

        $this->clearPendingChange($user);
        $this->forgetAttempts($user);

        AuditLog::create([
            'actor_user_id'  => $user->id,
            'event'          => 'user.email_change_cancelled_by_link',
            'auditable_type' => User::class,
            'auditable_id'   => $user->id,
            'ip_address'     => request()->ip(),
            'context'        => ['to' => $pendingEmail],
        ]);

        return redirect()->route('login')
            ->with('success', 'Cambio email annullato. Il tuo indirizzo rimane invariato.');
    }
}
