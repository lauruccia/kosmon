<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\SubAccountInvitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AccountStructureTest extends TestCase
{
    use RefreshDatabase;

    public function test_private_owner_can_create_subaccount_with_delegate(): void
    {
        Notification::fake();
        $this->seed();

        $owner = User::query()->where('email', 'maria.ferri@kmoney.test')->firstOrFail();
        $rootAccount = Account::query()->where('owner_user_id', $owner->id)->whereNull('parent_account_id')->firstOrFail();

        $response = $this->actingAs($owner)->post('/conti/sottoconti', [
            'account_name'        => 'Budget Spesa Casa',
            'spending_limit'      => 1,     // 1 KY → 100 centesimi
            'daily_outgoing_limit' => 1.5,  // 1,50 KY → 150 centesimi
            'manager_email'       => 'luca.ferri@kmoney.test',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('accounts', [
            'parent_account_id' => $rootAccount->id,
            'account_name'      => 'Budget Spesa Casa',
            'spending_limit'    => 100,
            'daily_outgoing_limit' => 150,
        ]);
    }

    public function test_company_owner_can_create_subaccount_for_employee_with_limits(): void
    {
        Notification::fake();

        $company = Company::create([
            'name'          => 'Officina Nord SRL',
            'slug'          => 'officina-nord-srl',
            'status'        => 'active',
            'kyc_status'    => 'approved',
            'sector'        => 'Artigianato',
            'description'   => 'Officina meccanica',
            'currency_code' => 'KY',
        ]);

        $owner = User::create([
            'company_id'          => $company->id,
            'account_holder_type' => 'company',
            'name'                => 'Giulia Bianchi',
            'email'               => 'giulia.bianchi@kmoney.test',
            'password'            => 'secret123',
            'role'                => 'company-manager',
            'is_active'           => true,
            'is_super_admin'      => false,
            'email_verified_at'   => now(),
            'contract_signed_at'  => now(),
        ]);

        $rootAccount = Account::create([
            'company_id'        => $company->id,
            'owner_user_id'     => $owner->id,
            'owner_type'        => 'company',
            'type'              => 'primary',
            'account_name'      => 'Conto principale Officina Nord',
            'currency_code'     => 'KY',
            'status'            => 'active',
            'allow_negative_balance' => false,
            'available_balance' => 5000,
            'pending_balance'   => 0,
        ]);

        $response = $this->actingAs($owner)->post('/conti/sottoconti', [
            'account_name'        => 'Budget Trasferte Marco',
            'spending_limit'      => 2,    // 2 KY → 200 centesimi
            'daily_outgoing_limit' => 3.5, // 3,50 KY → 350 centesimi
            'manager_email'       => 'marco.verdi@kmoney.test',
        ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('accounts', [
            'parent_account_id'   => $rootAccount->id,
            'company_id'          => $company->id,
            'owner_type'          => 'company',
            'account_name'        => 'Budget Trasferte Marco',
            'spending_limit'      => 200,
            'daily_outgoing_limit' => 350,
        ]);
    }
}
