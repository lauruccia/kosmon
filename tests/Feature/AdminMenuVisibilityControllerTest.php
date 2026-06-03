<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminMenuVisibilityControllerTest extends TestCase
{
    use RefreshDatabase;

    private function makeAdmin(): User
    {
        $user = User::create([
            'name'                => 'Admin MV',
            'email'               => 'admin-mv-' . Str::random(6) . '@test.test',
            'password'            => 'secret123',
            'account_holder_type' => 'private',
            'company_id'          => null,
            'is_active'           => true,
            'is_super_admin'      => true,
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();
        return $user;
    }

    public function test_index_requires_authentication(): void
    {
        $this->get(route('admin.menu-visibility.index'))
            ->assertRedirect(route('login'));
    }

    public function test_admin_can_view_menu_visibility_index(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->get(route('admin.menu-visibility.index'))
            ->assertOk()
            ->assertSee('Menu', false);
    }

    public function test_admin_can_set_global_menu_rule(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->post(route('admin.menu-visibility.store'), [
                'menu_item_key' => 'shop',
                'scope_type'    => 'global',
                'visible'       => '0',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('menu_visibilities', [
            'menu_item_key' => 'shop',
            'scope_type'    => 'global',
            'visible'       => false,
        ]);
    }

    public function test_admin_can_set_account_type_menu_rule(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->post(route('admin.menu-visibility.store'), [
                'menu_item_key' => 'announcements',
                'scope_type'    => 'account_type',
                'account_type'  => 'private',
                'visible'       => '1',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('menu_visibilities', [
            'menu_item_key' => 'announcements',
            'scope_type'    => 'account_type',
            'account_type'  => 'private',
            'visible'       => true,
        ]);
    }

    public function test_store_validates_required_fields(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->post(route('admin.menu-visibility.store'), [])
            ->assertSessionHasErrors(['menu_item_key', 'scope_type', 'visible']);
    }

    public function test_admin_can_destroy_menu_rule(): void
    {
        $admin = $this->makeAdmin();

        // Prima crea una regola
        $this->actingAs($admin)->post(route('admin.menu-visibility.store'), [
            'menu_item_key' => 'shop',
            'scope_type'    => 'global',
            'visible'       => '0',
        ]);

        // Poi la elimina
        $this->actingAs($admin)
            ->delete(route('admin.menu-visibility.destroy'), [
                'menu_item_key' => 'shop',
                'scope_type'    => 'global',
            ])
            ->assertRedirect();
    }
}
