<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ManagesPaymentGateways;
use App\Models\PaymentGateway;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Self-service azienda: ogni azienda configura i propri metodi di
 * pagamento EUR (Stripe, PayPal, Bonifico) per incassare la quota EUR dei
 * prodotti shop con mix KY/EUR — vedi PaymentGateway per il perché queste
 * sono credenziali dell'account INDIPENDENTE dell'azienda, non un conto
 * "figlio" di Kosmopay.
 */
class PaymentGatewayController extends Controller
{
    use ManagesPaymentGateways;

    /** GET /azienda/pagamenti */
    public function index(Request $request): View
    {
        $user = $request->user();
        $company = $user->company;

        abort_unless($company, 403, 'Nessuna azienda associata al tuo profilo.');
        abort_unless($user->canAccessMarketplace() || $user->is_super_admin, 403);

        $gateways = PaymentGateway::query()
            ->where('company_id', $company->id)
            ->get()
            ->keyBy('provider');

        return view('portal.payment-gateways', [
            'pageTitle'    => 'Metodi di pagamento EUR',
            'company'      => $company,
            'gateways'     => $gateways,
            'providers'    => PaymentGateway::PROVIDERS,
            'fields'       => PaymentGateway::CREDENTIAL_FIELDS,
            'activeNav'    => 'shop',
            'updateRoute'  => 'portal.payment-gateways.update',
            'toggleRoute'  => 'portal.payment-gateways.toggle',
            'destroyRoute' => 'portal.payment-gateways.destroy',
            'routeParams'  => [],
        ]);
    }

    /** POST /azienda/pagamenti/{provider} */
    public function update(Request $request, string $provider): RedirectResponse
    {
        $user = $request->user();
        $company = $user->company;
        abort_unless($company, 403);
        abort_unless($user->canAccessMarketplace() || $user->is_super_admin, 403);

        $this->saveGatewayFromRequest($request, $company, $provider, $user);

        return redirect()->route('portal.payment-gateways.index')
            ->with('portal_success', ucfirst(PaymentGateway::PROVIDERS[$provider] ?? $provider) . ' configurato correttamente.');
    }

    /** POST /azienda/pagamenti/{provider}/attiva */
    public function toggleActive(Request $request, string $provider): RedirectResponse
    {
        $user = $request->user();
        $company = $user->company;
        abort_unless($company, 403);
        abort_unless($user->canAccessMarketplace() || $user->is_super_admin, 403);

        $gateway = $this->findCompanyGatewayOrFail($company, $provider);
        $gateway->update(['is_active' => ! $gateway->is_active, 'updated_by_user_id' => $user->id]);

        return back()->with('portal_success', $gateway->is_active ? 'Metodo riattivato.' : 'Metodo disattivato.');
    }

    /** DELETE /azienda/pagamenti/{provider} */
    public function destroy(Request $request, string $provider): RedirectResponse
    {
        $user = $request->user();
        $company = $user->company;
        abort_unless($company, 403);
        abort_unless($user->canAccessMarketplace() || $user->is_super_admin, 403);

        $this->findCompanyGatewayOrFail($company, $provider)->delete();

        return back()->with('portal_success', 'Metodo di pagamento rimosso.');
    }
}
