<?php

namespace App\Http\Controllers;

use App\Models\PaymentMandate;
use App\Models\PaymentMandateCharge;
use App\Services\PaymentMandateService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * "App collegate" — la pagina in cui l'utente vede, e può spegnere, i permessi
 * che ha dato alle applicazioni del circuito.
 *
 * È la contropartita del "un clic e paghi": un'autorizzazione che non si riesce
 * a trovare e a revocare in dieci secondi non è un'autorizzazione, è una
 * delega in bianco. Per questo la revoca **non** chiede lo step-up (spegnere
 * dev'essere sempre più facile che accendere), mentre alzare il tetto sì.
 */
class ConnectedAppsController extends Controller
{
    public function __construct(private readonly PaymentMandateService $mandates)
    {
    }

    public function index(Request $request): View
    {
        $user = $request->user();

        $mandates = PaymentMandate::query()
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->get();

        $charges = PaymentMandateCharge::query()
            ->whereIn('payment_mandate_id', $mandates->pluck('id'))
            // `mandatePaymentRequest` è presente solo sugli acquisti confermati
            // a mano: è così che l'elenco distingue i due casi senza una
            // seconda query per riga.
            ->with(['transfer', 'mandatePaymentRequest'])
            ->latest('id')
            ->limit(20)
            ->get();

        return view('portal.connected-apps.index', [
            'pageTitle' => 'App collegate',
            'activeNav' => 'connected-apps',
            'mandates'  => $mandates,
            'charges'   => $charges,
            'appNames'  => $this->appNames(),
            'minCap'    => (int) config('oauth.mandate.min_max_per_transaction', 100),
            'maxCap'    => (int) config('oauth.mandate.max_max_per_transaction', 100000),
        ]);
    }

    /**
     * Revoca immediata. Un clic, nessuna conferma di identità: da qui in poi
     * quell'applicazione dovrà chiedere all'utente ogni singolo pagamento.
     */
    public function revoke(Request $request, string $uuid): RedirectResponse
    {
        $mandate = $this->findOwned($request, $uuid);

        $this->mandates->revoke($mandate, $request->ip());

        return redirect()->route('portal.connected-apps.index')
            ->with('status', 'Autorizzazione revocata: da adesso ogni pagamento andrà confermato da te.');
    }

    /**
     * Riaccende un'autorizzazione che l'antifurto aveva sospeso da solo
     * (route protetta da step.up).
     *
     * Prima della 2b da quella sospensione non si usciva: si poteva solo
     * revocare e rifare tutto da capo, magari per un allarme che aveva fatto
     * scattare l'utente stesso comprando otto regali in mezz'ora. Sta dietro
     * allo step-up perché riaccendere è ridare un permesso, non toglierlo.
     */
    public function reactivate(Request $request, string $uuid): RedirectResponse
    {
        $mandate = $this->findOwned($request, $uuid);

        try {
            $this->mandates->reactivate($mandate, $request->ip());
        } catch (\RuntimeException $e) {
            return redirect()->route('portal.connected-apps.index')
                ->with('portal_error', $e->getMessage());
        }

        return redirect()->route('portal.connected-apps.index')
            ->with('status', 'Autorizzazione riattivata: i pagamenti in un clic sono di nuovo possibili entro il tuo tetto.');
    }

    /**
     * Cambia il tetto per singolo acquisto (route protetta da step.up).
     */
    public function updateLimit(Request $request, string $uuid): RedirectResponse
    {
        $mandate = $this->findOwned($request, $uuid);

        $validated = $request->validate([
            // Sia "50.00" sia "50,50": la virgola è come scrive la gente qui, e
            // `numeric` la scarterebbe in silenzio — è già successo una volta
            // sul prezzo dei prodotti shop (12/08), non succede più.
            'max_per_transaction' => ['required', 'string', 'regex:/^\\d{1,7}([.,]\\d{1,2})?$/'],
        ]);

        $cap = ky_to_cents((string) $validated['max_per_transaction']);

        $min = (int) config('oauth.mandate.min_max_per_transaction', 100);
        $max = (int) config('oauth.mandate.max_max_per_transaction', 100000);

        if ($cap < $min || $cap > $max) {
            return back()->withErrors([
                'max_per_transaction' => 'Il tetto deve essere compreso fra ' . ky_format($min) . ' e ' . ky_format($max) . ' KY.',
            ]);
        }

        $this->mandates->updateLimit($mandate, $cap, $request->ip());

        return redirect()->route('portal.connected-apps.index')
            ->with('status', 'Tetto aggiornato a ' . ky_format($cap) . ' KY per acquisto.');
    }

    // =========================================================================

    private function findOwned(Request $request, string $uuid): PaymentMandate
    {
        return PaymentMandate::query()
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
