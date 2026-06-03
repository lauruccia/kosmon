<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\BalanceAlert;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class BalanceAlertControllerTest extends TestCase
{
    use RefreshDatabase;

    // ── helpers ──────────────────────────────────────────────────────────────

    private function makeCompanyUser(): array
    {
        $company = Company::create([
            'name'          => 'Alert Co ' . Str::random(4),
            'slug'          => 'alert-co-' . Str::random(4),
            'email'         => 'alert@test.test',
            'status'        => 'active',
            'kyc_status'    => 'approved',
            'currency_code' => 'KY',
            'sector'        => 'informatica',
            'description'   => 'Test',
        ]);

        $user = User::create([
            'company_id'          => $company->id,
            'account_holder_type' => 'company',
            'name'                => 'Alert User',
            'email'               => 'alertuser-' . Str::random(6) . '@test.test',
            'password'            => 'secret123',
            'role'                => 'company-manager',
            'is_active'           => true,
            'is_super_admin'      => false,
        ]);
        $user->forceFill([
            'email_verified_at'  => now(),
            'contract_signed_at' => now(),
        ])->save();

        $account = Account::create([
            'company_id'         => $company->id,
            'owner_user_id'      => $user->id,
            'owner_type'         => 'company',
            'type'               => 'primary',
            'account_name'       => 'Conto Alert',
            'currency_code'      => 'KY',
            'status'             => 'active',
            'available_balance'  => 0,
        ]);

        return [$user, $account];
    }

    // ── index ─────────────────────────────────────────────────────────────────

    public function test_index_requires_authentication(): void
    {
        $this->get(route('portal.balance-alerts.index'))
            ->assertRedirect(route('login'));
    }

    public function test_user_can_view_balance_alerts_page(): void
    {
        [$user] = $this->makeCompanyUser();

        $this->actingAs($user)
            ->get(route('portal.balance-alerts.index'))
            ->assertOk()
            ->assertSee('Avvisi saldo');
    }

    // ── store ─────────────────────────────────────────────────────────────────

    public function test_user_can_create_balance_alert(): void
    {
        [$user, $account] = $this->makeCompanyUser();

        $this->actingAs($user)
            ->post(route('portal.balance-alerts.store'), [
                'threshold_ky'   => '100.00',
                'notify_email'   => true,
                'notify_inapp'   => true,
                'cooldown_hours' => 24,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('balance_alerts', [
            'account_id'       => $account->id,
            'threshold_amount' => 10000,
        ]);
    }

    public function test_store_validates_threshold_is_required(): void
    {
        [$user] = $this->makeCompanyUser();

        $this->actingAs($user)
            ->post(route('portal.balance-alerts.store'), ['threshold_ky' => ''])
            ->assertSessionHasErrors('threshold_ky');
    }

    public function test_store_rejects_more_than_five_alerts(): void
    {
        [$user, $account] = $this->makeCompanyUser();

        for ($i = 0; $i < 5; $i++) {
            BalanceAlert::create([
                'account_id'       => $account->id,
                'threshold_amount' => ($i + 1) * 1000,
                'notify_email'     => true,
                'notify_inapp'     => true,
                'cooldown_hours'   => 24,
            ]);
        }

        $this->actingAs($user)
            ->post(route('portal.balance-alerts.store'), ['threshold_ky' => '50'])
            ->assertSessionHasErrors('threshold_ky');
    }

    // ── toggle ────────────────────────────────────────────────────────────────

    public function test_user_can_toggle_balance_alert(): void
    {
        [$user, $account] = $this->makeCompanyUser();

        $alert = BalanceAlert::create([
            'account_id'       => $account->id,
            'threshold_amount' => 5000,
            'notify_email'     => true,
            'notify_inapp'     => true,
            'cooldown_hours'   => 24,
            'is_active'        => true,
        ]);

        $this->actingAs($user)
            ->patch(route('portal.balance-alerts.toggle', $alert))
            ->assertRedirect();

        $this->assertFalse($alert->fresh()->is_active);
    }

    public function test_user_cannot_toggle_another_users_alert(): void
    {
        [$user] = $this->makeCompanyUser();
        [$other, $otherAccount] = $this->makeCompanyUser();

        $alert = BalanceAlert::create([
            'account_id'       => $otherAccount->id,
            'threshold_amount' => 5000,
            'notify_email'     => true,
            'notify_inapp'     => true,
            'cooldown_hours'   => 24,
        ]);

        $this->actingAs($user)
            ->patch(route('portal.balance-alerts.toggle', $alert))
            ->assertForbidden();
    }

    // ── destroy ───────────────────────────────────────────────────────────────

    public function test_user_can_delete_balance_alert(): void
    {
        [$user, $account] = $this->makeCompanyUser();

        $alert = BalanceAlert::create([
            'account_id'       => $account->id,
            'threshold_amount' => 5000,
            'notify_email'     => true,
            'notify_inapp'     => true,
            'cooldown_hours'   => 24,
        ]);

        $this->actingAs($user)
            ->delete(route('portal.balance-alerts.destroy', $alert))
            ->assertRedirect();

        $this->assertDatabaseMissing('balance_alerts', ['id' => $alert->id]);
    }

    public function test_user_cannot_delete_another_users_alert(): void
    {
        [$user] = $this->makeCompanyUser();
        [$other, $otherAccount] = $this->makeCompanyUser();

        $alert = BalanceAlert::create([
            'account_id'       => $otherAccount->id,
            'threshold_amount' => 5000,
            'notify_email'     => true,
            'notify_inapp'     => true,
            'cooldown_hours'   => 24,
        ]);

        $this->actingAs($user)
            ->delete(route('portal.balance-alerts.destroy', $alert))
            ->assertForbidden();
    }
}
