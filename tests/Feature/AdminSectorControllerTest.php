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

    // ───────────────────────── Gerarchia (sotto-settori) ─────────────────────────

    public function test_admin_can_create_subsector_with_parent(): void
    {
        $admin  = $this->makeAdmin();
        $parent = Sector::create(['name' => 'Commercio', 'is_active' => true]);

        $this->actingAs($admin)
            ->post(route('admin.sectors.store'), [
                'name'      => 'Abbigliamento',
                'parent_id' => $parent->id,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('sectors', ['name' => 'Abbigliamento', 'parent_id' => $parent->id]);
    }

    public function test_store_rejects_nonexistent_parent(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->post(route('admin.sectors.store'), ['name' => 'Orfano', 'parent_id' => 999999])
            ->assertSessionHasErrors('parent_id');
    }

    public function test_cannot_delete_sector_with_children(): void
    {
        $admin  = $this->makeAdmin();
        $parent = Sector::create(['name' => 'Padre', 'is_active' => true]);
        Sector::create(['name' => 'Figlio', 'is_active' => true, 'parent_id' => $parent->id]);

        $this->actingAs($admin)
            ->delete(route('admin.sectors.destroy', $parent))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseHas('sectors', ['id' => $parent->id]);
    }

    public function test_update_prevents_cycle(): void
    {
        $admin  = $this->makeAdmin();
        $parent = Sector::create(['name' => 'Radice', 'is_active' => true]);
        $child  = Sector::create(['name' => 'Ramo', 'is_active' => true, 'parent_id' => $parent->id]);

        // Tentativo: rendere il padre figlio del proprio figlio → ciclo
        $this->actingAs($admin)
            ->put(route('admin.sectors.update', $parent), [
                'name'      => 'Radice',
                'parent_id' => $child->id,
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertNull($parent->fresh()->parent_id);
    }

    public function test_selectable_options_only_includes_active_leaves(): void
    {
        $parent = Sector::create(['name' => 'Categoria', 'is_active' => true]);
        $leaf   = Sector::create(['name' => 'Foglia attiva', 'is_active' => true, 'parent_id' => $parent->id]);
        Sector::create(['name' => 'Foglia spenta', 'is_active' => false, 'parent_id' => $parent->id]);

        $names = collect(Sector::selectableOptions())->pluck('name');

        $this->assertTrue($names->contains('Foglia attiva'));
        $this->assertFalse($names->contains('Categoria'));      // ha figli → non selezionabile
        $this->assertFalse($names->contains('Foglia spenta'));  // inattiva

        $option = collect(Sector::selectableOptions())->firstWhere('name', 'Foglia attiva');
        $this->assertSame('Categoria › Foglia attiva', $option['label']);
    }
}
