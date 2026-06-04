<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class BrokerControllerTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // Dashboard
    // -------------------------------------------------------------------------

    public function test_broker_can_view_dashboard(): void
    {
        [$broker] = $this->makeBroker();

        $this->actingAs($broker)
            ->get(route('broker.dashboard'))
            ->assertOk();
    }

    public function test_regular_user_cannot_view_broker_dashboard(): void
    {
        $user = $this->makeRegularUser();

        $this->actingAs($user)
            ->get(route('broker.dashboard'))
            ->assertForbidden();
    }

    public function test_broker_dashboard_requires_authentication(): void
    {
        $this->get(route('broker.dashboard'))
            ->assertRedirect(route('login'));
    }

    public function test_admin_can_view_broker_dashboard(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->get(route('broker.dashboard'))
            ->assertOk();
    }

    // -------------------------------------------------------------------------
    // Show client
    // -------------------------------------------------------------------------

    public function test_broker_can_view_assigned_client(): void
    {
        [$broker, $company, $account] = $this->makeBroker();

        $this->actingAs($broker)
            ->get(route('broker.clients.show', $company))
            ->assertOk();
    }

    public function test_broker_cannot_view_unassigned_client(): void
    {
        [$broker] = $this->makeBroker();
        // Altra azienda senza broker assegnato
        $otherCompany = $this->makeCompanyWithAccount();

        $this->actingAs($broker)
            ->get(route('broker.clients.show', $otherCompany))
            ->assertForbidden();
    }

    // -------------------------------------------------------------------------
    // Pay form
    // -------------------------------------------------------------------------

    public function test_broker_can_view_pay_form_for_assigned_client(): void
    {
        [$broker, $company, $account] = $this->makeBroker();

        $this->actingAs($broker)
            ->get(route('broker.pay.form', $company))
            ->assertOk();
    }

    public function test_broker_cannot_view_pay_form_for_unassigned_client(): void
    {
        [$broker] = $this->makeBroker();
        $otherCompany = $this->makeCompanyWithAccount();

        $this->actingAs($broker)
            ->get(route('broker.pay.form', $otherCompany))
            ->assertForbidden();
    }

    // -------------------------------------------------------------------------
    // Pay submit
    // -------------------------------------------------------------------------

    public function test_broker_can_submit_payment_for_client(): void
    {
        [$broker, $company, $fromAccount] = $this->makeBroker();
        // Conto destinatario
        [$recipientUser, $toAccount] = $this->makeUserAndAccount(0);

        // Finanzia il conto cliente con saldo sufficiente
        $fromAccount->update(['available_balance' => 5000]);

        $response = $this->actingAs($broker)
            ->post(route('broker.pay.submit', $company), [
                'to_account_id' => $toAccount->id,
                'amount'        => 5,   // 5 KY → 500 centesimi
                'description'   => 'Pagamento broker test',
            ]);

        $response->assertRedirect(route('broker.clients.show', $company));
        $this->assertDatabaseHas('transfers', [
            'from_account_id' => $fromAccount->id,
            'to_account_id'   => $toAccount->id,
            'amount'          => 500,
            'kind'            => 'broker_payment',
            'status'          => 'booked',
        ]);
    }

    public function test_broker_pay_fails_with_insufficient_balance(): void
    {
        [$broker, $company, $fromAccount] = $this->makeBroker();
        [$recipientUser, $toAccount] = $this->makeUserAndAccount(0);

        $fromAccount->update(['available_balance' => 0, 'allow_negative_balance' => false]);

        $this->actingAs($broker)
            ->post(route('broker.pay.submit', $company), [
                'to_account_id' => $toAccount->id,
                'amount'        => 999,
                'description'   => '',
            ])
            ->assertRedirect(); // back with error

        $this->assertDatabaseMissing('transfers', [
            'from_account_id' => $fromAccount->id,
            'kind'            => 'broker_payment',
            'status'          => 'booked',
        ]);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Crea broker user + company assegnata + account azienda.
     * Restituisce [$brokerUser, $company, $account].
     */
    private function makeBroker(): array
    {
        $broker = User::create([
            'name'                => 'Broker ' . Str::random(4),
            'email'               => 'broker-' . Str::random(8) . '@test.test',
            'password'            => 'secret123',
            'account_holder_type' => 'private',
            'company_id'          => null,
            'is_active'           => true,
            'is_super_admin'      => false,
            'email_verified_at'   => now(),
            'role'                => 'broker',
        ]);

        $brokerRole = Role::firstOrCreate(['slug' => 'broker'], ['name' => 'Broker']);
        $broker->roles()->syncWithoutDetaching([$brokerRole->id]);

        $company = $this->makeCompanyWithAccount($broker->id);
        $account = $company->accounts()->whereNull('parent_account_id')->firstOrFail();

        return [$broker, $company, $account];
    }

    private function makeCompanyWithAccount(?int $brokerUserId = null): Company
    {
        $company = Company::create([
            'name'            => 'Azienda ' . Str::random(6),
            'slug'            => 'azienda-' . Str::random(6),
            'vat_number'      => 'IT' . rand(10000000000, 99999999999),
            'status'          => 'active',
            'broker_user_id'  => $brokerUserId,
        ]);

        Account::create([
            'company_id'        => $company->id,
            'owner_type'        => 'company',
            'type'              => 'primary',
            'status'            => 'active',
            'available_balance' => 5000,
            'allow_negative_balance' => false,
        ]);

        return $company;
    }

    private function makeRegularUser(): User
    {
        return User::create([
            'name'                => 'Regular User',
            'email'               => 'regular-' . Str::random(8) . '@test.test',
            'password'            => 'secret123',
            'account_holder_type' => 'private',
            'company_id'          => null,
            'is_active'           => true,
            'is_super_admin'      => false,
            'email_verified_at'   => now(),
        ]);
    }

    private function makeAdmin(): User
    {
        return User::create([
            'name'                => 'Super Admin',
            'email'               => 'superadmin-' . Str::random(8) . '@test.test',
            'password'            => 'secret123',
            'account_holder_type' => 'private',
            'company_id'          => null,
            'is_active'           => true,
            'is_super_admin'      => true,
            'email_verified_at'   => now(),
        ]);
    }

    private function makeUserAndAccount(int $balance = 0): array
    {
        $user = User::create([
            'name'                => 'User',
            'email'               => 'u-' . Str::random(8) . '@test.test',
            'password'            => 'secret123',
            'account_holder_type' => 'private',
            'company_id'          => null,
            'is_active'           => true,
            'is_super_admin'      => false,
            'email_verified_at'   => now(),
        ]);

        $account = Account::create([
            'owner_user_id'     => $user->id,
            'owner_type'        => 'private',
            'type'              => 'member',
            'status'            => 'active',
            'available_balance' => $balance,
            'allow_negative_balance' => true,
        ]);

        return [$user, $account];
    }
}
