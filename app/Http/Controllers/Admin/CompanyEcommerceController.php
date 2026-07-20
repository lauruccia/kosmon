<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\AuthorizesBackoffice;
use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\EcommercePairing;
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

    /**
     * POST /admin/companies/{company}/ecommerce/pairings/{pairing}/approve
     *
     * Approva la richiesta di collegamento inviata dal plugin col solo numero
     * di conto: crea token API (read+write) e webhook payment_request.paid e
     * lascia le credenziali cifrate sul pairing, che il plugin ritirerà da
     * solo (una sola volta) alla prossima verifica. L'admin non deve copiare
     * o incollare nulla.
     */
    public function approvePairing(Request $request, Company $company, EcommercePairing $pairing): RedirectResponse
    {
        $this->authorizeBackoffice($request->user());
        abort_unless($pairing->company_id === $company->id, 404);

        if (! $pairing->isPending()) {
            return back()->with('portal_error', 'Questa richiesta di collegamento è già stata gestita.');
        }

        $host = parse_url($pairing->site_url, PHP_URL_HOST) ?: $pairing->site_url;

        [$raw, $hash, $prefix] = ApiToken::generateRaw();

        $token = ApiToken::create([
            'company_id'   => $company->id,
            'created_by'   => $request->user()->id,
            'name'         => 'Plugin ' . ucfirst($pairing->platform) . ' — ' . $host,
            'token_hash'   => $hash,
            'token_prefix' => $prefix,
            'abilities'    => ['read', 'write'],
            'expires_at'   => null,
        ]);

        $webhook = Webhook::create([
            'company_id' => $company->id,
            'url'        => $pairing->webhook_url,
            'events'     => ['payment_request.paid'],
        ]);

        $pairing->forceFill([
            'status'       => EcommercePairing::STATUS_APPROVED,
            'api_token_id' => $token->id,
            'webhook_id'   => $webhook->id,
            'credentials'  => [
                'api_token'      => $raw,
                'webhook_secret' => $webhook->secret,
            ],
            'approved_by'  => $request->user()->id,
            'approved_at'  => now(),
        ])->save();

        AuditLog::create([
            'actor_user_id'  => $request->user()->id,
            'event'          => 'admin.company.ecommerce_pairing_approved',
            'auditable_type' => EcommercePairing::class,
            'auditable_id'   => $pairing->id,
            'context'        => [
                'company_id'     => $company->id,
                'account_number' => $pairing->account_number,
                'site_url'       => $pairing->site_url,
                'api_token_id'   => $token->id,
                'webhook_id'     => $webhook->id,
            ],
        ]);

        return back()->with('portal_success', 'Collegamento approvato per ' . $host . '. Il plugin riceverà token e webhook automaticamente alla prossima verifica (basta riaprire o salvare le impostazioni del plugin).');
    }

    /** POST /admin/companies/{company}/ecommerce/pairings/{pairing}/reject */
    public function rejectPairing(Request $request, Company $company, EcommercePairing $pairing): RedirectResponse
    {
        $this->authorizeBackoffice($request->user());
        abort_unless($pairing->company_id === $company->id, 404);

        if (! $pairing->isPending()) {
            return back()->with('portal_error', 'Questa richiesta di collegamento è già stata gestita.');
        }

        $pairing->forceFill(['status' => EcommercePairing::STATUS_REJECTED])->save();

        AuditLog::create([
            'actor_user_id'  => $request->user()->id,
            'event'          => 'admin.company.ecommerce_pairing_rejected',
            'auditable_type' => EcommercePairing::class,
            'auditable_id'   => $pairing->id,
            'context'        => [
                'company_id'     => $company->id,
                'account_number' => $pairing->account_number,
                'site_url'       => $pairing->site_url,
            ],
        ]);

        return back()->with('portal_success', 'Richiesta di collegamento rifiutata.');
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
