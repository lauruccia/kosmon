<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\OrderReturnRequest;
use App\Models\Transfer;
use App\Notifications\OrderCancelledNotification;
use App\Notifications\OrderReturnDecidedNotification;
use App\Notifications\OrderReturnRequestedNotification;
use App\Notifications\OrderShippedNotification;
use App\Services\OrderService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
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
            ->with(['company', 'items', 'payment', 'returnRequests'])
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
            'order'          => $order->load(['company', 'items', 'payment', 'transfer', 'returnRequests.decidedBy']),
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
        $eAdmin = (bool) $user->canAccessBackoffice();

        // L'admin gestisce gli ordini PER CONTO delle aziende (richiesta di
        // Laura, 27/08): entra da questa stessa pagina e li vede tutti. Non
        // serve che abbia un conto operativo, e infatti la guardia qui sotto
        // non lo riguarda.
        if (! $eAdmin && ($redirect = $this->redirectIfNoAccount($account, $user))) {
            return $redirect;
        }

        if (! $eAdmin && ! $account->company_id) {
            return redirect()->route('portal.dashboard')
                ->with('portal_error', 'Le vendite le vede chi ha un negozio nel circuito.');
        }

        $azienda = $eAdmin ? $request->integer('company') ?: null : (int) $account->company_id;

        // "Da lavorare" in cima, e dentro quel gruppo i piu' vecchi per primi:
        // in una lista di cose da spedire il piu' urgente e' quello che
        // aspetta da piu' tempo, non l'ultimo arrivato.
        $ordini = Order::query()
            ->when($azienda, fn ($q) => $q->where('company_id', $azienda))
            ->with(['items', 'buyerUser', 'payment', 'company', 'returnRequests'])
            ->orderByRaw("CASE WHEN `status` IN ('delivered','cancelled','refunded') THEN 1 ELSE 0 END")
            ->orderBy('placed_at')
            ->paginate(20)
            ->withQueryString();

        return view('portal.sales', [
            'pageTitle'      => $eAdmin ? 'Ordini dei negozi' : 'Ordini ricevuti',
            'currentAccount' => $account,
            'currentUser'    => $user,
            'ordini'         => $ordini,
            'eAdmin'         => $eAdmin,
            // Il filtro per azienda serve solo all'admin: il venditore vede
            // gia' soltanto i propri.
            'aziende'        => $eAdmin
                ? \App\Models\Company::query()
                    ->whereIn('id', Order::query()->select('company_id'))
                    ->orderBy('name')
                    ->get(['id', 'name'])
                : collect(),
            'aziendaScelta'  => $azienda,
            'daLavorare'     => Order::query()
                ->when($azienda, fn ($q) => $q->where('company_id', $azienda))
                ->whereIn('status', [Order::STATUS_PAID, Order::STATUS_PREPARING])
                ->count(),
            // Una pratica di reso aperta e' l'unica cosa in questa pagina che
            // ha una controparte che aspetta una risposta: va contata a parte
            // dagli ordini da spedire, perche' e' piu' urgente.
            'resiDaRispondere' => \App\Models\OrderReturnRequest::query()
                ->where('status', \App\Models\OrderReturnRequest::STATUS_PENDING)
                ->whereHas('order', fn ($q) => $azienda ? $q->where('company_id', $azienda) : $q)
                ->count(),
            'activeNav'      => 'vendite',
        ]);
    }

    public function sale(Request $request, Order $order): View|RedirectResponse
    {
        $user = $request->user();
        $account = $this->resolveAccount($user);

        if (! $user->canAccessBackoffice() && ($redirect = $this->redirectIfNoAccount($account, $user))) {
            return $redirect;
        }

        $eAdmin = (bool) $user->canAccessBackoffice();

        abort_unless($eAdmin || $this->eIlVenditore($order, $account), 403);

        return view('portal.order-show', [
            'pageTitle'      => 'Ordine ' . $order->numero,
            'currentAccount' => $account,
            'currentUser'    => $user,
            'order'          => $order->load(['company', 'items', 'payment', 'transfer', 'buyerUser', 'returnRequests.requestedBy']),
            'lato'           => 'venditore',
            'eAdmin'         => $eAdmin,
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
        $eAdmin = (bool) $user->canAccessBackoffice();

        if (! $eAdmin && ($redirect = $this->redirectIfNoAccount($account, $user))) {
            return $redirect;
        }

        abort_unless($eAdmin || $this->eIlVenditore($order, $account), 403);

        $validated = $request->validate([
            'stato'         => ['required', Rule::in(array_keys(Order::STATUSES))],
            'carrier'       => ['nullable', 'string', 'max:60'],
            'tracking_code' => ['nullable', 'string', 'max:100'],
        ]);

        // Due regole diverse di proposito: il venditore va solo avanti,
        // l'admin si muove liberamente dentro gli stati di consegna. E' cosi'
        // che una correzione ha sempre un responsabile - e infatti finisce in
        // AuditLog con scritto chi era.
        $ammesso = $eAdmin
            ? $order->lAdminPuoPortarloA($validated['stato'])
            : $order->ilVenditorePuoPortarloA($validated['stato']);

        if (! $ammesso) {
            return back()->withInput()->with('portal_error',
                'Da "' . $order->status_label . '" non si può passare a "'
                . (Order::STATUSES[$validated['stato']] ?? $validated['stato'])
                . '".' . ($eAdmin
                    ? ' Annullamenti e rimborsi non passano da qui: muovono denaro.'
                    : ' Se serve correggere un ordine, scrivi all\'assistenza del circuito.'));
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

        // L'admin puo' tornare indietro, e allora le date davanti non valgono
        // piu': un ordine riportato a "in preparazione" che si porta dietro una
        // data di consegna racconta una storia falsa nella cronologia.
        if ($eAdmin) {
            $indice = array_search($validated['stato'], Order::STATI_DI_CONSEGNA, true);

            if ($indice < array_search(Order::STATUS_SHIPPED, Order::STATI_DI_CONSEGNA, true)) {
                $campi['shipped_at'] = null;
            }

            if ($indice < array_search(Order::STATUS_DELIVERED, Order::STATI_DI_CONSEGNA, true)) {
                $campi['delivered_at'] = null;
            }
        }

        $order->forceFill($campi)->save();

        $this->registraCambioDiStato($order, $precedente, $user, $request->ip(), $eAdmin);

        // "Spedito" e' l'unica notizia che interessa davvero a chi ha comprato
        // (fase C): gli altri passaggi restano visibili nella sua pagina ordini
        // senza bisogno di una email. Solo in AVANTI - se l'admin riporta un
        // ordine a "in preparazione" e poi di nuovo a "spedito" il cliente
        // riceve un secondo avviso, ed e' giusto: e' ripartito davvero.
        if ($order->status === Order::STATUS_SHIPPED && $precedente !== Order::STATUS_SHIPPED) {
            $this->avvisaCheEPartito($order);
        }

        return back()->with('portal_success',
            'Ordine ' . $order->numero . ': ora è "' . $order->status_label . '".');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Quando l'ordine torna indietro (giro 2, 27/08/2026)
    // ─────────────────────────────────────────────────────────────────────────
    //
    // Da qui in giu' si muove denaro. Le regole di chi-puo'-cosa non sono
    // simmetriche ed e' voluto: ANNULLA il venditore (e l'admin per conto
    // suo), CHIEDE IL RESO il compratore, RISPONDE il venditore. Nessuno puo'
    // prendere soldi dal conto di un altro senza il suo assenso.

    /**
     * Il venditore annulla l'ordine: i KY tornano al cliente.
     *
     * Il motivo e' obbligatorio. Non e' burocrazia: e' la riga che il
     * compratore legge nella mail al posto di una telefonata, ed e' anche
     * quello che l'assistenza legge fra sei mesi quando qualcuno contesta.
     */
    public function cancel(Request $request, Order $order): RedirectResponse
    {
        $user    = $request->user();
        $account = $this->resolveAccount($user);
        $eAdmin  = (bool) $user->canAccessBackoffice();

        if (! $eAdmin && ($redirect = $this->redirectIfNoAccount($account, $user))) {
            return $redirect;
        }

        abort_unless($eAdmin || ($account && $this->eIlVenditore($order, $account)), 403);

        $validated = $request->validate([
            'motivo' => ['required', 'string', 'min:3', 'max:300'],
        ], [
            'motivo.required' => 'Scrivi perché stai annullando: lo leggerà il cliente.',
            'motivo.min'      => 'Il motivo è troppo corto per dire qualcosa al cliente.',
        ]);

        try {
            $ordine = app(OrderService::class)->annulla(
                $order,
                $user,
                trim($validated['motivo']),
                $request->ip()
            );
        } catch (\RuntimeException $e) {
            return back()->withInput()->with('portal_error', $e->getMessage());
        }

        $rimborsati = (int) (Transfer::query()->find($ordine->refund_transfer_id)?->amount ?? 0);

        AuditLog::create([
            'actor_user_id'  => $user->id,
            'event'          => 'order.cancelled',
            'auditable_type' => Order::class,
            'auditable_id'   => $ordine->id,
            'ip_address'     => $request->ip(),
            'context'        => [
                'order_uuid'            => $ordine->uuid,
                'motivo'                => $ordine->cancel_reason,
                'rimborso_ky'           => $rimborsati,
                'refund_transfer_id'    => $ordine->refund_transfer_id,
                'per_conto_del_negozio' => $eAdmin,
                'company_id'            => $ordine->company_id,
            ],
        ]);

        $this->avvisa(
            $ordine->buyerUser,
            fn () => new OrderCancelledNotification($ordine->fresh(['company', 'payment', 'items']), $rimborsati),
            'order.cancelled.notify_failed',
            $ordine->id
        );

        $messaggio = 'Ordine ' . $ordine->numero . ' annullato.';

        if ($rimborsati > 0) {
            $messaggio .= ' Restituiti ' . ky_format($rimborsati) . ' KY al cliente, merce di nuovo in magazzino.';
        }

        // La quota in euro gia' incassata non passa dal circuito: nessuno qui
        // dentro puo' restituirla, e non dirlo lascerebbe il venditore
        // convinto di aver chiuso la pratica.
        if ($ordine->hasEuroQuota() && $ordine->payment()->first()?->status === \App\Models\MarketplaceOrderPayment::STATUS_PAID) {
            $messaggio .= ' Attenzione: la quota di '
                . number_format($ordine->total_eur / 100, 2, ',', '.')
                . ' € era già stata incassata fuori dal circuito — va restituita separatamente.';
        }

        return redirect()->route('portal.sales.show', $ordine)->with('portal_success', $messaggio);
    }

    /**
     * Il compratore chiede di restituire un ordine che ha gia' ricevuto.
     *
     * Apre una pratica, non muove soldi: il conto del venditore non si tocca
     * finche' non e' lui (o l'admin) a dire di si'.
     */
    public function requestReturn(Request $request, Order $order): RedirectResponse
    {
        $user    = $request->user();
        $account = $this->resolveAccount($user);

        if ($redirect = $this->redirectIfNoAccount($account, $user)) {
            return $redirect;
        }

        abort_unless((int) $order->buyer_account_id === (int) $account->id, 403);

        $validated = $request->validate([
            'motivo' => ['required', 'string', 'min:10', 'max:500'],
        ], [
            'motivo.required' => 'Spiega al venditore perché vuoi restituire l\'ordine.',
            'motivo.min'      => 'Scrivi qualche parola in più: il venditore deve capire il problema per poterti rispondere.',
        ]);

        try {
            $richiesta = app(OrderService::class)->chiediReso($order, $user, trim($validated['motivo']));
        } catch (\RuntimeException $e) {
            return back()->withInput()->with('portal_error', $e->getMessage());
        }

        AuditLog::create([
            'actor_user_id'  => $user->id,
            'event'          => 'order.return.requested',
            'auditable_type' => OrderReturnRequest::class,
            'auditable_id'   => $richiesta->id,
            'ip_address'     => $request->ip(),
            'context'        => [
                'order_uuid' => $order->uuid,
                'company_id' => $order->company_id,
                'motivo'     => $richiesta->reason,
            ],
        ]);

        $this->avvisa(
            $order->company?->primaryBusinessAccount()?->ownerUser,
            fn () => new OrderReturnRequestedNotification($richiesta->load('order.items', 'order.company')),
            'order.return.notify_failed',
            $order->id
        );

        return back()->with('portal_success',
            'Richiesta di reso inviata. Il venditore riceve un avviso e ti risponde da qui: vedrai l\'esito su questa pagina.');
    }

    /**
     * Il venditore risponde alla richiesta di reso.
     *
     * Accettare muove denaro (e allora valgono le regole del saldo e del
     * fido); rifiutare no, ma richiede il perche' scritto - un rifiuto muto
     * e' il modo piu' rapido di far finire la lite fuori dal circuito.
     */
    public function decideReturn(Request $request, Order $order, OrderReturnRequest $richiesta): RedirectResponse
    {
        $user    = $request->user();
        $account = $this->resolveAccount($user);
        $eAdmin  = (bool) $user->canAccessBackoffice();

        if (! $eAdmin && ($redirect = $this->redirectIfNoAccount($account, $user))) {
            return $redirect;
        }

        abort_unless($eAdmin || ($account && $this->eIlVenditore($order, $account)), 403);

        // La pratica dev'essere DI questo ordine: un uuid preso da un'altra
        // pagina non deve poter chiudere il reso di qualcun altro.
        abort_unless((int) $richiesta->order_id === (int) $order->id, 404);

        $validated = $request->validate([
            'esito' => ['required', Rule::in([OrderReturnRequest::STATUS_ACCEPTED, OrderReturnRequest::STATUS_REJECTED])],
            'nota'  => ['nullable', 'string', 'max:500', Rule::requiredIf(fn () => $request->input('esito') === OrderReturnRequest::STATUS_REJECTED)],
        ], [
            'nota.required' => 'Se rifiuti il reso devi scrivere il motivo: lo legge il cliente.',
        ]);

        $accetta = $validated['esito'] === OrderReturnRequest::STATUS_ACCEPTED;
        $nota    = filled($validated['nota'] ?? null) ? trim($validated['nota']) : null;

        try {
            if ($accetta) {
                $ordine = app(OrderService::class)->accettaReso($richiesta, $user, $nota, $eAdmin, $request->ip());
            } else {
                app(OrderService::class)->rifiutaReso($richiesta, $user, (string) $nota, $eAdmin);
                $ordine = $order->fresh();
            }
        } catch (\RuntimeException $e) {
            return back()->withInput()->with('portal_error', $e->getMessage());
        }

        $rimborsati = $accetta
            ? (int) (Transfer::query()->find($ordine->refund_transfer_id)?->amount ?? 0)
            : 0;

        AuditLog::create([
            'actor_user_id'  => $user->id,
            'event'          => 'order.return.decided',
            'auditable_type' => OrderReturnRequest::class,
            'auditable_id'   => $richiesta->id,
            'ip_address'     => $request->ip(),
            'context'        => [
                'order_uuid'            => $ordine->uuid,
                'esito'                 => $validated['esito'],
                'nota'                  => $nota,
                'rimborso_ky'           => $rimborsati,
                'per_conto_del_negozio' => $eAdmin,
                'company_id'            => $ordine->company_id,
            ],
        ]);

        $aggiornata = $richiesta->fresh(['order.items', 'order.company']);

        $this->avvisa(
            $ordine->buyerUser,
            fn () => new OrderReturnDecidedNotification($aggiornata, $rimborsati),
            'order.return.decided.notify_failed',
            $ordine->id
        );

        $messaggio = $accetta
            ? 'Reso accettato: ' . ($rimborsati > 0 ? ky_format($rimborsati) . ' KY restituiti al cliente e merce' : 'merce')
                . ' di nuovo in magazzino.'
            : 'Reso rifiutato. Il cliente riceve la tua risposta con il motivo che hai scritto.';

        if ($accetta && $ordine->hasEuroQuota() && $ordine->payment()->first()?->status === \App\Models\MarketplaceOrderPayment::STATUS_PAID) {
            $messaggio .= ' Attenzione: la quota di '
                . number_format($ordine->total_eur / 100, 2, ',', '.')
                . ' € era già stata incassata fuori dal circuito — va restituita separatamente.';
        }

        return back()->with('portal_success', $messaggio);
    }

    // ── Interno ──────────────────────────────────────────────────────────────

    /**
     * Chi ha segnato "spedito", e quando.
     *
     * Senza questo non si saprebbe mai: gli stati li cambiano il venditore E
     * l'admin (decisione di Laura), e il giorno che un compratore contesta una
     * consegna l'unica difesa e' sapere chi ha premuto quel bottone.
     */
    private function registraCambioDiStato(Order $order, string $precedente, $user, ?string $ip, bool $eAdmin = false): void
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
                // Chi ha corretto per conto del negozio, e chi invece stava
                // gestendo i propri ordini: a distanza di mesi e' la
                // differenza che conta.
                'per_conto_del_negozio' => $eAdmin,
                'company_id'            => $order->company_id,
            ],
        ]);
    }

    /**
     * Avvisa chi ha comprato che il pacco e' partito.
     *
     * Fuori da qualsiasi transazione e con la sua rete di sicurezza: una email
     * che non parte non deve impedire al venditore di segnare l'ordine come
     * spedito. Lo stato e' il fatto, la notifica e' la cortesia.
     */
    private function avvisaCheEPartito(Order $order): void
    {
        $destinatario = $order->buyerUser;

        if (! $destinatario) {
            return;
        }

        try {
            $destinatario->notify(new OrderShippedNotification($order));
        } catch (\Throwable $e) {
            Log::error('order.shipped.notify_failed', [
                'order_id' => $order->id,
                'error'    => $e->getMessage(),
            ]);
        }
    }

    /**
     * Manda una notifica senza che un guasto alla posta possa far fallire
     * quello che e' gia' successo.
     *
     * I fatti di questo controller - un ordine annullato, dei KY tornati
     * indietro - sono gia' scritti quando si arriva qui. Una mail che non
     * parte e' un fastidio; una eccezione che risale sarebbe un utente che
     * vede un errore su un'operazione andata a buon fine, e che la rifa'.
     *
     * La notifica arriva come closure e non gia' costruita: se anche il solo
     * costruirla fallisse (una relazione mancante, un ordine senza azienda),
     * il guaio resterebbe dentro il try.
     */
    private function avvisa(?\App\Models\User $destinatario, \Closure $costruisci, string $etichetta, int $orderId): void
    {
        if (! $destinatario) {
            return;
        }

        try {
            $destinatario->notify($costruisci());
        } catch (\Throwable $e) {
            Log::error($etichetta, [
                'order_id' => $orderId,
                'error'    => $e->getMessage(),
            ]);
        }
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
