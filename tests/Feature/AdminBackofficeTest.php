<?php

namespace Tests\Feature;

use App\Models\Transfer;
use App\Models\User;
use App\Models\Account;
use App\Models\SystemSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminBackofficeTest extends TestCase
{
    use RefreshDatabase;

    public function test_superadmin_can_open_separated_backoffice_pages(): void
    {
        $this->seed();
        $admin = User::where('email', 'superadmin@kmoney.test')->firstOrFail();
        $privateUser = User::where('email', 'maria.ferri@kmoney.test')->firstOrFail();

        $this->actingAs($admin)->get('/admin/users')
            ->assertOk()
            ->assertSee('Elenco utenti', false);

        $this->actingAs($admin)->get('/admin/users/' . $privateUser->id)
            ->assertOk()
            ->assertSee("Tutti i movimenti dell'utente", false)
            ->assertSee('Maria Ferri', false);

        $this->actingAs($admin)->get('/admin/roles')
            ->assertOk()
            ->assertSee('Ruoli e permessi', false);

        $this->actingAs($admin)->get('/admin/accounts')
            ->assertOk()
            ->assertSee('Conti e sottoconti', false);

        $this->actingAs($admin)->get('/admin/transfers')
            ->assertOk()
            ->assertSee('Movimenti e correzioni', false);
    }

    public function test_regular_portal_user_cannot_open_backoffice_page(): void
    {
        $this->seed();
        $user = User::where('email', 'operatore-panificio-canale@kmoney.test')->firstOrFail();

        $this->actingAs($user)->get('/admin/users')->assertForbidden();
    }

    public function test_superadmin_can_filter_users_by_role(): void
    {
        $this->seed();
        $admin = User::where('email', 'superadmin@kmoney.test')->firstOrFail();
        $viewer = User::where('email', 'viewer-panificio@kmoney.test')->firstOrFail();
        $manager = User::where('email', 'operatore-panificio-canale@kmoney.test')->firstOrFail();
        $viewerRoleId = $viewer->roles()->value('roles.id');

        $response = $this->actingAs($admin)->get('/admin/users?role_id=' . $viewerRoleId);

        $response
            ->assertOk()
            ->assertSee('Panificio Viewer', false)
            ->assertDontSee('Panificio Canale Operator', false);

        $this->assertNotSame($viewer->id, $manager->id);
    }

    public function test_superadmin_can_filter_users_by_status(): void
    {
        $this->seed();
        $admin = User::where('email', 'superadmin@kmoney.test')->firstOrFail();
        $viewer = User::where('email', 'viewer-panificio@kmoney.test')->firstOrFail();
        $manager = User::where('email', 'operatore-panificio-canale@kmoney.test')->firstOrFail();
        $viewer->forceFill(['is_active' => false])->save();

        $response = $this->actingAs($admin)->get('/admin/users?status=inactive');

        $response
            ->assertOk()
            ->assertSee('Panificio Viewer', false)
            ->assertDontSee('Panificio Canale Operator', false);

        $this->assertTrue($manager->fresh()->is_active);
    }

    public function test_superadmin_can_filter_users_by_account_holder_type(): void
    {
        $this->seed();
        $admin = User::where('email', 'superadmin@kmoney.test')->firstOrFail();

        $response = $this->actingAs($admin)->get('/admin/users?account_holder_type=private');

        $response
            ->assertOk()
            ->assertSee('Maria Ferri', false)
            ->assertDontSee('Panificio Canale Operator', false);
    }

    public function test_holder_type_filter_uses_linked_accounts_when_user_type_is_stale(): void
    {
        $this->seed();
        $admin = User::where('email', 'superadmin@kmoney.test')->firstOrFail();
        $privateUser = User::where('email', 'maria.ferri@kmoney.test')->firstOrFail();
        $privateUser->forceFill(['account_holder_type' => 'company'])->save();

        $response = $this->actingAs($admin)->get('/admin/users?account_holder_type=private');

        $response
            ->assertOk()
            ->assertSee('Maria Ferri', false)
            ->assertDontSee('Panificio Canale Operator', false);
    }

    public function test_users_directory_shows_reset_filters_action(): void
    {
        $this->seed();
        $admin = User::where('email', 'superadmin@kmoney.test')->firstOrFail();

        $this->actingAs($admin)
            ->get('/admin/users?status=active&account_holder_type=company')
            ->assertOk()
            ->assertSee('Reset filtri', false);
    }

    public function test_users_directory_shows_optimized_filters(): void
    {
        $this->seed();
        $admin = User::where('email', 'superadmin@kmoney.test')->firstOrFail();

        $this->actingAs($admin)->get('/admin/users')
            ->assertOk()
            ->assertSee('Aziende', false)
            ->assertSee('Privati', false)
            ->assertSee('Reset filtri', false);
    }

    public function test_superadmin_can_set_private_account_max_balance_from_account_page(): void
    {
        $this->seed();
        $admin = User::where('email', 'superadmin@kmoney.test')->firstOrFail();
        $privateUser = User::where('email', 'maria.ferri@kmoney.test')->firstOrFail();
        $account = Account::query()
            ->where('owner_user_id', $privateUser->id)
            ->whereNull('parent_account_id')
            ->firstOrFail();

        $this->actingAs($admin)
            ->post('/admin/accounts/' . $account->id, [
                'status' => $account->status,
                'max_balance' => 12345,
                'spending_limit' => $account->spending_limit,
                'daily_outgoing_limit' => $account->daily_outgoing_limit,
                'allow_negative_balance' => $account->allow_negative_balance ? '1' : '0',
            ])
            ->assertRedirect();

        // L'input del form è in KY: 12345 KY → 1234500 centesimi
        $this->assertSame(1234500, $account->fresh()->max_balance);

        $this->actingAs($privateUser)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('12.345,00', false);
    }

    public function test_superadmin_can_set_private_account_max_balance_from_user_page(): void
    {
        $this->seed();
        $admin = User::where('email', 'superadmin@kmoney.test')->firstOrFail();
        $privateUser = User::where('email', 'maria.ferri@kmoney.test')->firstOrFail();
        $account = Account::query()
            ->where('owner_user_id', $privateUser->id)
            ->whereNull('parent_account_id')
            ->firstOrFail();

        $this->actingAs($admin)
            ->get('/admin/users/' . $privateUser->id . '#user-update')
            ->assertOk()
            ->assertSee('Saldo massimo (KY)', false);

        $this->actingAs($admin)
            ->post('/admin/users/' . $privateUser->id, [
                'name' => $privateUser->name,
                'email' => $privateUser->email,
                'account_holder_type' => $privateUser->account_holder_type,
                'company_id' => $privateUser->company_id,
                'managed_account_id' => $privateUser->managed_account_id,
                'phone' => $privateUser->phone,
                'role_label' => $privateUser->role,
                'is_active' => $privateUser->is_active ? '1' : '0',
                'circuit_capacity_limit' => $privateUser->circuit_capacity_limit,
                'negative_balance_limit' => $privateUser->negative_balance_limit,
                'daily_transaction_limit' => $privateUser->daily_transaction_limit,
                'monthly_transaction_limit' => $privateUser->monthly_transaction_limit,
                'per_movement_limit' => $privateUser->per_movement_limit,
                'primary_account_max_balance' => 22222,
                'roles' => $privateUser->roles()->pluck('roles.id')->all(),
            ])
            ->assertRedirect();

        // L'input del form è in KY: 22222 KY → 2222200 centesimi
        $this->assertSame(2222200, $account->fresh()->max_balance);
    }

    public function test_updating_default_limits_does_not_change_existing_users_effective_limits(): void
    {
        $this->seed();
        $admin = User::where('email', 'superadmin@kmoney.test')->firstOrFail();
        $privateUser = User::where('email', 'maria.ferri@kmoney.test')->firstOrFail();

        $this->assertTrue($privateUser->transfer_limits_use_defaults);
        $oldLimits = $privateUser->effectiveTransferLimits();

        $this->actingAs($admin)
            ->post('/admin/limits', [
                'default_circuit_capacity_limit' => 50000,
                'default_negative_balance_limit' => 10000,
                'default_daily_transaction_limit' => 7000,
                'default_monthly_transaction_limit' => 30000,
                'default_per_movement_limit' => 4000,
            ])
            ->assertRedirect();

        $privateUser->refresh();

        $this->assertFalse($privateUser->transfer_limits_use_defaults);
        $this->assertSame($oldLimits, $privateUser->effectiveTransferLimits());
        // L'input del form è in KY: 10000 KY → 1000000 centesimi
        $this->assertSame(1000000, SystemSetting::userLimitDefaults()->default_negative_balance_limit);
    }

    public function test_superadmin_can_refund_transfer_within_refund_window(): void
    {
        $this->seed();
        $admin = User::where('email', 'superadmin@kmoney.test')->firstOrFail();
        $transfer = Transfer::query()->where('kind', 'trade_payment')->latest('id')->firstOrFail();

        $response = $this->actingAs($admin)->post('/admin/transfers/' . $transfer->id . '/refund', [
            'reason' => 'Errore operatore',
        ]);

        $response->assertRedirect();

        $refund = Transfer::query()->where('reversed_transfer_id', $transfer->id)->first();

        $this->assertNotNull($refund);
        $this->assertSame('admin_refund', $refund->kind);
        $this->assertSame('refund', $refund->admin_action);
        $this->assertSame($transfer->to_account_id, $refund->from_account_id);
        $this->assertSame($transfer->from_account_id, $refund->to_account_id);
    }

    public function test_superadmin_cannot_refund_transfer_outside_refund_window(): void
    {
        $this->seed();
        $admin = User::where('email', 'superadmin@kmoney.test')->firstOrFail();
        $transfer = Transfer::query()->where('kind', 'trade_payment')->latest('id')->firstOrFail();
        $transfer->forceFill(['booked_at' => now()->subDays(31)])->save();

        $this->actingAs($admin)
            ->post('/admin/transfers/' . $transfer->id . '/refund', ['reason' => 'Fuori finestra'])
            ->assertStatus(422);
    }
}
