<?php

namespace Tests\Feature;

use App\Models\TransactionFee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminFeeControllerTest extends TestCase
{
    use RefreshDatabase;

    private function makeAdmin(): User
    {
        $user = User::create([
            'name'                => 'Admin',
            'email'               => 'admin-fee-' . Str::random(6) . '@test.test',
            'password'            => 'secret123',
            'account_holder_type' => 'private',
            'company_id'          => null,
            'is_active'           => true,
            'is_super_admin'      => true,
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();
        return $user;
    }

    public function test_admin_can_view_fees_index(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->get(route('admin.fees.index'))
            ->assertOk()
            ->assertSee('Commissioni', false);
    }

    public function test_admin_can_view_create_fee_form(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->get(route('admin.fees.create'))
            ->assertOk();
    }

    public function test_admin_can_store_a_fee(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->post(route('admin.fees.store'), [
                'operation_kind' => 'portal_payment',
                'fee_type'       => 'percentage',
                'fee_value'      => '1.5',
                'is_active'      => true,
            ])
            ->assertRedirect(route('admin.fees.index'));

        $this->assertDatabaseHas('transaction_fees', [
            'operation_kind' => 'portal_payment',
            'fee_type'       => 'percentage',
        ]);
    }

    public function test_admin_can_update_a_fee(): void
    {
        $admin = $this->makeAdmin();
        $fee   = TransactionFee::create([
            'operation_kind' => 'portal_qr_payment',
            'fee_type'       => 'fixed',
            'fee_value'      => '100',
            'is_active'      => true,
        ]);

        $this->actingAs($admin)
            ->put(route('admin.fees.update', $fee), [
                'operation_kind' => 'portal_qr_payment',
                'fee_type'       => 'percentage',
                'fee_value'      => '2.0',
                'is_active'      => true,
            ])
            ->assertRedirect(route('admin.fees.index'));

        $this->assertSame('percentage', $fee->fresh()->fee_type);
    }

    public function test_admin_can_toggle_a_fee(): void
    {
        $admin = $this->makeAdmin();
        $fee   = TransactionFee::create([
            'operation_kind' => 'nfc',
            'fee_type'       => 'fixed',
            'fee_value'      => '50',
            'is_active'      => true,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.fees.toggle', $fee))
            ->assertRedirect();

        $this->assertFalse((bool) $fee->fresh()->is_active);
    }

    public function test_admin_can_delete_a_fee(): void
    {
        $admin = $this->makeAdmin();
        $fee   = TransactionFee::create([
            'operation_kind' => 'api_payment',
            'fee_type'       => 'fixed',
            'fee_value'      => '30',
            'is_active'      => false,
        ]);

        $this->actingAs($admin)
            ->delete(route('admin.fees.destroy', $fee))
            ->assertRedirect(route('admin.fees.index'));

        $this->assertDatabaseMissing('transaction_fees', ['id' => $fee->id]);
    }

    public function test_index_requires_authentication(): void
    {
        $this->get(route('admin.fees.index'))
            ->assertRedirect(route('login'));
    }
}
