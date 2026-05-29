<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\PaymentPlan;
use App\Models\PaymentPlanInstallment;
use App\Models\Transfer;
use App\Models\User;
use App\Services\PaymentPlanService;
use App\Services\TransferBookingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PaymentPlanServiceTest extends TestCase
{
    use RefreshDatabase;

    private PaymentPlanService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PaymentPlanService(new TransferBookingService());
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeCompanyWithAccount(int $balance = 0): array
    {
        $company = Company::factory()->create();
        $account = Account::factory()->create([
            'company_id'        => $company->id,
            'available_balance' => $balance,
        ]);
        $user = User::factory()->create([
            'company_id' => $company->id,
            'role'       => 'company-manager',
        ]);

        return [$company, $account, $user];
    }

    private function makePlan(
        int $fromBalance = 0,
        int $toBalance = 0,
        int $total = 3000,
        int $installments = 3,
        string $frequency = 'monthly',
    ): array {
        [, $fromAccount, $fromUser] = $this->makeCompanyWithAccount($fromBalance);
        [, $toAccount]             = $this->makeCompanyWithAccount($toBalance);

        $plan = $this->service->create(
            fromAccountId:     $fromAccount->id,
            toAccountId:       $toAccount->id,
            totalAmount:       $total,
            installmentsCount: $installments,
            frequency:         $frequency,
            firstDueDate:      Carbon::today()->addDays(1),
            initiatedBy:       $fromUser->id,
            description:       'Test plan',
        );

        return [$plan, $fromAccount, $toAccount, $fromUser];
    }

    // ── create() ─────────────────────────────────────────────────────────────

    public function test_create_generates_plan_and_correct_installment_schedule(): void
    {
        [$plan, , , $fromUser] = $this->makePlan(total: 3000, installments: 3, frequency: 'monthly');

        $this->assertSame('pending_approval', $plan->status);
        $this->assertSame(3000, $plan->total_amount);
        $this->assertSame(3, $plan->installments_count);

        $installments = $plan->installments;
        $this->assertCount(3, $installments);

        // Each installment = 1000 KY
        $installments->each(fn ($i) => $this->assertSame(1000, (int) $i->amount));

        // All installments start as pending
        $installments->each(fn ($i) => $this->assertSame('pending', $i->status));

        // Audit log written
        $this->assertSame(1, AuditLog::where('event', 'payment_plan.created')->count());
    }

    public function test_create_distributes_remainder_to_last_installment(): void
    {
        // 1001 / 3 = 333 + 333 + 335
        [$plan] = $this->makePlan(total: 1001, installments: 3);

        $amounts = $plan->installments->pluck('amount')->map(fn ($a) => (int) $a)->toArray();
        $this->assertSame(1001, array_sum($amounts));
        $this->assertSame($amounts[0], $amounts[1]); // first two equal
        $this->assertGreaterThan($amounts[0], $amounts[2]); // last absorbs remainder
    }

    public function test_create_throws_when_installments_count_below_minimum(): void
    {
        [, $fromAccount, , $fromUser] = $this->makeCompanyWithAccount();
        [, $toAccount]               = $this->makeCompanyWithAccount();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/tra 2 e 60/');

        $this->service->create(
            fromAccountId: $fromAccount->id, toAccountId: $toAccount->id,
            totalAmount: 1000, installmentsCount: 1, frequency: 'monthly',
            firstDueDate: Carbon::today()->addDay(), initiatedBy: $fromUser->id,
        );
    }

    public function test_create_throws_on_invalid_frequency(): void
    {
        [, $fromAccount, , $fromUser] = $this->makeCompanyWithAccount();
        [, $toAccount]               = $this->makeCompanyWithAccount();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/[Ff]requenza/');

        $this->service->create(
            fromAccountId: $fromAccount->id, toAccountId: $toAccount->id,
            totalAmount: 1000, installmentsCount: 2, frequency: 'daily',
            firstDueDate: Carbon::today()->addDay(), initiatedBy: $fromUser->id,
        );
    }

    public function test_create_throws_when_first_due_date_is_in_the_past(): void
    {
        [, $fromAccount, , $fromUser] = $this->makeCompanyWithAccount();
        [, $toAccount]               = $this->makeCompanyWithAccount();

        $this->expectException(\RuntimeException::class);

        $this->service->create(
            fromAccountId: $fromAccount->id, toAccountId: $toAccount->id,
            totalAmount: 1000, installmentsCount: 2, frequency: 'monthly',
            firstDueDate: Carbon::yesterday(), initiatedBy: $fromUser->id,
        );
    }

    // ── approve() ────────────────────────────────────────────────────────────

    public function test_approve_sets_plan_active(): void
    {
        [$plan, , , $fromUser] = $this->makePlan();

        $this->assertSame('pending_approval', $plan->status);

        $this->service->approve($plan, $fromUser->id);
        $plan->refresh();

        $this->assertSame('active', $plan->status);
        $this->assertSame(1, AuditLog::where('event', 'payment_plan.approved')->count());
    }

    public function test_approve_throws_when_plan_not_pending(): void
    {
        [$plan, , , $fromUser] = $this->makePlan();
        $this->service->approve($plan, $fromUser->id);
        $plan->refresh();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/attesa/');

        $this->service->approve($plan, $fromUser->id);
    }

    // ── reject() ─────────────────────────────────────────────────────────────

    public function test_reject_sets_plan_rejected_and_cancels_installments(): void
    {
        [$plan, , , $fromUser] = $this->makePlan(installments: 4);

        $this->service->reject($plan, $fromUser->id);
        $plan->refresh();

        $this->assertSame('rejected', $plan->status);

        $cancelled = PaymentPlanInstallment::where('payment_plan_id', $plan->id)
            ->where('status', 'cancelled')
            ->count();
        $this->assertSame(4, $cancelled);
        $this->assertSame(1, AuditLog::where('event', 'payment_plan.rejected')->count());
    }

    public function test_reject_throws_when_plan_already_active(): void
    {
        [$plan, , , $fromUser] = $this->makePlan();
        $this->service->approve($plan, $fromUser->id);
        $plan->refresh();

        $this->expectException(\RuntimeException::class);

        $this->service->reject($plan, $fromUser->id);
    }

    // ── cancel() ─────────────────────────────────────────────────────────────

    public function test_cancel_sets_plan_cancelled_and_cancels_pending_installments(): void
    {
        [$plan, , , $fromUser] = $this->makePlan(installments: 3);

        // Approve first so we can cancel an active plan
        $this->service->approve($plan, $fromUser->id);
        $plan->refresh();

        $this->service->cancel($plan, $fromUser->id);
        $plan->refresh();

        $this->assertSame('cancelled', $plan->status);
        $this->assertSame(3, PaymentPlanInstallment::where('payment_plan_id', $plan->id)
            ->where('status', 'cancelled')->count());
        $this->assertSame(1, AuditLog::where('event', 'payment_plan.cancelled')->count());
    }

    // ── processInstallment() ─────────────────────────────────────────────────

    public function test_process_installment_books_transfer_and_updates_balances(): void
    {
        [$plan, $fromAccount, $toAccount] = $this->makePlan(
            fromBalance: 10_000,
            toBalance: 0,
            total: 3000,
            installments: 3,
        );
        $this->service->approve($plan, User::first()->id);

        $installment = $plan->installments->first();

        $transfer = $this->service->processInstallment($installment);

        $fromAccount->refresh();
        $toAccount->refresh();
        $installment->refresh();

        // Balances updated
        $this->assertSame(10_000 - 1000, $fromAccount->available_balance);
        $this->assertSame(1000, $toAccount->available_balance);

        // Transfer created
        $this->assertSame('booked', $transfer->status);
        $this->assertSame('portal_installment', $transfer->kind);
        $this->assertSame(1000, (int) $transfer->amount);

        // Installment marked paid
        $this->assertSame('paid', $installment->status);
        $this->assertSame($transfer->id, $installment->transfer_id);

        // Ledger entries (debit + credit)
        $this->assertSame(2, LedgerEntry::where('transfer_id', $transfer->id)->count());
        $this->assertSame(1, AuditLog::where('event', 'payment_plan.installment_paid')->count());
    }

    public function test_process_all_installments_marks_plan_completed(): void
    {
        [$plan, $fromAccount] = $this->makePlan(
            fromBalance: 99_999,
            total: 3000,
            installments: 3,
        );
        $this->service->approve($plan, User::first()->id);

        foreach ($plan->installments as $installment) {
            $this->service->processInstallment($installment);
        }

        $plan->refresh();
        $this->assertSame('completed', $plan->status);
    }

    public function test_process_installment_fails_when_insufficient_balance_no_negative(): void
    {
        [$plan, $fromAccount] = $this->makePlan(
            fromBalance: 0,
            total: 3000,
            installments: 3,
        );
        $fromAccount->forceFill(['allow_negative_balance' => false])->save();
        $this->service->approve($plan, User::first()->id);

        $installment = $plan->installments->first();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/[Ss]aldo/');

        $this->service->processInstallment($installment);

        $installment->refresh();
        $this->assertSame('failed', $installment->status);
    }

    public function test_process_installment_throws_when_already_paid(): void
    {
        [$plan, $fromAccount] = $this->makePlan(fromBalance: 99_999, total: 3000, installments: 3);
        $this->service->approve($plan, User::first()->id);

        $installment = $plan->installments->first();
        $this->service->processInstallment($installment);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/pending/');

        $this->service->processInstallment($installment);
    }
}
