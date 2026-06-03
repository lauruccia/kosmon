<?php

namespace Tests\Feature;

use App\Models\Sector;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminSectorControllerTest extends TestCase
{
    use RefreshDatabase;

    private function makeAdmin(): User
    {
        $user = User::create([
            'name'                => 'Admin',
            'email'               => 'admin-' . Str::random(6) . '@test.test',
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
        $this->get(route('admin.sectors.index'))
            ->assertRedirect(route('login'));
    }

    public function test_admin_can_view_sectors_index(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->get(route('admin.sectors.index'))
            ->assertOk()
            ->assertSee('Settori', false);
    }

    public function test_admin_can_create_sector(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->post(route('admin.sectors.store'), [
                'name'       => 'Nuova Categoria Test',
                'sort_order' => 10,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('sectors', ['name' => 'Nuova Categoria Test']);
    }

    public function test_store_validates_name_is_required(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->post(route('admin.sectors.store'), ['name' => ''])
            ->assertSessionHasErrors('name');
    }

    public function test_store_rejects_duplicate_name(): void
    {
        $admin = $this->makeAdmin();
        Sector::create(['name' => 'Esistente', 'is_active' => true]);

        $this->actingAs($admin)
            ->post(route('admin.sectors.store'), ['name' => 'Esistente'])
            ->assertSessionHasErrors('name');
    }

    public function test_admin_can_update_sector(): void
    {
        $admin  = $this->makeAdmin();
        $sector = Sector::create(['name' => 'Vecchio Nome', 'is_active' => true]);

        $this->actingAs($admin)
            ->put(route('admin.sectors.update', $sector), [
                'name'      => 'Nuovo Nome',
                'is_active' => true,
            ])
            ->assertRedirect();

        $this->assertSame('Nuovo Nome', $sector->fresh()->name);
    }

    public function test_admin_can_toggle_sector(): void
    {
        $admin  = $this->makeAdmin();
        $sector = Sector::create(['name' => 'Toggle Test', 'is_active' => true]);

        $this->actingAs($admin)
            ->patch(route('admin.sectors.toggle', $sector))
            ->assertRedirect();

        $this->assertFalse((bool) $sector->fresh()->is_active);
    }

    public function test_admin_can_delete_sector(): void
    {
        $admin  = $this->makeAdmin();
        $sector = Sector::create(['name' => 'Da Eliminare', 'is_active' => true]);

        $this->actingAs($admin)
            ->delete(route('admin.sectors.destroy', $sector))
            ->assertRedirect();

        $this->assertDatabaseMissing('sectors', ['id' => $sector->id]);
    }
}
