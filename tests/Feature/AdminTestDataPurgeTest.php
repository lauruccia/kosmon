<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\KyCard;
use App\Models\KyCardPurchase;
use App\Models\LedgerEntry;
use App\Models\Transfer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Verifica la cancellazione COMPLETA di dati di test (intera azienda o singolo
 * conto privato), con particolare attenzione a:
 *  - l'invariante del circuito chiuso (SUM(saldi) = 0) anche quando una
 *    controparte REALE è coinvolta nei movimenti di test;
 *  - la protezione contro i "soldi reali" (acquisti KY Card completati);
 *  - le autorizzazioni (solo super admin) e la conferma testuale obbligatoria.
 */
class AdminTestDataPurgeTest extends TestCase
{
    use RefreshDatabase;

    private function makeTestCompanyWithAccount(): array
    {
        $company = Company::factory()->create(['name' => 'Azienda Prova XYZ']);
        $account = Account::factory()->create([
            'company_id'         => $company->id,
            'owner_type'         => 'company',
            'available_balance'  => 0,
        ]);
        $user = User::factory()->create([
            'company_id'          => $company->id,
            'account_holder_type' => 'company',
        ]);

        return [$company, $account, $user];
    }

    private function bookManualTransfer(Account $from, Account $to, int $amount): Transfer
    {
        $transfer = Transfer::create([
            'from_account_id' => $from->id,
            'to_account_id'   => $to->id,
            'amount'          => $amount,
            'currency_code'   => 'KY',
            'kind'            => 'trade_payment',
            'status'          => 'booked',
            'description'     => 'Movimento di prova',
            'idempotency_key' => (string) Str::uuid(),
            'booked_at'       => now(),
        ]);

        LedgerEntry::create(['transfer_id' => $transfer->id, 'account_id' => $from->id, 'direction' => 'debit', 'amount' => $amount, 'balance_after' => $from->available_balance - $amount, 'posted_at' => now()]);
        LedgerEntry::create(['transfer_id' => $transfer->id, 'account_id' => $to->id, 'direction' => 'credit', 'amount' => $amount, 'balance_after' => $to->available_balance + $amount, 'posted_at' => now()]);

        $from->forceFill(['available_balance' => $from->available_balance - $amount])->save();
        $to->forceFill(['available_balance' => $to->available_balance + $amount])->save();

        return $transfer;
    }

    public function test_superadmin_can_purge_company_and_restores_real_counterparty_balance(): void
    {
        $this->seed();

        $admin = User::where('email', 'superadmin@kmoney.test')->firstOrFail();
        [$company, $testAccount] = $this->makeTestCompanyWithAccount();

        // Finanzia il conto di test dal conto sistema, poi genera un movimento
        // di test verso un conto REALE (seedato) — questa è la controparte la cui
        // storia deve tornare pulita dopo la purge.
        $system = Account::systemAccount();
        $this->bookManualTransfer($system, $testAccount, 10000);

        $realAccount = Account::query()
            ->where('is_system_account', false)
            ->where('id', '!=', $testAccount->id)
            ->first();
        $this->assertNotNull($realAccount, 'serve almeno un conto reale seedato');
        $realBalanceBefore = (int) $realAccount->available_balance;

        $this->bookManualTransfer($testAccount, $realAccount, 3000);

        $totalBefore = (int) Account::query()->sum('available_balance');
        $this->assertSame(0, $totalBefore);

        $response = $this->actingAs($admin)->get(route('admin.companies.purge-test', $company));
        $response->assertOk();

        $this->actingAs($admin)
            ->post(route('admin.companies.purge-test.destroy', $company), ['confirmation' => 'Azienda Prova XYZ'])
            ->assertRedirect();

        $this->assertDatabaseMissing('companies', ['id' => $company->id]);
        $this->assertDatabaseMissing('accounts', ['id' => $testAccount->id]);

        // La controparte reale torna esattamente al saldo che aveva prima del
        // movimento di test: nessuna traccia residua.
        $realAccount->refresh();
        $this->assertSame($realBalanceBefore, (int) $realAccount->available_balance);

        // Circuito ancora perfettamente a 0.
        $this->assertSame(0, (int) Account::query()->sum('available_balance'));
    }

