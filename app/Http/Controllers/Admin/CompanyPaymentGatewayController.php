<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\AuthorizesBackoffice;
use App\Http\Controllers\Concerns\ManagesPaymentGateways;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\PaymentGateway;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Metodi di pagamento EUR configurati dall'admin PER CONTO di un'azienda —
 * stesso principio di CompanyEcommerceController (l'admin del circuito può
 * impostare le credenziali dell'account Stripe/PayPal/IBAN indipendente
 * dell'azienda senza dover accedere con il suo profilo). Sezione inclusa
 * nella pagina /admin/companies/{company}.
 */
class CompanyPaymentGatewayController extends Controller
{
    use AuthorizesBackoffice;
    use ManagesPaymentGateways;

    /** POST /admin/companies/{company}/pagamenti/{provider} */
    public function update(Request $request, Company $company, string $provider): RedirectResponse
    {
        $this->authorizeBackoffice($request->user());

        $this->saveGatewayFromRequest($request, $company, $provider, $request->user());

        return back()->with('portal_success', ucfirst(PaymentGateway::PROVIDERS[$provider] ?? $provider) . ' configurato per ' . $company->name . '.');
    }

    /** POST /admin/companies/{company}/pagamenti/{provider}/attiva */
    public function toggleActive(Request $request, Company $company, string $provider): RedirectResponse
    {
        $this->authorizeBackoffice($request->user());

        $gateway = $this->findCompanyGatewayOrFail($company, $provider);
        $gateway->update(['is_active' => ! $gateway->is_active, 'updated_by_user_id' => $request->user()->id]);

        return back()->with('portal_success', $gateway->is_active ? 'Metodo riattivato.' : 'Metodo disattivato.');
    }

    /** DELETE /admin/companies/{company}/pagamenti/{provider} */
    public function destroy(Request $request, Company $company, string $provider): RedirectResponse
    {
        $this->authorizeBackoffice($request->user());

        $this->findCompanyGatewayOrFail($company, $provider)->delete();

        return back()->with('portal_success', 'Metodo di pagamento rimosso.');
    }
}
