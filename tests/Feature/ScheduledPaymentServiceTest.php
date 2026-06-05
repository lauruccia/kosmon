<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\ScheduledPayment;
use App\Models\User;
use App\Services\ScheduledPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ScheduledPaymentServiceTest extends TestCase
{
    use RefreshDatabase;

    private ScheduledPaymentService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ScheduledPaymentService::class);
    }

    private function makeAccount(int $balance = 100000): array
    {
        $company = Company::factory()->create(['kyc_status' => 'approved']);
        $user = User::factory()->create([
            'email_verified_at'  => now(),
            'company_id'         => $company->id,
            'role'               => 'company-manager',
            'account_holder_type' => 'company',
        ]);
        $account = Account::factory()->create([
            'company_id'        => $company->id,
            'owner_user_id'     => $user->id,
            'status'            => 'active',
            'currency_code'     => 'KY',
            'available_balance' => $balance,
        ]);
        return [$user, $account];
    }

    /** create() — genera un ScheduledPayment in stato pending */
    public function test_create_returns_pending_payment(): void
    {
        [$userA, $accountA] = $this->makeAccount(50000);
        [, $accountB] = $this->makeAccount();

        $payment = $this->service->create(
            fromAccount: $accountA,
            toAccount: $accountB,
            amount: 1000,
            description: 'Test programmato',
            scheduledAt: now()->addDay(),
            createdBy: $userA,
        );

        $this->assertInstanceOf(ScheduledPayment::class, $payment);
        $this->assertEquals('pending', $payment->status);
        $this->assertEquals(1000, $payment->amount);
        $this->assertEquals($accountA->id, $payment->from_account_id);
        $this->assertEquals($accountB->id, $payment->to_account_id);
    }

    /** execute() — esegue il pagamento e porta lo stato a executed */
    public function test_execute_completes_payment_and_transfers_funds(): void
    {
        Notification::fake();
        [$userA, $accountA] = $this->makeAccount(50000);
        [, $accountB] = $this->makeAccount(0);

        $payment = $this->service->create(
            fromAccount: $accountA,
            toAccount: $accountB,
            amount: 2000,
            description: 'Esecuzione test',
            scheduledAt: now()->subMinute(),
            createdBy: $userA,
        );

        $this->service->execute($payment);

        $this->assertEquals('executed', $payment->fresh()->status);
        $this->assertNotNull($payment->fresh()->executed_at);
        $this->assertNotNull($payment->fresh()->transfer_id);
    }

    /** execute() — saldo insufficiente porta lo stato a failed */
    public function test_execute_fails_gracefully_on_insufficient_balance(): void
    {
        Notification::fake();
        [$userA, $accountA] = $this->makeAccount(10); // balance quasi zero
        [, $accountB] = $this->makeAccount(0);

        $payment = $this->service->create(
            fromAccount: $accountA,
            toAccount: $accountB,
            amount: 100000,
            description: 'Saldo insufficiente',
            scheduledAt: now()->subMinute(),
            createdBy: $userA,
        );

        $this->service->execute($payment);

        $this->assertEquals('failed', $payment->fresh()->status);
        $this->assertNotNull($payment->fresh()->failure_reason);
    }

    /** execute() — idempotente: doppia esecuzione non duplica il trasferimento */
    public function test_execute_is_idempotent(): void
    {
        Notification::fake();
        [$userA, $accountA] = $this->makeAccount(50000);
        [, $accountB] = $this->makeAccount(0);

        $payment = $this->service->create(
            fromAccount: $accountA,
            toAccount: $accountB,
            amount: 500,
            description: 'Idempotency test',
            scheduledAt: now()->subMinute(),
            createdBy: $userA,
        );

        $this->service->execute($payment);
        $firstTransferId = $payment->fresh()->transfer_id;

        // Seconda chiamata — payment non è più pending, deve essere ignorata
        $this->service->execute($payment->fresh());

        $this->assertEquals($firstTransferId, $payment->fresh()->transfer_id);
        $this->assertEquals('executed', $payment->fresh()->status);
    }

    /** cancel() — annulla il pagamento se appartiene all'account */
    public function test_cancel_own_payment(): void
    {
        [$userA, $accountA] = $this->makeAccount(50000);
        [, $accountB] = $this->makeAccount();

        $payment = $this->service->create(
            fromAccount: $accountA,
            toAccount: $accountB,
            amount: 300,
            description: 'Da annullare',
            scheduledAt: now()->addHour(),
            createdBy: $userA,
        );

        $this->service->cancel($payment, $accountA);
        $this->assertEquals('cancelled', $payment->fresh()->status);
    }

    /** cancel() — 403 se l'account non è il mittente */
    public function test_cancel_others_payment_forbidden(): void
    {
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

        [$userA, $accountA] = $this->makeAccount(50000);
        [, $accountB] = $this->makeAccount();

        $payment = $this->service->create(
            fromAccount: $accountA,
            toAccount: $accountB,
            amount: 300,
            description: 'Non tuo',
            scheduledAt: now()->addHour(),
            createdBy: $userA,
        );

        // accountB tenta di annullare il pagamento di accountA
        $this->service->cancel($payment, $accountB);
    }

    /** cancel() — non si può annullare un pagamento già eseguito */
    public function test_cancel_executed_payment_forbidden(): void
    {
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        Notification::fake();

        [$userA, $accountA] = $this->makeAccount(50000);
        [, $accountB] = $this->makeAccount(0);

        $payment = $this->service->create(
            fromAccount: $accountA,
            toAccount: $accountB,
            amount: 100,
            description: 'Già eseguito',
            scheduledAt: now()->subMinute(),
            createdBy: $userA,
        );

        $this->service->execute($payment);
        $this->service->cancel($payment->fresh(), $accountA);
    }

    /** execute() — invia notifiche a mittente e destinatario */
    public function test_execute_sends_notifications(): void
    {
        Notification::fake();
        [$userA, $accountA] = $this->makeAccount(50000);
        [$userB, $accountB] = $this->makeAccount(0);

        $payment = $this->service->create(
            fromAccount: $accountA,
            toAccount: $accountB,
            amount: 500,
            description: 'Test notifiche',
            scheduledAt: now()->subMinute(),
            createdBy: $userA,
        );

        $this->service->execute($payment);

        Notification::assertSentTo($userA, \App\Notifications\ScheduledPaymentExecutedNotification::class);
        Notification::assertSentTo($userB, \App\Notifications\ScheduledPaymentExecutedNotification::class);
    }
}
