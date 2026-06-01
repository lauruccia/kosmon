<?php

namespace App\Services;

use App\Models\MenuVisibility;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Risolve la visibilità di ogni voce del menu per un utente.
 *
 * Priorità (dal più specifico al più generale):
 *   1. Regola per utente specifico   (scope_type = 'user')
 *   2. Regola per azienda specifica  (scope_type = 'company')
 *   3. Regola per tipo account       (scope_type = 'account_type', account_type = 'private'|'company')
 *   4. Regola globale                (scope_type = 'global')
 *   5. Nessuna regola → visibile di default
 */
class MenuVisibilityService
{
    /** @var array<string, bool>|null Cache già calcolata per la richiesta corrente */
    private ?array $resolved = null;

    /** @var User|null L'utente per cui abbiamo calcolato la cache */
    private ?int $cachedUserId = null;

    // -----------------------------------------------------------------------

    /**
     * Restituisce true se la voce di menu $key è visibile per $user.
     */
    public function isVisible(string $key, ?User $user): bool
    {
        if ($user === null) {
            return true;
        }

        if ($this->cachedUserId !== $user->id) {
            $this->resolved    = null;
            $this->cachedUserId = $user->id;
        }

        if ($this->resolved === null) {
            $this->resolved = $this->resolveForUser($user);
        }

        return $this->resolved[$key] ?? true;
    }

    /**
     * Restituisce la mappa completa key → bool per un utente.
     */
    public function resolveForUser(User $user): array
    {
        $allKeys = array_keys(MenuVisibility::menuItems());

        // Determina il tipo di account dell'utente
        $account     = $user->managedAccount ?? $user->accounts()->first();
        $accountType = ($account?->owner_type === 'private') ? 'private' : 'company';
        $companyId   = $user->company_id;
        $userId      = $user->id;

        // Carica tutte le regole rilevanti in una sola query
        $rules = MenuVisibility::query()
            ->where(function ($q) use ($userId, $companyId, $accountType) {
                $q->where('scope_type', MenuVisibility::SCOPE_GLOBAL)
                  ->orWhere(function ($q2) use ($accountType) {
                      $q2->where('scope_type', MenuVisibility::SCOPE_ACCOUNT_TYPE)
                         ->where('account_type', $accountType);
                  })
                  ->orWhere(function ($q2) use ($companyId) {
                      if ($companyId) {
                          $q2->where('scope_type', MenuVisibility::SCOPE_COMPANY)
                             ->where('scope_id', $companyId);
                      } else {
                          $q2->whereRaw('1=0');
                      }
                  })
                  ->orWhere(function ($q2) use ($userId) {
                      $q2->where('scope_type', MenuVisibility::SCOPE_USER)
                         ->where('scope_id', $userId);
                  });
            })
            ->get()
            ->groupBy('menu_item_key');

        // Risolve chiave per chiave
        $result = [];
        foreach ($allKeys as $key) {
            $result[$key] = $this->resolveKey($key, $rules->get($key, collect()));
        }

        return $result;
    }

    // -----------------------------------------------------------------------

    private function resolveKey(string $key, Collection $rules): bool
    {
        // Ordine di priorità: user > company > account_type > global
        $priority = [
            MenuVisibility::SCOPE_USER         => 1,
            MenuVisibility::SCOPE_COMPANY       => 2,
            MenuVisibility::SCOPE_ACCOUNT_TYPE  => 3,
            MenuVisibility::SCOPE_GLOBAL        => 4,
        ];

        $best = null;
        foreach ($rules as $rule) {
            $p = $priority[$rule->scope_type] ?? 99;
            if ($best === null || $p < ($priority[$best->scope_type] ?? 99)) {
                $best = $rule;
            }
        }

        return $best ? $best->visible : true;
    }

    // -----------------------------------------------------------------------
    // Helpers statici per l'interfaccia admin
    // -----------------------------------------------------------------------

    /**
     * Upsert di una regola. Usa null per $scopeId / $accountType se non applicabili.
     */
    public static function setRule(
        string  $key,
        string  $scopeType,
        bool    $visible,
        ?int    $scopeId    = null,
        ?string $accountType = null
    ): void {
        MenuVisibility::updateOrCreate(
            [
                'menu_item_key' => $key,
                'scope_type'    => $scopeType,
                'account_type'  => $accountType,
                'scope_id'      => $scopeId,
            ],
            ['visible' => $visible]
        );
    }

    /**
     * Rimuove una regola (torna al default o alla regola di livello inferiore).
     */
    public static function deleteRule(
        string  $key,
        string  $scopeType,
        ?int    $scopeId    = null,
        ?string $accountType = null
    ): void {
        MenuVisibility::where([
            'menu_item_key' => $key,
            'scope_type'    => $scopeType,
            'account_type'  => $accountType,
            'scope_id'      => $scopeId,
        ])->delete();
    }
}
