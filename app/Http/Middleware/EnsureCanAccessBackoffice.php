<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Consente l'accesso solo agli utenti con accesso al backoffice (admin / permesso backoffice.access).
 * Chiude il buco di autorizzazione sui controller admin le cui rotte stavano nel gruppo portale
 * senza guardia di ruolo (cashback, settori, menu-visibility, card NFC).
 *
 * Operatori "ristretti" (2026-08-12, richiesta di Laura): un utente con backoffice.access ma
 * SENZA il permesso backoffice.full (es. ruolo "Gestore Aziende e Prodotti") vede il suo accesso
 * limitato alle sole rotte elencate in ALLOWED_ROUTES_BY_PERMISSION, in base ai permessi che
 * possiede davvero (companies.*, listings.*). Qualsiasi altra rotta admin (utenti, ruoli, conti,
 * movimenti, MLM, audit, impostazioni...) resta bloccata (403) anche conoscendo l'URL diretto —
 * di default TUTTO e' negato, si abilita solo cio' che e' esplicitamente elencato qui sotto.
 * Super admin e chi ha 'backoffice.full' (es. Backoffice Operator) non sono soggetti a questo filtro.
 */
class EnsureCanAccessBackoffice
{
    /**
     * Nome rotta => permesso che la sblocca, per gli operatori ristretti (senza backoffice.full).
     * Aggiungere qui solo le rotte che devono restare raggiungibili da un ruolo con permessi
     * granulari (companies.x, listings.x) — qualunque rotta NON elencata resta bloccata.
     */
    private const ALLOWED_ROUTES_BY_PERMISSION = [
        'companies.read' => [
            'admin.companies.index',
            'admin.companies.show',
            'admin.kyc.index',
            'admin.kyc.show',
        ],
        'companies.manage' => [
            'admin.companies.address',
            'admin.companies.suspend',
            'admin.companies.unsuspend',
            'admin.companies.activate',
            'admin.companies.deactivate',
            'admin.companies.bulk',
            'admin.companies.broker',
            'admin.companies.plan',
            'admin.companies.ky-percentage',
            'admin.companies.ecommerce.token',
            'admin.companies.ecommerce.token.revoke',
            'admin.companies.ecommerce.webhook',
            'admin.companies.ecommerce.webhook.toggle',
            'admin.companies.ecommerce.webhook.delete',
            'admin.companies.ecommerce.pairing.approve',
            'admin.companies.ecommerce.pairing.reject',
            'admin.companies.payment-gateways.update',
            'admin.companies.payment-gateways.toggle',
            'admin.companies.payment-gateways.destroy',
            'admin.kyc.approve',
            'admin.kyc.reject',
            'admin.kyc.request-docs',
        ],
        'listings.read' => [
            'admin.listings.index',
            'admin.listing-categories.index',
        ],
        'listings.manage' => [
            'admin.listings.create',
            'admin.listings.store',
            'admin.listings.status',
            'admin.listing-categories.store',
            'admin.listing-categories.update',
            'admin.listing-categories.toggle',
            'admin.listing-categories.destroy',
        ],
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless($user?->canAccessBackoffice(), 403);

        if (! $this->hasFullBackofficeAccess($user)) {
            abort_unless($this->routeAllowedForRestrictedUser($user, $request->route()?->getName()), 403);
        }

        return $next($request);
    }

    private function hasFullBackofficeAccess(User $user): bool
    {
        return $user->is_super_admin || $user->hasPermission('backoffice.full');
    }

    private function routeAllowedForRestrictedUser(User $user, ?string $routeName): bool
    {
        if ($routeName === null) {
            return false;
        }

        foreach (self::ALLOWED_ROUTES_BY_PERMISSION as $permission => $routeNames) {
            if (in_array($routeName, $routeNames, true) && $user->hasPermission($permission)) {
                return true;
            }
        }

        return false;
    }
}
