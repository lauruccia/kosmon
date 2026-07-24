<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Plan;
use App\Models\PlanPayment;
use App\Services\PlanUpgradeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\View\View;

/**
 * CRUD piani di abbonamento (/admin/piani). L'admin puo' creare nuovi piani
 * oltre ai 4 storici (Ecommerce/Vetrina/Biglietto/Anagrafica) e definire per
 * ciascuno: prezzo canone, se puo' vendere prodotti nello shop, lo stile
 * grafica card in directory, l'ordine di visualizzazione, il colore badge e
 * se e' pagabile in KY oltre che con Stripe/PayPal/bonifico.
 */
class AdminPlanController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return ['backoffice'];
    }

    public function index(): View
    {
        $plans = Plan::withCount('companies')->orderBy('display_order')->orderBy('name')->get();

        return view('admin.plans.index', [
            'pageTitle' => 'Piani di abbonamento',
            'plans'     => $plans,
            'activeNav' => 'plans',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validatedData($request);

        $plan = Plan::create($data);

        AuditLog::create([
            'actor_user_id'  => $request->user()->id,
            'event'          => 'admin.plan.created',
            'auditable_type' => Plan::class,
            'auditable_id'   => $plan->id,
            'context'        => ['name' => $plan->name],
        ]);

        return back()->with('success', "Piano \"{$plan->name}\" creato.");
    }

    public function update(Request $request, Plan $plan): RedirectResponse
    {
        $data = $this->validatedData($request, $plan);

        $plan->update($data);

        AuditLog::create([
            'actor_user_id'  => $request->user()->id,
            'event'          => 'admin.plan.updated',
            'auditable_type' => Plan::class,
            'auditable_id'   => $plan->id,
            'context'        => $data,
        ]);

        return back()->with('success', "Piano \"{$plan->name}\" aggiornato.");
    }

    public function toggle(Plan $plan): RedirectResponse
    {
        $plan->update(['is_active' => ! $plan->is_active]);

        return back()->with('success', $plan->is_active
            ? "Piano \"{$plan->name}\" riattivato."
            : "Piano \"{$plan->name}\" disattivato (le aziende che lo hanno gia' lo mantengono, ma non e' piu' proponibile per nuove sottoscrizioni).");
    }

    public function destroy(Plan $plan): RedirectResponse
    {
        if ($plan->companies()->exists()) {
            return back()->with('error', "Impossibile eliminare \"{$plan->name}\": e' usato da almeno un'azienda. Disattivalo invece di eliminarlo.");
        }

        $name = $plan->name;
        $plan->delete();

        return back()->with('success', "Piano \"{$name}\" eliminato.");
    }

    // ── Revisione bonifici upgrade piano ────────────────────────────────────

    /** GET /admin/piani/bonifici */
    public function pendingTransfers(): View
    {
        $payments = PlanPayment::where('status', 'pending_bank_transfer')
            ->with(['company', 'user', 'fromPlan', 'toPlan'])
            ->latest()
            ->paginate(30);

        return view('admin.plans.pending-transfers', [
            'pageTitle' => 'Bonifici upgrade piano da confermare',
            'payments'  => $payments,
            'activeNav' => 'plans',
        ]);
    }

    /** POST /admin/piani/bonifici/{payment}/confirm */
    public function confirmBankTransfer(Request $request, PlanPayment $payment, PlanUpgradeService $upgradeService): RedirectResponse
    {
        abort_unless($payment->isPendingBankTransfer(), 422, 'Pagamento non in attesa di bonifico.');

        $request->validate(['admin_notes' => 'nullable|string|max:500']);

        $payment->update([
            'admin_notes'  => $request->input('admin_notes'),
            'confirmed_by' => $request->user()->id,
        ]);

        $upgradeService->completePayment($payment);

        return redirect()->route('admin.plans.pending-transfers')
            ->with('success', 'Bonifico confermato. Piano "' . $payment->toPlan->name . '" attivato per ' . $payment->company->name . '.');
    }

    /** POST /admin/piani/bonifici/{payment}/reject */
    public function rejectBankTransfer(Request $request, PlanPayment $payment): RedirectResponse
    {
        abort_unless($payment->isPendingBankTransfer(), 422);

        $request->validate(['admin_notes' => 'nullable|string|max:500']);

        $payment->update([
            'status'       => 'failed',
            'admin_notes'  => $request->input('admin_notes') ?: 'Bonifico non ricevuto o non conforme.',
            'confirmed_by' => $request->user()->id,
        ]);

        return redirect()->route('admin.plans.pending-transfers')->with('success', 'Bonifico rifiutato.');
    }

    private function validatedData(Request $request, ?Plan $plan = null): array
    {
        $validated = $request->validate([
            'name'               => ['required', 'string', 'max:80'],
            'slug'               => ['nullable', 'string', 'max:60', 'alpha_dash',
                                       \Illuminate\Validation\Rule::unique('plans', 'slug')->ignore($plan?->id)],
            'description'        => ['nullable', 'string', 'max:2000'],
            'price_eur'          => ['required', 'numeric', 'min:0'],
            'can_sell_products'  => ['nullable', 'boolean'],
            'card_style'         => ['required', \Illuminate\Validation\Rule::in(array_keys(Plan::CARD_STYLES))],
            'display_order'      => ['required', 'integer', 'min:0'],
            'badge_color'        => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'allow_ky_payment'   => ['nullable', 'boolean'],
            'is_active'          => ['nullable', 'boolean'],
        ]);

        return [
            'name'              => $validated['name'],
            'slug'              => $validated['slug'] ?: \Illuminate\Support\Str::slug($validated['name']),
            'description'       => $validated['description'] ?? null,
            'price_cents'       => ky_to_cents($validated['price_eur']),
            'can_sell_products' => (bool) ($validated['can_sell_products'] ?? false),
            'card_style'        => $validated['card_style'],
            'display_order'     => $validated['display_order'],
            'badge_color'       => $validated['badge_color'] ?: null,
            'allow_ky_payment'  => (bool) ($validated['allow_ky_payment'] ?? false),
            'is_active'         => (bool) ($validated['is_active'] ?? false),
        ];
    }
}
