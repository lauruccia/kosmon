<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\AuthorizesBackoffice;
use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Webhook;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Integrazione e-commerce gestita dall'admin per conto del negoziante.
 *
 * L'admin del circuito configura i plugin WooCommerce/Magento dei clienti
 * senza dover accedere con l'account del negozio: da /admin/companies/{id}
 * genera il token API (ability read+write) e il webhook payment_request.paid
 * dell'azienda, e incolla i valori nella configurazione del plugin sul sito
 * del cliente. Token e secret sono mostrati UNA SOLA VOLTA, come nel portale.
 */
class CompanyEcommerceController extends Controller
{
    use AuthorizesBackoffice;

    /** POST /admin/companies/{company}/ecommerce/tokens */
    public function createToken(Request $request, Company $company): RedirectResponse
    {
        $this->authorizeBackoffice($request->user());

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
        ]);

        [$raw, $hash, $prefix] = ApiToken::generateRaw();

        $token = ApiToken::create([
            'company_id'   => $company->id,
            'created_by'   => $request->user()->id,
            'name'         => $data['name'],
            'token_hash'   => $hash,
            'token_prefix' => $prefix,
            'abilities'    => ['read', 'write'],
            'expires_at'   => null,
        ]);

        AuditLog::create([
            'actor_user_id'  => $request->user()->id,
            'event'          => 'admin.company.api_token_created',
            'auditable_type' => ApiToken::class,
            'auditable_id'   => $token->id,
            'context'        => [
                'company_id'   => $company->id,
                'name'         => $token->name,
                'token_prefix' => $token->token_prefix,
                'abilities'    => $token->abilities,
            ],
        ]);

        // Token in chiaro in sessione: mostrato una sola volta nella card.
        return back()
            ->with('ecommerce_token_plain', $raw)
            ->with('portal_success', 'Token API creato per ' . $company->name . '. Copialo ora: non sarà più visibile.');
    }

    /** DELETE /admin/companies/{company}/ecommerce/tokens/{apiToken} */
    public function revokeToken(Request $request, Company $company, ApiToken $apiToken): RedirectResponse
    {
        $this->authorizeBackoffice($request->user());
        abort_unless($apiToken->company_id === $company->id, 404);

        AuditLog::create([
            'actor_user_id'  => $request->user()->id,
            'event'          => 'admin.company.api_token_revoked',
            'auditable_type' => ApiToken::class,
            'auditable_id'   => $apiToken->id,
            'context'        => [
                'company_id'   => $company->id,
                'name'         => $apiToken->name,
                'token_prefix' => $apiToken->token_prefix,
            ],
        ]);

        $apiToken->delete();

        return back()->with('portal_success', 'Token revocato. Il plugin che lo usava smetterà di funzionare.');
    }

    /** POST /admin/companies/{company}/ecommerce/webhooks */
    public function createWebhook(Request $request, Company $company): RedirectResponse
    {
        $this->authorizeBackoffice($request->user());

        $data = $request->validate([
            'url' => ['required', 'url', 'max:500'],
        ]);

        $webhook = Webhook::create([
            'company_id' => $company->id,
            'url'        => $data['url'],
            // Evento usato dai plugin e-commerce (WooCommerce/Magento).
            'events'     => ['payment_request.paid'],
        ]);

        AuditLog::create([
            'actor_user_id'  => $request->user()->id,
            'event'          => 'admin.company.webhook_created',
            'auditable_type' => Webhook::class,
            'auditable_id'   => $webhook->id,
            'context'        => [
                'company_id' => $company->id,
                'url'        => $webhook->url,
                'events'     => $webhook->events,
            ],
        ]);

        // Secret in sessione: mostrato una sola volta nella card.
        return back()
            ->with('ecommerce_webhook_secret', $webhook->secret)
            ->with('portal_success', 'Webhook creato per ' . $company->name . '. Copia il secret ora e incollalo nel plugin.');
    }

    /** POST /admin/companies/{company}/ecommerce/webhooks/{webhook}/toggle */
    public function toggleWebhook(Request $request, Company $company, Webhook $webhook): RedirectResponse
    {
        $this->authorizeBackoffice($request->user());
        abort_unless($webhook->company_id === $company->id, 404);

        $webhook->update([
            'is_active'     => ! $webhook->is_active,
            'failure_count' => $webhook->is_active ? $webhook->failure_count : 0,
        ]);

        AuditLog::create([
            'actor_user_id'  => $request->user()->id,
            'event'          => $webhook->is_active ? 'admin.company.webhook_enabled' : 'admin.company.webhook_disabled',
            'auditable_type' => Webhook::class,
            'auditable_id'   => $webhook->id,
            'context'        => ['company_id' => $company->id, 'url' => $webhook->url],
        ]);

        return back()->with('portal_success', 'Webhook ' . ($webhook->is_active ? 'riattivato' : 'disattivato') . '.');
    }

    /** DELETE /admin/companies/{company}/ecommerce/webhooks/{webhook} */
    public function deleteWebhook(Request $request, Company $company, Webhook $webhook): RedirectResponse
    {
        $this->authorizeBackoffice($request->user());
        abort_unless($webhook->company_id === $company->id, 404);

        AuditLog::create([
            'actor_user_id'  => $request->user()->id,
            'event'          => 'admin.company.webhook_deleted',
            'auditable_type' => Webhook::class,
            'auditable_id'   => $webhook->id,
            'context'        => ['company_id' => $company->id, 'url' => $webhook->url],
        ]);

        $webhook->delete();

        return back()->with('portal_success', 'Webhook eliminato.');
    }
}
