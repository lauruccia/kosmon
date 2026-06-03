<?php

namespace Tests\Feature;

use App\Models\CashbackRule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CashbackRuleControllerTest extends TestCase
{
    use RefreshDatabase;

    private function makeAdmin(): User
    {
        $user = User::create([
            'name'                => 'Admin Cashback',
            'email'               => 'admin-cb-' . Str::random(6) . '@test.test',
            'password'            => 'secret123',
            'account_holder_type' => 'private',
            'company_id'          => null,
            'is_active'           => true,
            'is_super_admin'      => true,
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();
        return $user;
    }

    private function rulePayload(array $overrides = []): array
    {
        return array_merge([
            'name'              => 'Cashback Test',
            'min_amount'        => 1000,
            'percentage'        => '5.0',
            'applicable_kinds'  => ['trade_payment'],
            'is_active'         => true,
            'target_type'       => 'all',
        ], $overrides);
    }

    public function test_admin_can_view_cashback_index(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->get(route('admin.cashback.index'))
            ->assertOk()
            ->assertSee('Cashback', false);
    }

    public function test_admin_can_view_cashback_create_form(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->get(route('admin.cashback.create'))
            ->assertOk();
    }

    public function test_admin_can_create_cashback_rule(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->post(route('admin.cashback.store'), $this->rulePayload())
            ->assertRedirect();

        $this->assertDatabaseHas('cashback_rules', ['name' => 'Cashback Test']);
    }

    public function test_store_validates_required_fields(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->post(route('admin.cashback.store'), [])
            ->assertSessionHasErrors(['name', 'percentage', 'target_type']);
    }

    public function test_admin_can_view_edit_form(): void
    {
        $admin = $this->makeAdmin();
        $rule  = CashbackRule::create(array_merge($this->rulePayload(), [
            'applicable_kinds' => json_encode(['trade_payment']),
            'created_by'       => $admin->id,
        ]));

        $this->actingAs($admin)
            ->get(route('admin.cashback.edit', $rule))
            ->assertOk();
    }

    public function test_admin_can_toggle_cashback_rule(): void
    {
        $admin = $this->makeAdmin();
        $rule  = CashbackRule::create(array_merge($this->rulePayload(), [
            'applicable_kinds' => json_encode(['trade_payment']),
            'is_active'        => true,
            'created_by'       => $admin->id,
        ]));

        $this->actingAs($admin)
            ->post(route('admin.cashback.toggle', $rule))
            ->assertRedirect();

        $this->assertFalse((bool) $rule->fresh()->is_active);
    }

    public function test_admin_can_delete_cashback_rule(): void
    {
        $admin = $this->makeAdmin();
        $rule  = CashbackRule::create(array_merge($this->rulePayload(), [
            'applicable_kinds' => json_encode(['trade_payment']),
            'created_by'       => $admin->id,
        ]));

        $this->actingAs($admin)
            ->delete(route('admin.cashback.destroy', $rule))
            ->assertRedirect();

        $this->assertDatabaseMissing('cashback_rules', ['id' => $rule->id]);
    }

    public function test_index_requires_authentication(): void
    {
        $this->get(route('admin.cashback.index'))
            ->assertRedirect(route('login'));
    }
}
