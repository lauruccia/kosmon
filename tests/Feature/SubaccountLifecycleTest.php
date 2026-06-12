<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Transfer;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class SubaccountLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_top_up_a_subaccount_from_the_structure_screen(): void
    {
        [$owner, $rootAccount, $subaccount] = $this->makeOwnerAndSubaccount();

        $response = $this->actingAs($owner)->post(route('portal.accounts.subaccounts.budget', $subaccount), [
            'amount' => 12,   // 12 KY → 1200 centesimi
            'description' => 'Ricarica mensile',
        ]);

        $response->assertRedirect();
        $rootAccount->refresh();
        $subaccount->refresh();

        $this->assertSame(8800, $rootAccount->available_balance);
        $this->assertSame(1200, $subaccount->available_balance);
        $this->assertSame(1, Transfer::where('kind', 'subaccount_funding')->count());
    }

    public function test_owner_can_update_subaccount_limits(): void
    {
        [$owner, $rootAccount, $subaccount] = $this->makeOwnerAndSubaccount();

        $response = $this->actingAs($owner)
            ->withSession(['step_up_verified_at' => now()->timestamp])
            ->post(route('portal.accounts.subaccounts.limits', $subaccount), [
                'spending_limit'       => 4.5,  // 4,50 KY → 450 centesimi
                'daily_outgoing_limit' => 9,    // 9 KY → 900 centesimi
            ]);

        $response->assertRedirect();
        $subaccount->refresh();

        $this->assertSame(450, $subaccount->spending_limit);
        $this->assertSame(900, $subaccount->daily_outgoing_limit);
        $this->assertSame(1, AuditLog::where('event', 'subaccount.limits_updated')->count());
    }

    public function test_owner_can_suspend_a_subaccount_and_disable_delegate_login(): void
    {
        [$owner, $rootAccount, $subaccount, $delegate] = $this->makeOwnerAndSubaccount(withDelegate: true);

        $response = $this->actingAs($owner)->post(route('portal.accounts.subaccounts.status', $subaccount), [
            'status' => 'suspended',
        ]);

        $response->assertRedirect();
        $subaccount->refresh();
        $delegate->refresh();

        $this->assertSame('suspended', $subaccount->status);
        $this->assertFalse($delegate->is_active);
        $this->assertFalse(Auth::attempt([
            'email' => $delegate->email,
            'password' => 'secret123',
            'is_active' => true,
        ]));
    }

    public function test_delegate_dashboard_is_rendered_for_managed_subaccount(): void
    {
        [$owner, $rootAccount, $subaccount, $delegate] = $this->makeOwnerAndSubaccount(withDelegate: true);

        $response = $this->actingAs($delegate)->get('/dashboard');

        $response->assertOk();
        $response->assertSee('Vista delegato');
        $response->assertSee($subaccount->display_name);
    }

    private function makeOwnerAndSubaccount(bool $withDelegate = false): array
    {
        $company = Company::create([
            'name'          => 'Lifecycle SRL',
            'slug'          => 'lifecycle-srl',
            'status'        => 'active',
            'kyc_status'    => 'approved',
            'currency_code' => 'KY',
            'sector'        => 'informatica',
            'description'   => 'Test azienda',
        ]);

        $owner = User::create([
            'company_id'          => $company->id,
            'account_holder_type' => 'company',
            'name'                => 'Owner User',
            'email'               => 'owner@example.test',
            'password'            => 'secret123',
            'role'                => 'company-manager',
            'is_active'           => true,
            'is_super_admin'      => false,
            'email_verified_at'   => now(),
            'contract_signed_at'  => now(),
        ]);
        $ownerRole = Role::query()->where('slug', 'company-manager')->first();
        if ($ownerRole) {
            $owner->roles()->sync([$ownerRole->id]);
        }

        $rootAccount = Account::create([
            'company_id' => $company->id,
            'owner_user_id' => $owner->id,
            'owner_type' => 'company',
            'type' => 'primary',
            'account_name' => 'Conto principale Lifecycle',
            'currency_code' => 'KY',
            'status' => 'active',
            'allow_negative_balance' => false,
            'available_balance' => 10000,
            'pending_balance' => 0,
        ]);

        $subaccount = Account::create([
            'company_id' => $company->id,
            'owner_user_id' => $owner->id,
            'owner_type' => 'company',
            'parent_account_id' => $rootAccount->id,
            'assigned_by_user_id' => $owner->id,
            'type' => 'subaccount',
            'account_name' => 'Budget Team',
            'currency_code' => 'KY',
            'status' => 'active',
            'allow_negative_balance' => false,
            'available_balance' => 0,
            'pending_balance' => 0,
            'spending_limit' => 300,
            'daily_outgoing_limit' => 700,
        ]);

        $delegate = null;

        if ($withDelegate) {
            $delegate = User::create([
                'company_id'          => $company->id,
                'account_holder_type' => 'company',
                'managed_account_id'  => $subaccount->id,
                'name'                => 'Delegate User',
                'email'               => 'delegate@example.test',
                'password'            => 'secret123',
                'role'                => 'delegate-member',
                'is_active'           => true,
                'is_super_admin'      => false,
                'email_verified_at'   => now(),
                'contract_signed_at'  => now(),
            ]);
            $delegateRole = Role::query()->where('slug', 'delegate-member')->first();
            if ($delegateRole) {
                $delegate->roles()->sync([$delegateRole->id]);
            }
        }

        return [$owner, $rootAccount, $subaccount, $delegate];
    }
}