    public function test_purge_requires_exact_confirmation_text(): void
    {
        $this->seed();

        $admin = User::where('email', 'superadmin@kmoney.test')->firstOrFail();
        [$company] = $this->makeTestCompanyWithAccount();

        $this->actingAs($admin)
            ->post(route('admin.companies.purge-test.destroy', $company), ['confirmation' => 'nome sbagliato'])
            ->assertStatus(422);

        $this->assertDatabaseHas('companies', ['id' => $company->id]);
    }

    public function test_purge_blocks_when_real_money_present_unless_forced(): void
    {
        $this->seed();

        $admin = User::where('email', 'superadmin@kmoney.test')->firstOrFail();
        [$company, $testAccount, $testUser] = $this->makeTestCompanyWithAccount();

        $card = KyCard::create([
            'name'            => 'Test Pack',
            'price_eur_cents' => 10000,
            'bonus_type'      => 'fixed',
            'ky_base_amount'  => 10000,
            'bonus_value'     => 0,
            'is_active'       => true,
        ]);

        KyCardPurchase::create([
            'ky_card_id'      => $card->id,
            'account_id'      => $testAccount->id,
            'user_id'         => $testUser->id,
            'price_eur_cents' => 10000,
            'ky_amount'       => 10000,
            'status'          => 'completed',
            'payment_method'  => 'stripe',
            'completed_at'    => now(),
        ]);

        // Senza force=1: bloccato, azienda intatta.
        $this->actingAs($admin)
            ->post(route('admin.companies.purge-test.destroy', $company), ['confirmation' => $company->name])
            ->assertStatus(422);
        $this->assertDatabaseHas('companies', ['id' => $company->id]);

        // Con force=1: l'admin si assume la responsabilità, procede.
        $this->actingAs($admin)
            ->post(route('admin.companies.purge-test.destroy', $company), ['confirmation' => $company->name, 'force' => '1'])
            ->assertRedirect();
        $this->assertDatabaseMissing('companies', ['id' => $company->id]);
    }

    public function test_non_super_admin_cannot_purge_company(): void
    {
        $this->seed();

        $user = User::factory()->create(['is_super_admin' => false, 'role' => 'broker']);
        [$company] = $this->makeTestCompanyWithAccount();

        $this->actingAs($user)
            ->post(route('admin.companies.purge-test.destroy', $company), ['confirmation' => $company->name])
            ->assertForbidden();

        $this->assertDatabaseHas('companies', ['id' => $company->id]);
    }

    public function test_can_purge_standalone_private_account(): void
    {
        $this->seed();

        $admin = User::where('email', 'superadmin@kmoney.test')->firstOrFail();

        $privateUser = User::factory()->create(['account_holder_type' => 'private']);
        $privateAccount = Account::factory()->create([
            'company_id'        => null,
            'owner_type'        => 'private',
            'owner_user_id'     => $privateUser->id,
            'available_balance' => 0,
        ]);

        $system = Account::systemAccount();
        $this->bookManualTransfer($system, $privateAccount, 5000);

        $this->assertSame(0, (int) Account::query()->sum('available_balance'));

        $label = $privateUser->name;

        $this->actingAs($admin)
            ->post(route('admin.accounts.purge-test.destroy', $privateAccount), ['confirmation' => $label])
            ->assertRedirect();

        $this->assertDatabaseMissing('accounts', ['id' => $privateAccount->id]);
        $this->assertDatabaseMissing('users', ['id' => $privateUser->id]);
        $this->assertSame(0, (int) Account::query()->sum('available_balance'));
    }

    public function test_cannot_purge_business_account_directly_must_use_company_route(): void
    {
        $this->seed();

        $admin = User::where('email', 'superadmin@kmoney.test')->firstOrFail();
        [, $testAccount] = $this->makeTestCompanyWithAccount();

        $this->actingAs($admin)
            ->get(route('admin.accounts.purge-test', $testAccount))
            ->assertStatus(422);
    }
}
