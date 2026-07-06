<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Verifica che i limiti finanziari configurabili da backoffice siano coerenti tra loro:
 * un limite con periodo/ambito più ampio (mensile) non può mai essere inferiore a uno
 * più ristretto (giornaliero, per singola operazione) già impostato.
 *
 * Copre i due punti di ingresso principali: i limiti di trasferimento dell'utente
 * (App\Http\Controllers\Admin\UserController::updateUser) e il fido di un'azienda
 * (App\Http\Controllers\Admin\CreditLimitController::setCreditLimit).
 */
class LimitCoherenceValidationTest extends TestCase
{
    use RefreshDatabase;

    private function makeSuperAdmin(): User
    {
        $admin = User::create([
            'name'                => 'Super Admin',
            'email'               => 'admin-limits-' . Str::random(8) . '@test.test',
            'password'            => 'secret123',
            'account_holder_type' => 'private',
            'company_id'          => null,
            'is_active'           => true,
            'is_super_admin'      => true,
        ]);
        $admin->forceFill(['email_verified_at' => now()])->save();

        return $admin;
    }

    public function test_it_rejects_a_daily_transaction_limit_higher_than_the_monthly_one(): void
    {
        $admin  = $this->makeSuperAdmin();
        $target = User::create([
            'name'                => 'Operatore',
            'email'               => 'op-' . Str::random(8) . '@test.test',
            'password'            => 'secret123',
            'account_holder_type' => 'private',
            'is_active'           => true,
        ]);

        // Esattamente lo scenario segnalato: giornaliero 1.200 KY, mensile 50 KY.
        $response = $this->actingAs($admin)->post(route('admin.users.update', $target), [
            'name'                      => $target->name,
            'email'                     => $target->email,
            'account_holder_type'       => 'private',
            'is_active'                 => 1,
            'daily_transaction_limit'   => '1200',
            'monthly_transaction_limit' => '50',
        ]);

        $response->assertSessionHasErrors('monthly_transaction_limit');

        // Nessuna scrittura parziale: i limiti non devono essere stati salvati.
        $this->assertNull($target->fresh()->daily_transaction_limit);
        $this->assertNull($target->fresh()->monthly_transaction_limit);
    }

    public function test_it_rejects_a_per_movement_limit_higher_than_the_daily_one(): void
    {
        $admin  = $this->makeSuperAdmin();
        $target = User::create([
            'name'                => 'Operatore 2',
            'email'               => 'op2-' . Str::random(8) . '@test.test',
            'password'            => 'secret123',
            'account_holder_type' => 'private',
            'is_active'           => true,
        ]);

        $response = $this->actingAs($admin)->post(route('admin.users.update', $target), [
            'name'                    => $target->name,
            'email'                   => $target->email,
            'account_holder_type'     => 'private',
            'is_active'               => 1,
            'per_movement_limit'      => '500',
            'daily_transaction_limit' => '100',
        ]);

        $response->assertSessionHasErrors('daily_transaction_limit');
        $this->assertNull($target->fresh()->per_movement_limit);
    }

    public function test_it_accepts_ascending_and_coherent_user_transfer_limits(): void
    {
        $admin  = $this->makeSuperAdmin();
        $target = User::create([
            'name'                => 'Operatore 3',
            'email'               => 'op3-' . Str::random(8) . '@test.test',
            'password'            => 'secret123',
            'account_holder_type' => 'private',
            'is_active'           => true,
        ]);

        $response = $this->actingAs($admin)->post(route('admin.users.update', $target), [
            'name'                      => $target->name,
            'email'                     => $target->email,
            'account_holder_type'       => 'private',
            'is_active'                 => 1,
            'per_movement_limit'        => '100',
            'daily_transaction_limit'   => '500',
            'monthly_transaction_limit' => '5000',
        ]);

        $response->assertSessionDoesntHaveErrors();

        $fresh = $target->fresh();
        $this->assertSame(10000, $fresh->per_movement_limit);
        $this->assertSame(50000, $fresh->daily_transaction_limit);
        $this->assertSame(500000, $fresh->monthly_transaction_limit);
    }

    public function test_it_rejects_a_fido_single_transfer_limit_higher_than_the_daily_one(): void
    {
        $admin   = $this->makeSuperAdmin();
        $company = Company::create([
            'name'          => 'Azienda Fido SRL',
            'slug'          => 'azienda-fido-' . Str::lower(Str::random(6)),
            'status'        => 'active',
            'kyc_status'    => 'approved',
            'currency_code' => 'KY',
        ]);

        $account = \App\Models\Account::create([
            'company_id'      => $company->id,
            'type'            => 'primary',
            'owner_type'      => 'company',
            'currency_code'   => 'KY',
            'status'          => 'active',
            'available_balance' => 0,
            'pending_balance'   => 0,
        ]);

        $response = $this->actingAs($admin)->post(route('admin.companies.credit-limit', $company), [
            'credit_limit'          => '10000',
            'daily_outgoing_limit'  => '100',
            'single_transfer_limit' => '500',
        ]);

        $response->assertSessionHasErrors('daily_outgoing_limit');
        $this->assertDatabaseMissing('credit_limits', ['account_id' => $account->id]);
    }
}
