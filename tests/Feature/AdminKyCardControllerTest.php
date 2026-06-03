<?php

namespace Tests\Feature;

use App\Models\KyCard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminKyCardControllerTest extends TestCase
{
    use RefreshDatabase;

    private function makeAdmin(): User
    {
        $user = User::create([
            'name'                => 'Admin KyCard',
            'email'               => 'admin-kycard-' . Str::random(6) . '@test.test',
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
        $this->get(route('admin.ky-cards.index'))
            ->assertRedirect(route('login'));
    }

    public function test_admin_can_view_ky_cards_index(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->get(route('admin.ky-cards.index'))
            ->assertOk();
    }

    public function test_admin_can_view_create_ky_card_form(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->get(route('admin.ky-cards.create'))
            ->assertOk();
    }

    public function test_admin_can_store_ky_card(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->post(route('admin.ky-cards.store'), [
                'name'          => 'KY Card 100',
                'price_eur'     => '85.00',
                'bonus_type'    => 'fixed',
                'ky_base_amount'=> 10000,
                'bonus_value'   => 0,
                'is_active'     => true,
                'sort_order'    => 1,
            ])
            ->assertRedirect(route('admin.ky-cards.index'));

        $this->assertDatabaseHas('ky_cards', ['name' => 'KY Card 100']);
    }

    public function test_admin_can_toggle_ky_card(): void
    {
        $admin = $this->makeAdmin();
        $card  = KyCard::create([
            'name'            => 'Toggle Card',
            'ky_base_amount'  => 5000,
            'price_eur_cents' => 4500,
            'bonus_type'      => 'fixed',
            'bonus_value'     => 0,
            'is_active'       => true,
        ]);

        $this->actingAs($admin)
            ->patch(route('admin.ky-cards.toggle', $card))
            ->assertRedirect();

        $this->assertFalse((bool) $card->fresh()->is_active);
    }

    public function test_admin_can_delete_ky_card(): void
    {
        $admin = $this->makeAdmin();
        $card  = KyCard::create([
            'name'            => 'Delete Card',
            'ky_base_amount'  => 2000,
            'price_eur_cents' => 1800,
            'bonus_type'      => 'fixed',
            'bonus_value'     => 0,
            'is_active'       => false,
        ]);

        $this->actingAs($admin)
            ->delete(route('admin.ky-cards.destroy', $card))
            ->assertRedirect(route('admin.ky-cards.index'));

        $this->assertDatabaseMissing('ky_cards', ['id' => $card->id]);
    }
}
