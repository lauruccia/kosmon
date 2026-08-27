<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Order;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Gli ordini, da tutte e due le parti (fase B del piano esperienza d'acquisto).
 *
 * Fino a ieri `Order::` compariva SOLO dentro `OrderService`: non c'era una
 * rotta, una view o una voce di menu che mostrasse un ordine. Chi comprava lo
 * ritrovava come riga in *Movimenti*, in mezzo a bonifici e ricariche, senza
 * stato, senza indirizzo e senza un numero da citare; chi vendeva riceveva una
 * email e finiva li'.
 *
 * Due pagine, due punti di vista sullo stesso oggetto:
 *   - `/ordini`  — quello che ho comprato, a che punto e';
 *   - `/vendite` — quello che devo spedire.
 *
 * REGOLA CHE NON SI TOCCA: **cambiare stato non muove un centesimo.**
 * "In preparazione", "spedito" e "consegnato" sono etichette; l'addebito e'
 * gia' avvenuto alla cassa. L'unico stato che muove soldi e' l'annullamento,
 * e infatti qui dentro non c'e': arriva nel giro successivo, trattato come un
 * rimborso vero con le regole di saldo del venditore.
 */
class OrderController extends Controller
{
    // ─────────────────────────────────────────────────────────────────────────
    // Chi compra
    // ─────────────────────────────────────────────────────────────────────────

    public function index(Request $request): View|RedirectResponse
    {
        $user = $request->user();
        $account = $this->resolveAccount($user);

        if ($redirect = $this->redirectIfNoAccount($account, $user)) {
            return $redirect;
        }

        $ordini = Order::query()
            ->where('buyer_account_id', $account->id)
            ->with(['company', 'items', 'payment'])
            ->orderByDesc('placed_at')
            ->paginate(15);

        return view('portal.orders', [
            'pageTitle'      => 'I miei ordini',
            'currentAccount' => $account,
            'currentUser'    => $user,
            'ordini'         => $ordini,
            'activeNav'      => 'ordini',
        ]);
    }

    public function show(Request $request, Order $order): View|RedirectResponse
    {
        $user = $request->user();
        $account = $this->resolveAccount($user);

        if ($redirect = $this->redirectIfNoAccount($account, $user)) {
            return $redirect;
        }

        // Non un 404: chi prova l'uuid di un ordine altrui deve leggere che non
        // e' suo, e non deve vedere una riga di quell'ordine.
        abort_unless((int) $order->buyer_account_id === (int) $account->id, 403);

        return view('portal.order-show', [
            'pageTitle'      => 'Ordine ' . $order->numero,
            'currentAccount' => $account,
            'currentUser'    => $user,
            'order'          => $order->load(['company', 'items', 'payment', 'transfer']),
            'lato'           => 'compratore',
            'activeNav'      => 'ordini',
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Chi vende
    // ─────────────────────────────────────────────────────────────────────────

    public function sales(Request $request): View|RedirectResponse
    {
        $user = $request->user();
        $account = $this->resolveAccount($user);

        if ($redirect = $this->redirectIfNoAccount($account, $user)) {
            return $redirect;
        }

        if (! $account->company_id) {
            return redirect()->route('portal.dashboard')
                ->with('portal_error', 'Le vendite le vede chi ha un negozio nel circuito.');
        }

        // "Da lavorare" in cima, e dentro quel gruppo i piu' vecchi per primi:
        // in una lista di cose da spedire il piu' urgente e' quello che
        // aspetta da piu' tempo, non l'ultimo arrivato.
        $ordini = Order::query()
            ->where('company_id', $account->company_id)
            ->with(['items', 'buyerUser', 'payment'])
            ->orderByRaw("CASE WHEN `status` IN ('delivered','cancelled','refunded') THEN 1 ELSE 0 END")
            ->orderBy('placed_at')
            ->paginate(20);

        return view('portal.sales', [
            'pageTitle'      => 'Ordini ricevuti',
            'currentAccount' => $account,
            'currentUser'    => $user,
            'ordini'         => $ordini,
            'daLavorare'     => Order::query()
                ->where('company_id', $account->company_id)
                ->whereIn('status', [Order::STATUS_PAID, Order::STATUS_PREPARING])
                ->count(),
            'activeNav'      => 'vendite',
        ]);
    }

    public function sale(Request $request, Order $order): View|RedirectResponse
    {
        $user = $request->user();
        $account = $this->resolveAccount($user);

        if ($redirect = $this->redirectIfNoAccount($account, $user)) {
            return $redirect;
        }

        abort_unless($this->eIlVenditore($order, $account), 403);

        return view('portal.order-show', [
            'pageTitle'      => 'Ordine ' . $order->numero,
            'currentAccount' => $account,
            'currentUser'    => $user,
            'order'          => $order->load(['company', 'items', 'payment', 'transfer', 'buyerUser']),
            'lato'           => 'venditore',
            'activeNav'      => 'vendite',
        ]);
    }

    /**
     * Il venditore fa avanzare l'ordine.
     *
     * Non si fida di niente: lo stato chiesto dev'essere fra quelli che il
     * modello dichiara leciti DA QUELLO ATTUALE. Un POST con "consegnato" su
     * un ordine appena pagato viene rifiutato anche se il bottone non c'era.
     */
    public function updateStatus(Request $request, Order $order): RedirectResponse
    {
        $user = $request->user();
        $account = $this->resolveAccount($user);

        if ($redirect = $this->redirectIfNoAccount($account, $user)) {
            return $redirect;
        }

        abort_unless($this->eIlVenditore($order, $account), 403);

        $validated = $request->validate([
            'stato'         => ['required', Rule::in(array_keys(Order::STATUSES))],
            'carrier'       => ['nullable', 'string', 'max:60'],
            'tracking_code' => ['nullable', 'string', 'max:100'],
        ]);

        if (! $order->ilVenditorePuoPortarloA($validated['stato'])) {
            return back()->withInput()->with('portal_error',
                'Da "' . $order->status_label . '" non si può passare a "'
                . (Order::STATUSES[$validated['stato']] ?? $validated['stato'])
                . '". Se serve correggere un ordine, scrivi all\'assistenza del circuito.');
        }

        $precedente = $order->status;
        $campi = ['status' => $validated['stato']];

        if ($validated['stato'] === Order::STATUS_SHIPPED) {
            $campi['shipped_at'] = now();
            // Corriere e tracking si scrivono solo quando arrivano: un campo
            // lasciato vuoto non deve cancellare quello che c'era gia'.
            if (filled($validated['carrier'] ?? null)) {
                $campi['carrier'] = trim($validated['carrier']);
            }
            if (filled($validated['tracking_code'] ?? null)) {
                $campi['tracking_code'] = trim($validated['tracking_code']);
            }
        }

        if ($validated['stato'] === Order::STATUS_DELIVERED) {
            $campi['delivered_at'] = now();
        }

        $order->forceFill($campi)->save();

        $this->registraCambioDiStato($order, $precedente, $user, $request->ip());

        return back()->with('portal_success',
            'Ordine ' . $order->numero . ': ora è "' . $order->status_label . '".');
    }

    // ── Interno ──────────────────────────────────────────────────────────────

    /**
     * Chi ha segnato "spedito", e quando.
     *
     * Senza questo non si saprebbe mai: gli stati li cambiano il venditore E
     * l'admin (decisione di Laura), e il giorno che un compratore contesta una
     * consegna l'unica difesa e' sapere chi ha premuto quel bottone.
     */
    private function registraCambioDiStato(Order $order, string $precedente, $user, ?string $ip): void
    {
        AuditLog::create([
            'actor_user_id'  => $user->id,
            'event'          => 'order.status.changed',
            'auditable_type' => Order::class,
            'auditable_id'   => $order->id,
            'ip_address'     => $ip,
            'context'        => [
                'order_uuid'    => $order->uuid,
                'da'            => $precedente,
                'a'             => $order->status,
                'carrier'       => $order->carrier,
                'tracking_code' => $order->tracking_code,
            ],
        ]);
    }

    private function eIlVenditore(Order $order, Account $account): bool
    {
        return $account->company_id !== null
            && (int) $order->company_id === (int) $account->company_id;
    }

    private function resolveAccount($user): ?Account
    {
        return Account::operativoPer($user);
    }

    private function redirectIfNoAccount(?Account $currentAccount, $user): ?RedirectResponse
    {
        if ($currentAccount !== null) {
            return null;
        }

        return $user->canAccessBackoffice()
            ? redirect()->route('admin.listings.orders')->with('portal_error', 'Il tuo profilo di backoffice non ha un conto associato al circuito: gli ordini del circuito si vedono da qui.')
            : redirect()->route('portal.dashboard')->with('portal_error', 'Impossibile determinare il tuo conto.');
    }
}
