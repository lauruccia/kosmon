<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\View\View;

class EmailChangeController extends Controller
{
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

        // OTP al nuovo indirizzo
        try {
            Mail::raw(
                "Il tuo codice di verifica per il cambio email KMoney:\n\n{$token}\n\nIl codice scade tra 30 minuti.",
                function ($m) use ($request, $token) {
                    $m->to($request->new_email)
                      ->subject('[KMoney] Codice verifica cambio email: ' . $token);
                }
            );
        } catch (\Throwable) {}

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
        } catch (\Throwable) {}

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

        if (! $user->pending_email) {
            return redirect()->route('portal.email-change')
                ->withErrors(['token' => 'Nessuna richiesta di cambio email in attesa.']);
        }

        if (now()->gt($user->email_change_expires_at)) {
            $user->update([
                'pending_email'             => null,
                'email_change_token'        => null,
                'email_change_expires_at'   => null,
                'email_change_cancel_token' => null,
            ]);
            return redirect()->route('portal.email-change')
                ->withErrors(['token' => 'Il codice e\' scaduto. Riprova.']);
        }

        $oldEmail = $user->email;
        $user->update([
            'email'                     => $user->pending_email,
            'pending_email'             => null,
            'email_change_token'        => null,
            'email_change_expires_at'   => null,
            'email_change_cancel_token' => null,
            'email_verified_at'         => now(),
        ]);

        try {
            Mail::raw(
                "L'indirizzo email del tuo account KMoney e' stato aggiornato a {$user->email}.\n" .
                "Se non hai autorizzato questa modifica, contatta immediatamente il supporto.",
                function ($m) use ($oldEmail) {
                    $m->to($oldEmail)->subject('[KMoney] Email aggiornata');
                }
            );
        } catch (\Throwable) {}

        return redirect()->route('portal.dashboard')
            ->with('success', 'Email aggiornata con successo a ' . $user->email . '.');
    }

    public function cancel(Request $request): RedirectResponse
    {
        $request->user()->update([
            'pending_email'             => null,
            'email_change_token'        => null,
            'email_change_expires_at'   => null,
            'email_change_cancel_token' => null,
        ]);

        return redirect()->route('portal.email-change')->with('info', 'Richiesta di cambio email annullata.');
    }

    /**
     * Revoca il cambio email via link unauthenticated inviato alla vecchia email.
     */
    public function cancelByToken(string $token): RedirectResponse
    {
        $user = \App\Models\User::where('email_change_cancel_token', $token)
            ->whereNotNull('pending_email')
            ->first();

        if (! $user) {
            return redirect()->route('login')
                ->with('error', 'Il link di annullamento non e\' valido o e\' gia\' stato usato.');
        }

        if ($user->email_change_expires_at && now()->gt($user->email_change_expires_at)) {
            $user->update([
                'pending_email'             => null,
                'email_change_token'        => null,
                'email_change_expires_at'   => null,
                'email_change_cancel_token' => null,
            ]);
            return redirect()->route('login')
                ->with('info', 'La richiesta era gia\' scaduta. Nessuna modifica applicata.');
        }

        $user->update([
            'pending_email'             => null,
            'email_change_token'        => null,
            'email_change_expires_at'   => null,
            'email_change_cancel_token' => null,
        ]);

        return redirect()->route('login')
            ->with('success', 'Cambio email annullato. Il tuo indirizzo rimane invariato.');
    }
}
