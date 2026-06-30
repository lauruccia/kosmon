<?php

namespace App\Http\Controllers;

use App\Models\NfcCard;
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
        $user = $request->user();

        $cards = NfcCard::ownedByUser($user)
            ->whereNotIn('status', ['pending', 'issued'])
            ->latest()
            ->get();

        // Richieste di pagamento pendenti sulle card del cliente
        $pendingSessions = \App\Models\NfcCardAuthSession::with(['card', 'merchant', 'merchantAccount'])
            ->whereHas('card', fn ($q) => $q->ownedByUser($user))
            ->where('status', 'pending')
            ->where('expires_at', '>', now())
            ->latest()
            ->get();

        return view('portal.nfc-cards.index', [
            'pageTitle'       => 'Le mie Card NFC',
            'cards'           => $cards,
            'pendingSessions' => $pendingSessions,
            'activeNav'       => 'nfc-cards',
        ]);
    }

    /** Dettaglio singola card (limiti + blocco). */
    public function show(Request $request, string $uuid): View
    {
        $card = NfcCard::ownedByUser($request->user())->where('uuid', $uuid)->firstOrFail();

        return view('portal.nfc-cards.show', [
            'pageTitle' => 'Card ' . ($card->serial_number ?? substr($card->uuid, 0, 8)),
            'card'      => $card,
            'activeNav' => 'nfc-cards',
        ]);
    }

    /** Form attivazione PIN. */
    public function activateForm(Request $request, string $uuid): View|RedirectResponse
    {
        $card = NfcCard::ownedByUser($request->user())->where('uuid', $uuid)->firstOrFail();

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
        $card = NfcCard::ownedByUser($request->user())->where('uuid', $uuid)->firstOrFail();

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
        $card = NfcCard::ownedByUser($request->user())->where('uuid', $uuid)->firstOrFail();

        abort_unless($card->status === 'active', 403, 'Card non attiva.');

        // Accetta sia virgola che punto come separatore decimale
        foreach (['limit_per_transaction', 'pin_threshold', 'limit_daily', 'limit_monthly'] as $field) {
            if ($request->filled($field)) {
                $request->merge([$field => str_replace(',', '.', (string) $request->input($field))]);
            }
        }

        $data = $request->validate([
            'limit_per_transaction' => ['nullable', 'numeric', 'min:0.01', 'max:9999999'],
            'pin_threshold'         => ['nullable', 'numeric', 'min:0.01', 'max:9999999'],
            'limit_daily'           => ['nullable', 'numeric', 'min:0.01', 'max:9999999'],
            'limit_monthly'         => ['nullable', 'numeric', 'min:0.01', 'max:9999999'],
        ]);

        // Input in KY → centesimi. Valori vuoti → null (rimuovi limite / PIN sempre richiesto)
        $card->update([
            'limit_per_transaction' => filled($data['limit_per_transaction'] ?? null) ? ky_to_cents($data['limit_per_transaction']) : null,
            'pin_threshold'         => filled($data['pin_threshold'] ?? null) ? ky_to_cents($data['pin_threshold']) : null,
            'limit_daily'           => filled($data['limit_daily'] ?? null) ? ky_to_cents($data['limit_daily']) : null,
            'limit_monthly'         => filled($data['limit_monthly'] ?? null) ? ky_to_cents($data['limit_monthly']) : null,
        ]);

        return back()->with('portal_success', 'Limiti aggiornati.');
    }

    /** Blocca card. */
    public function block(Request $request, string $uuid): RedirectResponse
    {
        $card = NfcCard::ownedByUser($request->user())->where('uuid', $uuid)->firstOrFail();

        abort_unless($card->status === 'active', 403);

        $card->update(['status' => 'blocked', 'blocked_at' => now()]);
        $card->logs()->create(['event' => 'blocked', 'notes' => 'Blocco manuale da cliente']);

        return back()->with('portal_success', 'Card bloccata. Nessun pagamento sarà accettato.');
    }

    /** Sblocca card. */
    public function unblock(Request $request, string $uuid): RedirectResponse
    {
        $card = NfcCard::ownedByUser($request->user())->where('uuid', $uuid)->firstOrFail();

        abort_unless($card->status === 'blocked', 403);

        $card->update(['status' => 'active', 'blocked_at' => null]);

        return back()->with('portal_success', 'Card sbloccata.');
    }
}
