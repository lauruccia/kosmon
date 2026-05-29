<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\NfcCard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdminNfcCardController extends Controller
{
    /** Lista tutte le card emesse. */
    public function index(Request $request): View
    {
        $cards = NfcCard::with(['company', 'issuer'])
            ->when($request->search, fn($q) => $q->whereHas('company', fn($c) => $c->where('name', 'like', "%{$request->search}%")))
            ->when($request->status, fn($q) => $q->where('status', $request->status))
            ->latest()
            ->paginate(25)
            ->withQueryString();

        return view('admin.nfc-cards.index', [
            'pageTitle' => 'Card NFC',
            'cards'     => $cards,
        ]);
    }

    /** Form di emissione nuova card. */
    public function create(): View
    {
        $companies = Company::where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name']);

        return view('admin.nfc-cards.create', [
            'pageTitle' => 'Emetti nuova Card NFC',
            'companies' => $companies,
        ]);
    }

    /** Salva la nuova card (status: pending). */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'company_id'    => ['required', 'exists:companies,id'],
            'serial_number' => ['nullable', 'string', 'max:50', 'unique:nfc_cards,serial_number'],
            'notes'         => ['nullable', 'string', 'max:500'],
        ]);

        $card = NfcCard::create([
            'company_id'    => $data['company_id'],
            'issued_by'     => $request->user()->id,
            'serial_number' => $data['serial_number'] ?? null,
            'notes'         => $data['notes'] ?? null,
            'status'        => 'pending',
        ]);

        // Genera il payload HMAC da scrivere sul chip
        $payload = NfcCard::buildPayload($card->uuid);
        $card->update(['nfc_payload' => $payload]);

        return redirect()->route('admin.nfc-cards.show', $card)
            ->with('success', 'Card creata. Ora scrivi il chip NFC.');
    }

    /** Dettaglio card + pagina per scrivere il chip. */
    public function show(NfcCard $nfcCard): View
    {
        $nfcCard->load(['company', 'issuer', 'logs' => fn($q) => $q->latest()->limit(20)]);

        return view('admin.nfc-cards.show', [
            'pageTitle' => 'Card NFC — ' . ($nfcCard->serial_number ?? $nfcCard->uuid),
            'card'      => $nfcCard,
        ]);
    }

    /** Segna card come "issued" (chip scritto). */
    public function markIssued(Request $request, NfcCard $nfcCard): RedirectResponse
    {
        abort_unless(in_array($nfcCard->status, ['pending']), 403, 'Stato non valido.');

        $nfcCard->update([
            'status'    => 'issued',
            'issued_at' => now(),
        ]);

        return back()->with('success', 'Card segnata come emessa. Ora consegnala al cliente.');
    }

    /** Segna card come "delivered" (consegnata al cliente). */
    public function markDelivered(NfcCard $nfcCard): RedirectResponse
    {
        abort_unless($nfcCard->status === 'issued', 403, 'Stato non valido.');

        $nfcCard->update([
            'status'       => 'delivered',
            'delivered_at' => now(),
        ]);

        return back()->with('success', 'Card segnata come consegnata. Il cliente può ora attivarla.');
    }

    /** Revoca definitiva card. */
    public function revoke(Request $request, NfcCard $nfcCard): RedirectResponse
    {
        abort_unless(! in_array($nfcCard->status, ['revoked']), 403, 'Già revocata.');

        $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        $nfcCard->update([
            'status'     => 'revoked',
            'revoked_at' => now(),
            'notes'      => $nfcCard->notes . ($request->reason ? "\n[Revoca] " . $request->reason : ''),
        ]);

        return redirect()->route('admin.nfc-cards.index')
            ->with('success', 'Card revocata definitivamente.');
    }
}
