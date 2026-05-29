<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\NfcCard;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Gestione card NFC lato cliente:
 * - visualizza le proprie card
 * - attiva card con PIN
 * - imposta limiti
 * - blocca/sblocca card
 */
class NfcCardController extends Controller
{
    /** Lista card del cliente. */
    public function index(Request $request): View
    {
        $company = $this->resolveCompany($request->user());

        $cards = NfcCard::where('company_id', $company->id)
            ->whereNotIn('status', ['pending', 'issued']) // solo quelle consegnate
            ->latest()
            ->get();

        return view('portal.nfc-cards.index', [
            'pageTitle' => 'Le mie Card NFC',
            'cards'     => $cards,
            'activeNav' => 'nfc-cards',
        ]);
    }

    /** Dettaglio singola card (limiti + blocco). */
    public function show(Request $request, string $uuid): View
    {
        $company = $this->resolveCompany($request->user());
        $card    = NfcCard::where('uuid', $uuid)->where('company_id', $company->id)->firstOrFail();

        return view('portal.nfc-cards.show', [
            'pageTitle' => 'Card ' . ($card->serial_number ?? substr($card->uuid, 0, 8)),
            'card'      => $card,
            'activeNav' => 'nfc-cards',
        ]);
    }

    /** Form attivazione PIN. */
    public function activateForm(Request $request, string $uuid): View|RedirectResponse
    {
        $company = $this->resolveCompany($request->user());
        $card    = NfcCard::where('uuid', $uuid)->where('company_id', $company->id)->firstOrFail();

        if ($card->status === 'active') {
            return redirect()->route('portal.nfc-cards.show', $uuid)
                ->with('portal_info', 'La card è già attiva.');
        }

        abort_unless($card->status === 'delivered', 403, 'Card non ancora consegnata.');

        return view('portal.nfc-cards.activate', [
            'pageTitle' => 'Attiva Card NFC',
            'card'      => $card,
            'activeNav' => 'nfc-cards',
        ]);
    }

    /** Imposta PIN e attiva card. */
    public function activate(Request $request, string $uuid): RedirectResponse
    {
        $company = $this->resolveCompany($request->user());
        $card    = NfcCard::where('uuid', $uuid)->where('company_id', $company->id)->firstOrFail();

        abort_unless($card->status === 'delivered', 403);

        $request->validate([
            'pin'              => ['required', 'string', 'min:4', 'max:8', 'regex:/^\d+$/', 'confirmed'],
            'pin_confirmation' => ['required'],
        ], [
            'pin.regex'    => 'Il PIN deve contenere solo cifre.',
            'pin.min'      => 'Il PIN deve essere di almeno 4 cifre.',
            'pin.confirmed'=> 'I PIN non corrispondono.',
        ]);

        $card->setPin($request->pin);
        $card->update([
            'status'       => 'active',
            'activated_at' => now(),
        ]);

        return redirect()->route('portal.nfc-cards.show', $uuid)
            ->with('portal_success', 'Card attivata con successo! Puoi usarla per pagare.');
    }

    /** Aggiorna limiti card. */
    public function updateLimits(Request $request, string $uuid): RedirectResponse
    {
        $company = $this->resolveCompany($request->user());
        $card    = NfcCard::where('uuid', $uuid)->where('company_id', $company->id)->firstOrFail();

        abort_unless($card->status === 'active', 403, 'Card non attiva.');

        $data = $request->validate([
            'limit_per_transaction' => ['nullable', 'integer', 'min:1', 'max:9999999'],
            'limit_daily'           => ['nullable', 'integer', 'min:1', 'max:9999999'],
            'limit_monthly'         => ['nullable', 'integer', 'min:1', 'max:9999999'],
        ]);

        // Valori vuoti → null (rimuovi limite)
        $card->update([
            'limit_per_transaction' => $data['limit_per_transaction'] ?: null,
            'limit_daily'           => $data['limit_daily'] ?: null,
            'limit_monthly'         => $data['limit_monthly'] ?: null,
        ]);

        return back()->with('portal_success', 'Limiti aggiornati.');
    }

    /** Blocca card. */
    public function block(Request $request, string $uuid): RedirectResponse
    {
        $company = $this->resolveCompany($request->user());
        $card    = NfcCard::where('uuid', $uuid)->where('company_id', $company->id)->firstOrFail();

        abort_unless($card->status === 'active', 403);

        $card->update(['status' => 'blocked', 'blocked_at' => now()]);
        $card->logs()->create(['event' => 'blocked', 'notes' => 'Blocco manuale da cliente']);

        return back()->with('portal_success', 'Card bloccata. Nessun pagamento sarà accettato.');
    }

    /** Sblocca card. */
    public function unblock(Request $request, string $uuid): RedirectResponse
    {
        $company = $this->resolveCompany($request->user());
        $card    = NfcCard::where('uuid', $uuid)->where('company_id', $company->id)->firstOrFail();

        abort_unless($card->status === 'blocked', 403);

        $card->update(['status' => 'active', 'blocked_at' => null]);

        return back()->with('portal_success', 'Card sbloccata.');
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function resolveCompany(User $user): \App\Models\Company
    {
        return \App\Models\Company::where('id', $user->company_id)->firstOrFail();
    }
}
