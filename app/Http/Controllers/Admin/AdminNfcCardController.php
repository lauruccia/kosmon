<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\NfcCard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Illuminate\Support\Facades\DB;

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

        $stats = [
            'total'     => NfcCard::count(),
            'pending'   => NfcCard::where('status', 'pending')->count(),
            'issued'    => NfcCard::where('status', 'issued')->count(),
            'delivered' => NfcCard::where('status', 'delivered')->count(),
            'active'    => NfcCard::where('status', 'active')->count(),
            'shipped'   => NfcCard::whereNotNull('shipped_at')->whereNull('delivered_at')->count(),
        ];

        return view('admin.nfc-cards.index', [
            'pageTitle' => 'Card NFC',
            'cards'     => $cards,
            'stats'     => $stats,
        ]);
    }

    /** Form di emissione nuova card (singola). */
    public function create(): View
    {
        $companies = Company::orderBy('name')->get(['id', 'name']);

        return view('admin.nfc-cards.create', [
            'pageTitle' => 'Emetti nuova Card NFC',
            'companies' => $companies,
        ]);
    }

    /** Salva la nuova card (status: pending). */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'company_id' => ['required', 'exists:companies,id'],
            'notes'      => ['nullable', 'string', 'max:500'],
        ]);

        $card = NfcCard::create([
            'company_id'    => $data['company_id'],
            'issued_by'     => $request->user()->id,
            'serial_number' => NfcCard::generateSerial(),
            'notes'         => $data['notes'] ?? null,
            'status'        => 'pending',
        ]);

        $payload = NfcCard::buildPayload($card->uuid);
        $card->update(['nfc_payload' => $payload]);

        return redirect()->route('admin.nfc-cards.show', $card)
            ->with('success', 'Card creata. Ora scrivi il chip NFC.');
    }

    /** Form emissione bulk (N card per la stessa azienda). */
    public function bulkCreate(): View
    {
        $companies = Company::orderBy('name')->get(['id', 'name']);

        return view('admin.nfc-cards.bulk-create', [
            'pageTitle' => 'Emissione bulk Card NFC',
            'companies' => $companies,
        ]);
    }

    /** Crea N card in una transazione atomica. */
    public function bulkStore(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'company_id' => ['required', 'exists:companies,id'],
            'quantity'   => ['required', 'integer', 'min:1', 'max:50'],
            'notes'      => ['nullable', 'string', 'max:500'],
        ]);

        $created = DB::transaction(function () use ($data, $request) {
            $cards = [];
            for ($i = 0; $i < $data['quantity']; $i++) {
                $card = NfcCard::create([
                    'company_id'    => $data['company_id'],
                    'issued_by'     => $request->user()->id,
                    'serial_number' => NfcCard::generateSerial(),
                    'notes'         => $data['notes'] ?? null,
                    'status'        => 'pending',
                ]);
                $card->update(['nfc_payload' => NfcCard::buildPayload($card->uuid)]);
                $cards[] = $card;
            }
            return $cards;
        });

        return redirect()->route('admin.nfc-cards.index')
            ->with('success', count($created) . ' card NFC create correttamente.');
    }

    /** Dettaglio card. */
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

    /** Aggiorna tracking spedizione e segna come spedita. */
    public function markShipped(Request $request, NfcCard $nfcCard): RedirectResponse
    {
        abort_unless(in_array($nfcCard->status, ['issued', 'pending']), 403, 'Stato non valido.');

        $data = $request->validate([
            'tracking_code'    => ['required', 'string', 'max:100'],
            'shipping_carrier' => ['nullable', 'string', 'max:50'],
        ]);

        $nfcCard->update([
            'tracking_code'    => $data['tracking_code'],
            'shipping_carrier' => $data['shipping_carrier'] ?? null,
            'shipped_at'       => now(),
        ]);

        return back()->with('success', 'Tracking spedizione aggiornato: ' . $data['tracking_code']);
    }

    /** Segna card come "delivered" (consegnata al cliente). */
    public function markDelivered(NfcCard $nfcCard): RedirectResponse
    {
        abort_unless(in_array($nfcCard->status, ['issued', 'delivered']), 403, 'Stato non valido.');

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
