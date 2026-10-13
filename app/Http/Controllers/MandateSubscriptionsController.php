<?php

namespace App\Http\Controllers;

use App\Models\MandateSubscription;
use App\Models\PaymentMandate;
use App\Models\PaymentMandateCharge;
use App\Services\MandateSubscriptionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * "Abbonamenti" — tutto ciò che i portali del circuito addebitano ogni mese
 * sul conto dell'utente, in un posto solo, con il pulsante per fermarlo.
 *
 * È la pagina gemella di "App collegate" e ne ha la stessa regola: bloccare è
 * un clic senza cerimonie, sbloccare chiede lo step-up. Un abbonamento che si
 * fatica a fermare non è un servizio, è una trappola.
 *
 * Qui non si annulla niente sul portale: l'abbonamento è del portale (prodotti,
 * calendario, spedizione) e lo si annulla lì. Il blocco dice solo a KMoney di
 * non pagarlo più.
 */
class MandateSubscriptionsController extends Controller
{
    public function __construct(private readonly MandateSubscriptionService $subscriptions)
    {
    }

    public function index(Request $request): View
    {
        $user = $request->user();

        $subscriptions = MandateSubscription::query()
            ->where('user_id', $user->id)
            ->withCount('charges')
            ->orderByDesc('id')
            ->get();

        // Prima ciò che è ancora in piedi, poi i bloccati, poi gli annullati:
        // l'utente guarda quasi sempre la prima riga.
        $subscriptions = $subscriptions->sortBy(fn (MandateSubscription $s) => match (true) {
            $s->isBlocked()   => 1,
            $s->isCancelled() => 2,
            default           => 0,
        })->values();

        $live = $subscriptions->filter(fn (MandateSubscription $s) => $s->isLive());

        $active = $live->filter(fn (MandateSubscription $s) => $s->status === MandateSubscription::ACTIVE);
        $paused = $live->filter(fn (MandateSubscription $s) => $s->status === MandateSubscription::PAUSED);

        $next = $active
            ->filter(fn (MandateSubscription $s) => $s->next_charge_at !== null)
            ->sortBy('next_charge_at')
            ->first();

        // Quanto pesano ogni mese gli abbonamenti attivi: un anno diviso per
        // dodici, una cadenza bimestrale per due. Solo di quelli che il portale
        // ha dichiarato con un importo.
        $monthly = (int) round($active->sum(
            fn (MandateSubscription $s) => $s->amount !== null
                ? $s->amount / max(1, (int) $s->interval_months)
                : 0
        ));

        $charges = PaymentMandateCharge::query()
            ->whereIn('mandate_subscription_id', $subscriptions->pluck('id'))
            ->with(['subscription', 'transfer'])
            ->latest('id')
            ->limit(20)
            ->get();

        // Le applicazioni che hanno ancora un'autorizzazione viva: senza,
        // l'addebito non parte da solo e l'utente deve saperlo.
        $authorizedClients = PaymentMandate::query()
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->whereNull('suspended_at')
            ->where('expires_at', '>', now())
            ->pluck('client_id')
            ->all();

        return view('portal.subscriptions.index', [
            'pageTitle'         => 'Abbonamenti',
            'activeNav'         => 'connected-apps',
            'subscriptions'     => $subscriptions,
            'activeCount'       => $active->count(),
            'pausedCount'       => $paused->count(),
            'next'              => $next,
            'monthly'           => $monthly,
            'charges'           => $charges,
            'authorizedClients' => $authorizedClients,
            'appNames'          => $this->appNames(),
        ]);
    }

    /**
     * Blocca. Un clic, nessuna conferma di identità.
     */
    public function block(Request $request, string $uuid): RedirectResponse
    {
        $subscription = $this->findOwned($request, $uuid);

        $this->subscriptions->block($subscription, $request->ip());

        return redirect()->route('portal.subscriptions.index')
            ->with('status', 'Abbonamento bloccato: da adesso KMoney rifiuta ogni suo addebito. Per smettere del tutto annullalo anche sul sito.');
    }

    /**
     * Sblocca (route protetta da step.up): è ridare un permesso.
     */
    public function unblock(Request $request, string $uuid): RedirectResponse
    {
        $subscription = $this->findOwned($request, $uuid);

        $this->subscriptions->unblock($subscription, $request->ip());

        return redirect()->route('portal.subscriptions.index')
            ->with('status', 'Abbonamento sbloccato: i prossimi addebiti potranno partire.');
    }

    // =========================================================================

    private function findOwned(Request $request, string $uuid): MandateSubscription
    {
        return MandateSubscription::query()
            ->where('uuid', $uuid)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();
    }

    /**
     * @return array<string, string>
     */
    private function appNames(): array
    {
        $names = [];

        foreach ((array) config('oauth.clients', []) as $client) {
            if (! empty($client['client_id'])) {
                $names[$client['client_id']] = (string) ($client['name'] ?? $client['client_id']);
            }
        }

        return $names;
    }
}
