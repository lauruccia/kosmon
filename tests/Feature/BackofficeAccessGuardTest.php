<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Copre la chiusura del buco di autorizzazione sui controller admin che stavano
 * nel gruppo portale senza guardia di backoffice (cashback, settori, card NFC,
 * visibilita' menu). Un utente onboardato e con contratto firmato ma SENZA
 * accesso al backoffice deve ricevere 403, sia in lettura (GET) sia in scrittura.
 */
class BackofficeAccessGuardTest extends TestCase
{
    use RefreshDatabase;

    /** Utente normale: supera auth/verified/onboarding/contract ma NON e' backoffice. */
    private function makeRegularUser(): User
    {
        $slug = 'reg-' . Str::random(5);

        $company = Company::create([
            'name'          => 'Reg Co ' . Str::random(4),
            'slug'          => $slug,
            'email'         => $slug . '@test.test',
            'status'        => 'active',
            'kyc_status'    => 'approved',
            'currency_code' => 'KY',
            'sector'        => 'informatica',
            'description'   => 'Test',
        ]);

        $user = User::create([
            'company_id'          => $company->id,
            'account_holder_type' => 'company',
            'name'                => 'Reg User',
            'email'               => 'reg-' . Str::random(6) . '@test.test',
            'password'            => 'secret123',
            'role'                => 'company-manager',
            'is_active'           => true,
            'is_super_admin'      => false,
        ]);
        $user->forceFill([
            'email_verified_at'  => now(),
            'contract_signed_at' => now(),
        ])->save();

        return $user;
    }

    private function makeAdmin(): User
    {
        $user = User::create([
            'name'                => 'Backoffice Admin',
            'email'               => 'bo-admin-' . Str::random(6) . '@test.test',
            'password'            => 'secret123',
            'account_holder_type' => 'private',
            'company_id'          => null,
            'is_active'           => true,
            'is_super_admin'      => true,
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();

        return $user;
    }

    /**
     * @return array<int, array{0:string,1:string}>  [metodo HTTP, nome rotta]
     */
    public static function guardedRoutes(): array
    {
        return [
            'cashback index'        => ['get',  'admin.cashback.index'],
            'cashback store'        => ['post', 'admin.cashback.store'],
            'settori index'         => ['get',  'admin.sectors.index'],
            'settori store'         => ['post', 'admin.sectors.store'],
            'nfc index'             => ['get',  'admin.nfc-cards.index'],
            'nfc store'             => ['post', 'admin.nfc-cards.store'],
            'menu-visibility index' => ['get',  'admin.menu-visibility.index'],
            'menu-visibility store' => ['post', 'admin.menu-visibility.store'],
            'fees index'            => ['get',  'admin.fees.index'],
            'fees store'            => ['post', 'admin.fees.store'],
            'broadcast index'       => ['get',  'admin.broadcast.index'],
            'broadcast send'        => ['post', 'admin.broadcast.send'],
            'ky-cards index'        => ['get',  'admin.ky-cards.index'],
            'ky-cards store'        => ['post', 'admin.ky-cards.store'],
            'companies index'       => ['get',  'admin.companies.index'],
            'users index'           => ['get',  'admin.users.index'],
            'kyc index'             => ['get',  'admin.kyc.index'],
        ];
    }

    #[DataProvider('guardedRoutes')]
    public function test_non_backoffice_user_is_forbidden(string $method, string $routeName): void
    {
        $user = $this->makeRegularUser();

        $this->actingAs($user)
            ->{$method}(route($routeName))
            ->assertForbidden();
    }

    public function test_admin_passes_the_backoffice_guard(): void
    {
        $admin = $this->makeAdmin();

        // L'admin supera la guardia: la index risponde 200 (non 403).
        $this->actingAs($admin)->get(route('admin.cashback.index'))->assertOk();
        $this->actingAs($admin)->get(route('admin.sectors.index'))->assertOk();
        $this->actingAs($admin)->get(route('admin.nfc-cards.index'))->assertOk();
        $this->actingAs($admin)->get(route('admin.menu-visibility.index'))->assertOk();
    }

    // ── Le regole delle FormRequest restano attive per chi e' autorizzato ──────

    public function test_sector_store_validation_fires_for_admin(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->post(route('admin.sectors.store'), [])
            ->assertSessionHasErrors('name');
    }

    public function test_nfc_store_validation_fires_for_admin(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->post(route('admin.nfc-cards.store'), [])
            ->assertSessionHasErrors('company_id');
    }

    public function test_menu_visibility_store_validation_fires_for_admin(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->post(route('admin.menu-visibility.store'), [])
            ->assertSessionHasErrors(['menu_item_key', 'scope_type', 'visible']);
    }

    /** Rotta parametrizzata ad alto impatto: sospensione azienda (era scoperta). */
    public function test_non_backoffice_user_cannot_suspend_company(): void
    {
        $user = $this->makeRegularUser();

        $slug = 'target-' . Str::random(5);
        $company = Company::create([
            'name'          => 'Target Co ' . Str::random(4),
            'slug'          => $slug,
            'email'         => $slug . '@test.test',
            'status'        => 'active',
            'kyc_status'    => 'approved',
            'currency_code' => 'KY',
            'sector'        => 'informatica',
            'description'   => 'Test',
        ]);

        $this->actingAs($user)
            ->post(route('admin.companies.suspend', $company))
            ->assertForbidden();

        $this->assertNull($company->fresh()->suspended_at);
    }
}
