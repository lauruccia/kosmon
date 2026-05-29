<?php

namespace App\Http\Controllers;

use App\Models\KyCard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdminKyCardController extends Controller
{
    public function index(): View
    {
        $cards = KyCard::orderBy('sort_order')->orderBy('price_eur_cents')->get();
        return view('admin.ky-cards.index', compact('cards'));
    }

    public function create(): View
    {
        return view('admin.ky-cards.form', [
            'card'      => new KyCard(),
            'pageTitle' => 'Nuova KYCard',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        KyCard::create($data);
        return redirect()->route('admin.ky-cards.index')
            ->with('success', 'KYCard creata con successo.');
    }

    public function edit(KyCard $kyCard): View
    {
        return view('admin.ky-cards.form', [
            'card'      => $kyCard,
            'pageTitle' => 'Modifica KYCard',
        ]);
    }

    public function update(Request $request, KyCard $kyCard): RedirectResponse
    {
        $data = $this->validated($request, $kyCard);
        $kyCard->update($data);
        return redirect()->route('admin.ky-cards.index')
            ->with('success', 'KYCard aggiornata.');
    }

    public function destroy(KyCard $kyCard): RedirectResponse
    {
        if ($kyCard->purchases()->whereIn('status', ['pending', 'completed'])->exists()) {
            return back()->with('error', 'Impossibile eliminare: esistono acquisti associati.');
        }
        $kyCard->delete();
        return redirect()->route('admin.ky-cards.index')
            ->with('success', 'KYCard eliminata.');
    }

    public function toggle(KyCard $kyCard): RedirectResponse
    {
        $kyCard->update(['is_active' => !$kyCard->is_active]);
        return back()->with('success', $kyCard->is_active ? 'Card attivata.' : 'Card disattivata.');
    }

    // ── Bonifici in attesa ─────────────────────────────────────────────────

    public function pendingTransfers(): \Illuminate\View\View
    {
        $pending = \App\Models\KyCardPurchase::where('status', 'pending_bank_transfer')
            ->with(['kyCard', 'user', 'account'])
            ->latest()
            ->get();

        return view('admin.ky-cards.pending-transfers', compact('pending'));
    }

    // ── Validation ─────────────────────────────────────────────────────────

    private function validated(Request $request, ?KyCard $existing = null): array
    {
        $raw = $request->validate([
            'name'             => 'required|string|max:100',
            'description'      => 'nullable|string|max:500',
            'price_eur'        => 'required|numeric|min:0.01|max:99999',
            'bonus_type'       => 'required|in:fixed,percentage',
            'ky_base_amount'   => 'required|integer|min:1',
            'bonus_value'      => 'required|numeric|min:0',
            'stripe_price_id'  => 'nullable|string|max:100',
            'sort_order'       => 'nullable|integer|min:0',
            'is_active'        => 'nullable|boolean',
        ]);

        return [
            'name'            => $raw['name'],
            'description'     => $raw['description'] ?? null,
            'price_eur_cents' => (int) round($raw['price_eur'] * 100),
            'bonus_type'      => $raw['bonus_type'],
            'ky_base_amount'  => (int) $raw['ky_base_amount'],
            'bonus_value'     => (float) $raw['bonus_value'],
            'stripe_price_id' => $raw['stripe_price_id'] ?? null,
            'sort_order'      => (int) ($raw['sort_order'] ?? 0),
            'is_active'       => (bool) ($raw['is_active'] ?? true),
        ];
    }
}
