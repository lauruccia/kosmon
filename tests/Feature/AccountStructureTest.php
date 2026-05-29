<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountStructureTest extends TestCase
{
    use RefreshDatabase;

    public function test_private_owner_can_create_subaccount_with_delegate(): void
    {
        $this->seed();

        $owner = User::query()->where('email', 'maria.ferri@kmoney.test')->firstOrFail();
        $rootAccount = Account::query()->where('owner_user_id', $owner->id)->whereNull('parent_account_id')->firstOrFail();

        $response = $this->actingAs($owner)->post('/conti/sottoconti', [
            'account_name' => 'Budget Spesa Casa',
            'initial_budget' => 300,
            'spending_limit' => 100,
            'daily_outgoing_limit' => 150,
            'manager_name' => 'Luca Ferri',
            'manager_email' => 'luca.ferri@kmoney.test',
            'manager_password' => 'secret123',
            'manager_role' => 'family-member',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('accounts', [
            'parent_account_id' => $rootAccount->id,
            'account_name' => 'Budget Spesa Casa',
            'spending_limit' => 100,
        ]);
        $this->assertDatabaseHas('users', [
            'email' => 'luca.ferri@kmoney.test',
            'role' => 'family-member',
        ]);
    }

    public function test_company_owner_can_create_subaccount_for_employee_with_limits(): void
    {
        $company = Company::create([
            'name' => 'Officina Nord SRL',
            'slug' => 'officina-nord-srl',
            'status' => 'active',
            'currency_code' => 'KY',
        ]);

        $owner = User::create([
            'company_id' => $company->id,
            'account_holder_type' => 'company',
            'name' => 'Giulia Bianchi',
            'email' => 'giulia.bianchi@kmoney.test',
            'password' => 'secret123',
            'role' => 'company-manager',
            'is_active' => true,
            'is_super_admin' => false,
        ]);

        $rootAccount = Account::create([
            'company_id' => $company->id,
            'owner_user_id' => $owner->id,
            'owner_type' => 'company',
            'type' => 'primary',
            'account_name' => 'Conto principale Officina Nord',
            'currency_code' => 'KY',
            'status' => 'active',
            'allow_negative_balance' => false,
            'available_balance' => 5000,
            'pending_balance' => 0,
        ]);

        $response = $this->actingAs($owner)->post('/conti/sottoconti', [
            'account_name' => 'Budget Trasferte Marco',
            'initial_budget' => 600,
            'spending_limit' => 200,
            'daily_outgoing_limit' => 350,
            'manager_name' => 'Marco Verdi',
            'manager_email' => 'marco.verdi@kmoney.test',
            'manager_password' => 'secret123',
            'manager_role' => 'employee',
        ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('accounts', [
            'parent_account_id' => $rootAccount->id,
            'company_id' => $company->id,
            'owner_type' => 'company',
            'account_name' => 'Budget Trasferte Marco',
            'spending_limit' => 200,
            'daily_outgoing_limit' => 350,
            'available_balance' => 600,
        ]);

        $subaccount = Account::query()
            ->where('parent_account_id', $rootAccount->id)
            ->where('account_name', 'Budget Trasferte Marco')
            ->firstOrFail();

        $this->assertDatabaseHas('users', [
            'company_id' => $company->id,
            'managed_account_id' => $subaccount->id,
            'email' => 'marco.verdi@kmoney.test',
            'role' => 'employee',
            'account_holder_type' => 'company',
            'is_active' => true,
        ]);

        $rootAccount->refresh();

        $this->assertSame(4400, $rootAccount->available_balance);
    }
}
