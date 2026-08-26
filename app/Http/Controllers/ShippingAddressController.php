<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\ShippingAddress;
use App\Services\ShippingAddressBook;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * La rubrica degli indirizzi di spedizione (fase A-bis, 26/08/2026).
 *
 * Prima il conto aveva un indirizzo solo, dentro al form del profilo: chi
 * spediva a casa e in ufficio doveva riscriverlo ogni volta. Adesso ne tiene
 * fino a 10, con un'etichetta e uno predefinito.
 *
 * Qui non si scrive mai direttamente sul database: ogni operazione passa da
 * ShippingAddressBook, che e' il posto dove sta scritto che il predefinito e'
 * uno solo e che `accounts.shipping_*` ne resta la copia.
 */
class ShippingAddressController extends Controller
{
    public function __construct(
        private readonly ShippingAddressBook $rubrica,
    ) {
    }

    public function index(Request $request): View|RedirectResponse
    {
        $user = $request->user();
        $account = $this->resolveAccount($user);

        if ($redirect = $this->redirectIfNoAccount($account)) {
            return $redirect;
        }

        $indirizzi = $this->rubrica->elenco($account);

        return view('portal.shipping-addresses', [
            'pageTitle'      => 'I tuoi indirizzi di spedizione',
            'currentAccount' => $account,
            'currentUser'    => $user,
            'indirizzi'      => $indirizzi,
            'tetto'          => ShippingAddress::MAX_PER_ACCOUNT,
            'puoAggiungere'  => $indirizzi->count() < ShippingAddress::MAX_PER_ACCOUNT,
            'ritorno'        => $this->ritornoSicuro($request),
            'activeNav'      => 'profilo',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        $account = $this->resolveAccount($user);

        if ($redirect = $this->redirectIfNoAccount($account)) {
            return $redirect;
        }

        $dati = $request->validate($this->regole());

        try {
            $this->rubrica->aggiungi($account, $dati, $request->boolean('is_default'));
        } catch (\RuntimeException $e) {
            return back()->withInput()->with('portal_error', $e->getMessage());
        }

        return $this->tornaIndietro($request, 'Indirizzo aggiunto.');
    }

    public function update(Request $request, ShippingAddress $shippingAddress): RedirectResponse
    {
        $user = $request->user();
        $account = $this->resolveAccount($user);

        if ($redirect = $this->redirectIfNoAccount($account)) {
            return $redirect;
        }

        $dati = $request->validate($this->regole());

        try {
            $this->rubrica->modifica($account, $shippingAddress, $dati);
        } catch (\RuntimeException $e) {
            return back()->with('portal_error', $e->getMessage());
        }

        return $this->tornaIndietro($request, 'Indirizzo aggiornato.');
    }

    public function makeDefault(Request $request, ShippingAddress $shippingAddress): RedirectResponse
    {
        $user = $request->user();
        $account = $this->resolveAccount($user);

        if ($redirect = $this->redirectIfNoAccount($account)) {
            return $redirect;
        }

        try {
            $this->rubrica->rendiPredefinito($account, $shippingAddress);
        } catch (\RuntimeException $e) {
            return back()->with('portal_error', $e->getMessage());
        }

        return $this->tornaIndietro($request, 'Indirizzo predefinito aggiornato.');
    }

    public function destroy(Request $request, ShippingAddress $shippingAddress): RedirectResponse
    {
        $user = $request->user();
        $account = $this->resolveAccount($user);

        if ($redirect = $this->redirectIfNoAccount($account)) {
            return $redirect;
        }

        try {
            $this->rubrica->elimina($account, $shippingAddress);
        } catch (\RuntimeException $e) {
            return back()->with('portal_error', $e->getMessage());
        }

        return $this->tornaIndietro($request, 'Indirizzo eliminato. Gli ordini già fatti restano invariati.');
    }

    // ── Interno ──────────────────────────────────────────────────────────────

    /** @return array<string, array<int, string>> */
    private function regole(): array
    {
        return [
            'label'          => ['nullable', 'string', 'max:60'],
            'recipient_name' => ['required', 'string', 'max:150'],
            'address'        => ['required', 'string', 'max:255'],
            'city'           => ['required', 'string', 'max:100'],
            'postal_code'    => ['required', 'string', 'max:12'],
            'province'       => ['nullable', 'string', 'max:60'],
            'phone'          => ['nullable', 'string', 'max:30'],
        ];
    }

    /**
     * Da dove si e' arrivati: dalla cassa si torna alla cassa, dal profilo si
     * resta in rubrica. Solo percorsi RELATIVI e solo quelli che conosciamo,
     * altrimenti diventa un open redirect.
     */
    private function ritornoSicuro(Request $request): ?string
    {
        $ammessi = [
            route('portal.cart.checkout.form', [], false),
            route('portal.cart', [], false),
        ];

        $chiesto = (string) $request->input('redirect_to', $request->query('redirect_to', ''));

        return in_array($chiesto, $ammessi, true) ? $chiesto : null;
    }

    private function tornaIndietro(Request $request, string $messaggio): RedirectResponse
    {
        $ritorno = $this->ritornoSicuro($request);

        return ($ritorno !== null ? redirect()->to($ritorno) : redirect()->route('portal.shipping-addresses.index'))
            ->with('portal_success', $messaggio);
    }

    private function resolveAccount($user): ?Account
    {
        return Account::operativoPer($user);
    }

    private function redirectIfNoAccount(?Account $account): ?RedirectResponse
    {
        return $account !== null
            ? null
            : redirect()->route('portal.dashboard')->with('portal_error', 'Impossibile determinare il tuo conto.');
    }
}
