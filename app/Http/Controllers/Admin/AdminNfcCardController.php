<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\BulkStoreNfcCardRequest;
use App\Http\Requests\StoreNfcCardRequest;
use App\Models\Account;
use App\Models\Company;
use App\Models\NfcCard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Illuminate\Support\Facades\DB;

class AdminNfcCardController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return ['backoffice'];
    }

    /** Lista tutte le card emesse. */
    public function index(Request $request): View
    {
        $cards = NfcCard::with(['company', 'ownerUser', 'issuer'])
            ->when($request->search, fn($q) => $q->where(fn($w) => $w
                ->whereHas('company', fn($c) => $c->where('name', 'like', "%{$request->search}%"))
                ->orWhereHas('ownerUser', fn($u) => $u->where('name', 'like', "%{$request->search}%"))
            ))
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
        return view('admin.nfc-cards.create', [
            'pageTitle'    => 'Emetti nuova Card NFC',
            'participants' => $this->participants(),
        ]);
    }

    /** Salva la nuova card (status: pending). */
    public function store(StoreNfcCardRequest $request): RedirectResponse
    {
        $owner = $request->resolvedOwner();

        $card = NfcCard::create([
            'company_id'    => $owner['company_id'],
            'owner_user_id' => $owner['owner_user_id'],
            'issued_by'     => $request->user()->id,
            'serial_number' => NfcCard::generateSerial(),
            'notes'         => $request->validated('notes'),
            'status'        => 'pending',
        ]);

        $payload = NfcCard::buildPayload($card->uuid);
        $card->update(['nfc_payload' => $payload]);

        return redirect()->route('admin.nfc-cards.show', $card)
            ->with('success', 'Card creata. Ora scrivi il chip NFC.');
    }

    /** Form emissione bulk (N card per lo stesso titolare). */
    public function bulkCreate(): View
    {
        return view('admin.nfc-cards.bulk-create', [
            'pageTitle'    => 'Emissione bulk Card NFC',
            'participants' => $this->participants(),
        ]);
    }

    /** Crea N card in una transazione atomica. */
    public function bulkStore(BulkStoreNfcCardRequest $request): RedirectResponse
    {
        $data  = $request->validated();
        $owner = $request->resolvedOwner();

        $created = DB::transaction(function () use ($data, $owner, $request) {
            $cards = [];
            for ($i = 0; $i < $data['quantity']; $i++) {
                $card = NfcCard::create([
                    'company_id'    => $owner['company_id'],
                    'owner_user_id' => $owner['owner_user_id'],
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

    /**
     * Lista unificata dei partecipanti che possono ricevere una card:
     * aziende (company) e privati (user con conto personale).
     *
     * @return Collection<int, array{type:string,id:int,name:string,label:string}>
     */
    private function participants(): Collection
    {
        $companies = Company::orderBy('name')->get(['id', 'name'])
            ->map(fn (Company $c) => [
                'type'  => 'company',
                'id'    => $c->id,
                'name'  => $c->name,
                'label' => 'Azienda',
            ]);

        $privates = Account::with('ownerUser:id,name')
            ->where('owner_type', 'private')
            ->whereNull('parent_account_id')
            ->whereNotNull('owner_user_id')
            ->get()
            ->map(fn (Account $a) => [
                'type'  => 'user',
                'id'    => $a->owner_user_id,
                'name'  => $a->ownerUser?->name ?? $a->display_name,
                'label' => 'Privato',
            ])
            ->filter(fn (array $p) => ! empty($p['name']));

        return $companies->concat($privates)
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();
    }

    /** Dettaglio card. */
    public function show(NfcCard $nfcCard): View
    {
        $nfcCard->load(['company', 'ownerUser', 'issuer', 'logs' => fn($q) => $q->latest()->limit(20)]);

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
