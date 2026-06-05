<?php

namespace Tests\Feature;

use App\Jobs\ProcessScheduledPayments;
use App\Models\Account;
use App\Models\Company;
use App\Models\ScheduledPayment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Test HTTP layer ScheduledPaymentController + ProcessScheduledPayments job.
 */
class ScheduledPaymentControllerTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // Index
    // -------------------------------------------------------------------------

    public function test_index_requires_authentication(): void
    {
        $this->get(route('portal.scheduled-payments.index'))
            ->assertRedirect(route('login'));
    }

    public function test_user_can_view_scheduled_payments_index(): void
    {
        [$user] = $this->makeCompanyUser();

        $this->actingAs($user)
            ->get(route('portal.scheduled-payments.index'))
            ->assertOk();
    }

    // -------------------------------------------------------------------------
    // Create
    // -------------------------------------------------------------------------

    public function test_user_can_view_scheduled_payment_create(): void
    {
        [$user] = $this->makeCompanyUser();

        $this->actingAs($user)
            ->get(route('portal.scheduled-payments.create'))
            ->assertOk();
    }

    // -------------------------------------------------------------------------
    // Store
    // -------------------------------------------------------------------------

    public function test_user_can_schedule_a_payment(): void
    {
        [$senderUser, $senderAccount] = $this->makeCompanyUser(5000);
        [, $recipientAccount]         = $this->makeCompanyUser(0);

        $scheduledAt = now()->addHour()->format('Y-m-d\TH:i');

        $response = $this->actingAs($senderUser)
            ->post(route('portal.scheduled-payments.store'), [
                'to_account_id' => $recipientAccount->id,
                'amount'        => 3,   // 3 KY → 300 centesimi
                'description'   => 'Pagamento rata mensile',
                'scheduled_at'  => $scheduledAt,
            ]);

        $payment = ScheduledPayment::where('from_account_id', $senderAccount->id)->first();
        $this->assertNotNull($payment);
        $this->assertSame(300, $payment->amount);
        $this->assertSame('pending', $payment->status);

        $response->assertRedirect(route('portal.scheduled-payments.show', $payment));
    }

    public function test_store_validates_required_fields(): void
    {
        [$user] = $this->makeCompanyUser();

        $this->actingAs($user)
            ->post(route('portal.scheduled-payments.store'), [])
            ->assertSessionHasErrors(['to_account_id', 'amount', 'description', 'scheduled_at']);
    }

    public function test_store_rejects_past_scheduled_date(): void
    {
        [$user] = $this->makeCompanyUser(5000);
        [, $recipient] = $this->makeCompanyUser(0);

        $this->actingAs($user)
            ->post(route('portal.scheduled-payments.store'), [
                'to_account_id' => $recipient->id,
                'amount'        => 100,
                'description'   => 'Test',
                'scheduled_at'  => now()->subHour()->format('Y-m-d\TH:i'),
            ])
            ->assertSessionHasErrors('scheduled_at');
    }

    public function test_store_rejects_payment_to_self(): void
    {
        [$user, $account] = $this->makeCompanyUser(5000);

        $this->actingAs($user)
            ->post(route('portal.scheduled-payments.store'), [
                'to_account_id' => $account->id,
                'amount'        => 100,
                'description'   => 'Autocredito',
                'scheduled_at'  => now()->addHour()->format('Y-m-d\TH:i'),
            ])
            ->assertStatus(422);
    }

    // -------------------------------------------------------------------------
    // Show
    // -------------------------------------------------------------------------

    public function test_sender_can_view_scheduled_payment(): void
    {
        [$senderUser, $senderAccount] = $this->makeCompanyUser(5000);
        [, $recipientAccount]         = $this->makeCompanyUser(0);
        $payment = $this->makeScheduledPayment($senderUser, $senderAccount, $recipientAccount);

        $this->actingAs($senderUser)
            ->get(route('portal.scheduled-payments.show', $payment))
            ->assertOk();
    }

    public function test_recipient_can_view_scheduled_payment(): void
    {
        [$senderUser, $senderAccount] = $this->makeCompanyUser(5000);
        [$recipientUser, $recipientAccount] = $this->makeCompanyUser(0);
        $payment = $this->makeScheduledPayment($senderUser, $senderAccount, $recipientAccount);

        $this->actingAs($recipientUser)
            ->get(route('portal.scheduled-payments.show', $payment))
            ->assertOk();
    }

    public function test_third_party_cannot_view_scheduled_payment(): void
    {
        [$senderUser, $senderAccount]     = $this->makeCompanyUser(5000);
        [, $recipientAccount]             = $this->makeCompanyUser(0);
        [$thirdUser]                      = $this->makeCompanyUser(0);
        $payment = $this->makeScheduledPayment($senderUser, $senderAccount, $recipientAccount);

        $this->actingAs($thirdUser)
            ->get(route('portal.scheduled-payments.show', $payment))
            ->assertForbidden();
    }

    // -------------------------------------------------------------------------
    // Cancel
    // -------------------------------------------------------------------------

    public function test_sender_can_cancel_pending_payment(): void
    {
        [$senderUser, $senderAccount] = $this->makeCompanyUser(5000);
        [, $recipientAccount]         = $this->makeCompanyUser(0);
        $payment = $this->makeScheduledPayment($senderUser, $senderAccount, $recipientAccount);

        $this->actingAs($senderUser)
            ->post(route('portal.scheduled-payments.cancel', $payment))
            ->assertRedirect(route('portal.scheduled-payments.index'));

        $this->assertSame('cancelled', $payment->fresh()->status);
    }

    public function test_recipient_cannot_cancel_payment(): void
    {
        [$senderUser, $senderAccount]       = $this->makeCompanyUser(5000);
        [$recipientUser, $recipientAccount] = $this->makeCompanyUser(0);
        $payment = $this->makeScheduledPayment($senderUser, $senderAccount, $recipientAccount);

        $this->actingAs($recipientUser)
            ->post(route('portal.scheduled-payments.cancel', $payment))
            ->assertForbidden();

        $this->assertSame('pending', $payment->fresh()->status);
    }

    // -------------------------------------------------------------------------
    // ProcessScheduledPayments Job
    // -------------------------------------------------------------------------

    public function test_job_executes_due_payment_and_marks_executed(): void
    {
        [$senderUser, $senderAccount] = $this->makeCompanyUser(5000);
        [, $recipientAccount]         = $this->makeCompanyUser(0);

        $payment = $this->makeScheduledPayment(
            $senderUser,
            $senderAccount,
            $recipientAccount,
            ['scheduled_at' => now()->subMinute()]  // scaduto → da eseguire
        );

        $this->app->call([new ProcessScheduledPayments(), 'handle']);

        $payment->refresh();
        $this->assertSame('executed', $payment->status);
        $this->assertNotNull($payment->transfer_id);
    }

    public function test_job_leaves_future_payments_pending(): void
    {
        [$senderUser, $senderAccount] = $this->makeCompanyUser(5000);
        [, $recipientAccount]         = $this->makeCompanyUser(0);

        $payment = $this->makeScheduledPayment(
            $senderUser,
            $senderAccount,
            $recipientAccount,
            ['scheduled_at' => now()->addHour()]  // futuro
        );

        $this->app->call([new ProcessScheduledPayments(), 'handle']);

        $this->assertSame('pending', $payment->fresh()->status);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** @return array{User, Account, Company} */
    private function makeCompanyUser(int $balance = 0): array
    {
        $company = Company::create([
            'name'          => 'SpCo ' . Str::random(4),
            'slug'          => 'spco-' . Str::random(6),
            'email'         => 'sp-' . Str::random(6) . '@test.test',
            'status'        => 'active',
            'kyc_status'    => 'approved',
            'currency_code' => 'KY',
        ]);

        $user = User::create([
            'name'                => 'SP User',
            'email'               => 'spuser-' . Str::random(8) . '@test.test',
            'password'            => 'secret123',
            'account_holder_type' => 'company',
            'company_id'          => $company->id,
            'role'                => 'company-manager',
            'is_active'           => true,
            'is_super_admin'      => false,
            'email_verified_at'   => now(),
            'contract_signed_at'  => now(),
        ]);

        $account = Account::create([
            'company_id'             => $company->id,
            'owner_user_id'          => $user->id,
            'owner_type'             => 'company',
            'type'                   => 'primary',
            'currency_code'          => 'KY',
            'status'                 => 'active',
            'available_balance'      => $balance,
            'allow_negative_balance' => false,
        ]);

        return [$user, $account, $company];
    }

    private function makeScheduledPayment(
        User $creator,
        Account $from,
        Account $to,
        array $overrides = [],
    ): ScheduledPayment {
        return ScheduledPayment::create(array_merge([
            'from_account_id' => $from->id,
            'to_account_id'   => $to->id,
            'amount'          => 250,
            'description'     => 'Test scheduled',
            'status'          => 'pending',
            'scheduled_at'    => now()->addDay(),
            'created_by'      => $creator->id,
        ], $overrides));
    }
}
