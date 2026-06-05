<?php

namespace App\Http\Controllers;

use App\Models\Account;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * StaticNfcController
 *
 * Gestisce il flusso "card NFC statica per ristorante/esercente".
 * La card è un tag NTAG programmato con l'URL:
 *   https://tuodominio.it/paga/{kyAccountNumber}
 *
 * Il cliente avvicina il telefono → il browser apre la pagina pubblica →
 * se non loggato viene mandato al login con URL intended → dopo il login
 * torna su questa pagina e vede il form di pagamento.
 *
 *   GET /paga/{kyAccountNumber}  →  landing pubblica + form pagamento
 */
class StaticNfcController extends Controller
{
    /**
     * Landing pubblica della card NFC statica.
     *
     * - Utente non autenticato → redirect al login (con intended URL salvato)
     * - Utente autenticato     → form di pagamento verso l'esercente
     */
    public function pay(Request $request, string $kyAccountNumber): View|RedirectResponse
    {
        // Cerca l'account per numero KY (es. KYB1234567890ABC)
        $toAccount = Account::with(['company', 'ownerUser'])
            ->where('account_number', $kyAccountNumber)
            ->where('status', 'active')
            ->firstOrFail();

        // Non mostrare conti di sistema o admin come destinatari
        abort_if($toAccount->is_system_account, 404);

        // Se non autenticato → salva intended URL e manda al login
        if (! $request->user()) {
            return redirect()->guest(route('login'));
        }

        $user = $request->user();

        // Gli admin non usano questo flusso
        if ($user->canAccessBackoffice()) {
            return redirect()->route('admin.dashboard');
        }

        // Risolvi account del pagante
        $fromAccount = $this->resolveRootAccount($user);

        // Non puoi pagare te stesso
        if ($fromAccount->id === $toAccount->id) {
            return redirect()->route('portal.dashboard')
                ->with('portal_error', 'Non puoi pagare il tuo stesso conto.');
        }

        return view('portal.nfc-static-pay', [
            'pageTitle'   => 'Paga ' . ($toAccount->company?->name ?? $toAccount->display_name),
            'fromAccount' => $fromAccount,
            'toAccount'   => $toAccount,
            'activeNav'   => 'paga',
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function resolveRootAccount(\App\Models\User $user): Account
    {
        if ($user->managed_account_id !== null) {
            $sub = Account::with('parentAccount')->findOrFail($user->managed_account_id);
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
