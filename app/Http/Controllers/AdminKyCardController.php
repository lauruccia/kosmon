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

        $warning = null;
        if (empty($data['stripe_price_id']) && config('services.stripe.secret')) {
            $data['stripe_price_id'] = $this->createStripePrice($data['name'], $data['price_eur_cents']);
            if (empty($data['stripe_price_id'])) {
                $warning = ' Attenzione: creazione automatica del prezzo Stripe non riuscita (controlla i log) — per ora la card è pagabile solo con bonifico.';
            }
        }

        KyCard::create($data);

        return redirect()->route('admin.ky-cards.index')
            ->with('success', 'KYCard creata con successo.' . $warning);
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

        $warning       = null;
        $manualPriceId = $data['stripe_price_id'];
        $needsNewPrice = empty($manualPriceId) && (
            empty($kyCard->stripe_price_id)
            || $kyCard->price_eur_cents !== $data['price_eur_cents']
            || $kyCard->name !== $data['name']
        );

        if ($needsNewPrice && config('services.stripe.secret')) {
            $newPriceId = $this->createStripePrice($data['name'], $data['price_eur_cents']);
            if ($newPriceId) {
                // Il prezzo vecchio non è più agganciato a questa card: lo disattiviamo
                // su Stripe per non lasciare prezzi "orfani" attivi nel dashboard.
                $this->archiveStripePrice($kyCard->stripe_price_id);
                $data['stripe_price_id'] = $newPriceId;
            } else {
                // Generazione fallita: manteniamo il prezzo precedente per non
                // disattivare un metodo di pagamento già funzionante.
                $data['stripe_price_id'] = $kyCard->stripe_price_id;
                $warning = ' Attenzione: aggiornamento automatico del prezzo Stripe non riuscito (controlla i log) — è rimasto attivo il prezzo precedente.';
            }
        } elseif (empty($manualPriceId)) {
            $data['stripe_price_id'] = $kyCard->stripe_price_id;
        }

        $kyCard->update($data);

        return redirect()->route('admin.ky-cards.index')
            ->with('success', 'KYCard aggiornata.' . $warning);
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

    // ── Stripe: sincronizzazione automatica del prezzo ──────────────────────

    /**
     * Crea un Price su Stripe (con Product inline) per la card e ne
     * restituisce l'id, oppure null se la chiamata fallisce.
     */
    private function createStripePrice(string $name, int $priceEurCents): ?string
    {
        try {
            \Stripe\Stripe::setApiKey(config('services.stripe.secret'));

            $price = \Stripe\Price::create([
                'unit_amount'  => $priceEurCents,
                'currency'     => 'eur',
                'product_data' => ['name' => 'KYCard: ' . $name],
            ]);

            return $price->id;
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Stripe price auto-create failed', [
                'error' => $e->getMessage(),
                'name'  => $name,
            ]);
            return null;
        }
    }

    /**
     * Disattiva un vecchio Price (+ il suo Product dedicato) su Stripe.
     * Best-effort: non blocca mai il flusso di salvataggio della card.
     *
     * Nota: un Price non si può archiviare finché è il "default price" del
     * suo Product (caso normale qui, dato che ogni Price viene creato con
     * un Product inline dedicato via product_data). Va quindi archiviato
     * prima il Product — a quel punto anche il Price si lascia archiviare,
     * e lo facciamo per non lasciarlo elencato tra i prezzi attivi.
     */
    private function archiveStripePrice(?string $priceId): void
    {
        if (!$priceId) {
            return;
        }

        try {
            \Stripe\Stripe::setApiKey(config('services.stripe.secret'));

            $price = \Stripe\Price::retrieve($priceId);

            if ($price->product) {
                \Stripe\Product::update(is_string($price->product) ? $price->product : $price->product->id, ['active' => false]);
            }

            \Stripe\Price::update($priceId, ['active' => false]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning('Stripe price archive failed', [
                'error'    => $e->getMessage(),
                'price_id' => $priceId,
            ]);
        }
    }

    // ── Validation ─────────────────────────────────────────────────────────

    private function validated(Request $request, ?KyCard $existing = null): array
    {
        $raw = $request->validate([
            'name'             => 'required|string|max:100',
            'description'      => 'nullable|string|max:500',
            'price_eur'        => 'required|numeric|min:0.01|max:99999',
            'bonus_type'       => 'required|in:fixed,percentage',
            'ky_base_amount'   => 'required|numeric|min:0.01|max:999999',
            'bonus_value'      => 'required|numeric|min:0',
            'stripe_price_id'  => 'nullable|string|max:100',
            'sort_order'       => 'nullable|integer|min:0',
            'is_active'        => 'nullable|boolean',
        ]);

        // Il form lavora sempre in "KY umani" (come per price_eur -> price_eur_cents):
        // "Fisso" è KY extra nella stessa unità di ky_base_amount, quindi va convertito
        // anch'esso in centesimi; "Percentuale" è un numero puro (es. 25 = 25%), non si tocca.
        $bonusValueCents = $raw['bonus_type'] === 'fixed'
            ? (float) round($raw['bonus_value'] * 100)
            : (float) $raw['bonus_value'];

        return [
            'name'            => $raw['name'],
            'description'     => $raw['description'] ?? null,
            'price_eur_cents' => (int) round($raw['price_eur'] * 100),
            'bonus_type'      => $raw['bonus_type'],
            'ky_base_amount'  => (int) round($raw['ky_base_amount'] * 100),
            'bonus_value'     => $bonusValueCents,
            'stripe_price_id' => $raw['stripe_price_id'] ?? null,
            'sort_order'      => (int) ($raw['sort_order'] ?? 0),
            'is_active'       => (bool) ($raw['is_active'] ?? true),
        ];
    }
}
