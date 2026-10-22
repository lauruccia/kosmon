<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\KyCard;
use App\Models\KyCardPurchase;
use App\Models\KyCardSubscription;
use App\Models\MlmCommissionBaseLedgerEntry;
use App\Models\MlmPointLedgerEntry;
use App\Models\Transfer;
use App\Models\User;
use App\Notifications\KyCardSubscriptionAdminAlert;
use App\Notifications\KyCardSubscriptionPaymentFailed;
use App\Services\KyCardSubscriptionService;
use App\Services\StripeSubscriptionGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * KYCard a rate (KY tutti con la prima rata) e a rinnovo mensile.
 *
 * Nessuna chiamata di rete: al posto del gateway Stripe c'e' un finto che
 * registra le chiamate. Le fatture sono oggetti come quelli del webhook.
 */
class KyCardSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    private FakeStripeGateway $stripe;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stripe = new FakeStripeGateway();
        $this->app->instance(StripeSubscriptionGateway::class, $this->stripe);
        Notification::fake();
    }

    // ── Helper ─────────────────────────────────────────────────────────────

    private function makeSystemAccount(): Account
    {
        $account = Account::systemAccount();
        $this->assertNotNull($account);
        $account->forceFill(['available_balance' => 0])->save();

        return $account;
    }

    private function makeAdmin(): User
    {
        $user = User::create([
            'name' => 'Admin Sub', 'email' => 'admin-sub-' . Str::random(6) . '@test.test',
            'password' => 'secret123', 'account_holder_type' => 'private', 'company_id' => null,
            'is_active' => true, 'is_super_admin' => true,
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();

        return $user;
    }

    /** @return array{0: User, 1: Account} */
    private function makeBuyer(): array
    {
        $slug = 'sub-' . Str::random(4);
        $company = Company::create([
            'name' => 'Sub Co', 'slug' => $slug, 'email' => $slug . '@test.test', 'status' => 'active',
            'kyc_status' => 'approved', 'currency_code' => 'KY', 'sector' => 'informatica', 'description' => 'Test',
        ]);
        $user = User::create([
            'company_id' => $company->id, 'account_holder_type' => 'company', 'name' => 'Sub Buyer',
            'email' => 'buyer-' . Str::random(6) . '@test.test', 'password' => 'secret123',
            'role' => 'company-manager', 'is_active' => true, 'is_super_admin' => false,
        ]);
        $user->forceFill(['email_verified_at' => now(), 'contract_signed_at' => now()])->save();
        $account = Account::create([
            'company_id' => $company->id, 'owner_user_id' => $user->id, 'owner_type' => 'company',
            'type' => 'primary', 'account_name' => 'Conto Sub', 'currency_code' => 'KY',
            'status' => 'active', 'available_balance' => 0,
        ]);

        return [$user, $account];
    }

    /** 1.200 EUR -> 1.500 KY (bonus 25%), rateale 3/6/12, 2 punti MLM. */
    private function bigCard(): KyCard
    {
        return KyCard::create([
            'name' => 'KYCard 1200', 'price_eur_cents' => 120000, 'ky_base_amount' => 120000,
            'bonus_type' => 'percentage', 'bonus_value' => 25, 'is_active' => true,
            'installments_enabled' => true, 'installment_options' => [3, 6, 12],
            'mlm_points' => 2, 'mlm_points_duration_days' => 360,
        ]);
    }

    /** 120 EUR -> 150 KY, rinnovo mensile, 2 punti MLM. */
    private function monthlyCard(): KyCard
    {
        return KyCard::create([
            'name' => 'KYCard 120', 'price_eur_cents' => 12000, 'ky_base_amount' => 12000,
            'bonus_type' => 'percentage', 'bonus_value' => 25, 'is_active' => true,
            'recurring_enabled' => true,
            'mlm_points' => 2, 'mlm_points_duration_days' => 30,
        ]);
    }

    private function attachMlmAgent(User $buyer): User
    {
        $agent = User::create([
            'name' => 'Agente Sub', 'email' => 'agente-' . Str::random(6) . '@test.test', 'password' => 'secret123',
            'account_holder_type' => 'private', 'company_id' => null, 'is_active' => true,
            'mlm_role' => 'agente', 'mlm_rank' => 'start', 'mlm_activated_at' => now(),
        ]);
        $buyer->forceFill(['mlm_role' => 'cliente', 'mlm_client_agent_id' => $agent->id])->save();

        return $agent;
    }

    private function service(): KyCardSubscriptionService
    {
        return app(KyCardSubscriptionService::class);
    }

    private function startInstallments(KyCard $card, User $user, Account $account, int $n): KyCardSubscription
    {
        [$sub] = $this->service()->startCheckout(
            $card, $account, $user, KyCardSubscription::KIND_INSTALLMENTS, $n, 'https://x.test/ok', 'https://x.test/no'
        );

        return $sub->fresh();
    }

    private function startRecurring(KyCard $card, User $user, Account $account): KyCardSubscription
    {
        [$sub] = $this->service()->startCheckout(
            $card, $account, $user, KyCardSubscription::KIND_RECURRING, null, 'https://x.test/ok', 'https://x.test/no'
        );

        return $sub->fresh();
    }

    /** Fattura pagata come la manda Stripe (forma nuova dell'API). */
    private function invoice(KyCardSubscription $sub, string $id, ?int $amount = null, array $extra = []): object
    {
        $periodStart = 1_800_000_000 + (count($this->seen[$sub->id] ?? []) * 2_592_000);
        $this->seen[$sub->id][] = $id;

        return (object) ($extra + [
            'id'          => $id,
            'status'      => 'paid',
            'currency'    => 'eur',
            'amount_paid' => $amount ?? $sub->amount_cents,
            'customer'    => 'cus_test',
            'payment_intent' => 'pi_' . $id,
            'parent'      => (object) ['subscription_details' => (object) ['subscription' => 'sub_' . $sub->uuid]],
            'lines'       => (object) ['data' => [(object) ['period' => (object) ['start' => $periodStart, 'end' => $periodStart + 2_592_000]]]],
        ]);
    }

    private array $seen = [];

    private function linkStripe(KyCardSubscription $sub): KyCardSubscription
    {
        $sub->update(['stripe_subscription_id' => 'sub_' . $sub->uuid]);

        return $sub->fresh();
    }

    // ── Opzioni di rate sulla card ─────────────────────────────────────────

    public function test_installment_options_keep_only_numbers_that_divide_price_and_ky_exactly(): void
    {
        $card = $this->bigCard();
        $card->update(['installment_options' => [3, 7, 6, 11, 12]]);

        // 7 e 11 non dividono 1200 EUR / 1500 KY.
        $this->assertSame([3, 6, 12], $card->fresh()->availableInstallmentOptions());

        $card->update(['installments_enabled' => false]);
        $this->assertSame([], $card->fresh()->availableInstallmentOptions());
    }

    public function test_start_checkout_refuses_installments_not_allowed_and_recurring_on_a_card_without_flag(): void
    {
        [$user, $account] = $this->makeBuyer();
        $card = $this->bigCard();

        try {
            $this->service()->startCheckout($card, $account, $user, KyCardSubscription::KIND_INSTALLMENTS, 5, 'a', 'b');
            $this->fail('5 rate non sono ammesse');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        try {
            $this->service()->startCheckout($card, $account, $user, KyCardSubscription::KIND_RECURRING, null, 'a', 'b');
            $this->fail('la card non accetta il rinnovo');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        $this->assertSame(0, KyCardSubscription::count());
    }

    public function test_start_checkout_builds_the_installment_amounts_from_the_card(): void
    {
        [$user, $account] = $this->makeBuyer();
        $sub = $this->startInstallments($this->bigCard(), $user, $account, 6);

        $this->assertSame(20000, $sub->amount_cents);     // 1200 / 6 = 200 EUR
        $this->assertSame(120000, $sub->card_price_cents);
        $this->assertSame(150000, $sub->ky_total);        // 1.500 KY
        $this->assertSame(6, $sub->installments_total);
        $this->assertSame('pending', $sub->status);

        $params = $this->stripe->sessions[0];
        $this->assertSame('subscription', $params['mode']);
        $this->assertSame(20000, $params['line_items'][0]['price_data']['unit_amount']);
        $this->assertSame('month', $params['line_items'][0]['price_data']['recurring']['interval']);
        $this->assertSame($sub->uuid, $params['client_reference_id']);
        $this->assertSame($sub->uuid, $params['subscription_data']['metadata']['ky_sub_uuid']);
    }

    // ── Rate: i KY arrivano tutti con la prima ─────────────────────────────

    public function test_first_installment_credits_all_ky_and_mlm_points_on_the_full_card_price(): void
    {
        $system = $this->makeSystemAccount();
        [$user, $account] = $this->makeBuyer();
        $this->attachMlmAgent($user);
        $sub = $this->linkStripe($this->startInstallments($this->bigCard(), $user, $account, 3));

        $this->service()->handleInvoicePaid($this->invoice($sub, 'in_1'));

        // Tutti i 1.500 KY subito, anche se ha pagato 400 EUR.
        $this->assertSame(150000, (int) $account->fresh()->available_balance);
        $this->assertSame(-150000, (int) $system->fresh()->available_balance);

        $purchase = KyCardPurchase::where('stripe_invoice_id', 'in_1')->firstOrFail();
        $this->assertTrue($purchase->isCompleted());
        $this->assertSame(1, (int) $purchase->installment_no);
        $this->assertSame(40000, (int) $purchase->price_eur_cents);   // incassato, per i totali di ricavo
        $this->assertSame(150000, (int) $purchase->ky_amount);

        // Punti e base commissionabile: sul prezzo INTERO della card.
        $this->assertSame(1, MlmPointLedgerEntry::where('client_user_id', $user->id)->count());
        $this->assertSame(
            120000,
            (int) MlmCommissionBaseLedgerEntry::where('client_user_id', $user->id)->value('monthly_amount_eur_cents')
        );

        $sub->refresh();
        $this->assertSame(1, $sub->installments_paid);
        $this->assertSame('active', $sub->status);
    }

    public function test_first_installment_sets_the_cancel_at_cap_on_stripe(): void
    {
        $this->makeSystemAccount();
        [$user, $account] = $this->makeBuyer();
        $sub = $this->linkStripe($this->startInstallments($this->bigCard(), $user, $account, 3));

        $invoice = $this->invoice($sub, 'in_1');
        $this->service()->handleInvoicePaid($invoice);

        $this->assertCount(1, $this->stripe->cancelAt);
        [$stripeId, $ts] = $this->stripe->cancelAt[0];
        $this->assertSame('sub_' . $sub->uuid, $stripeId);

        // Inizio periodo + 2 mesi (rata 3 di 3) + 25 giorni.
        $start = (int) $invoice->lines->data[0]->period->start;
        $expected = \Carbon\CarbonImmutable::createFromTimestamp($start)->addMonthsNoOverflow(2)->addDays(25)->getTimestamp();
        $this->assertSame($expected, $ts);
        $this->assertSame([], $this->stripe->cancelled);
    }

    public function test_next_installments_collect_money_but_give_no_ky_and_no_new_points(): void
    {
        $system = $this->makeSystemAccount();
        [$user, $account] = $this->makeBuyer();
        $this->attachMlmAgent($user);
        $sub = $this->linkStripe($this->startInstallments($this->bigCard(), $user, $account, 3));

        $this->service()->handleInvoicePaid($this->invoice($sub, 'in_1'));
        $this->service()->handleInvoicePaid($this->invoice($sub, 'in_2'));

        $second = KyCardPurchase::where('stripe_invoice_id', 'in_2')->firstOrFail();
        $this->assertTrue($second->isCompleted());
        $this->assertSame(2, (int) $second->installment_no);
        $this->assertSame(0, (int) $second->ky_amount);
        $this->assertNull($second->transfer_id);

        // Saldo invariato dopo la seconda rata, un solo accredito, punti una volta.
        $this->assertSame(150000, (int) $account->fresh()->available_balance);
        $this->assertSame(1, Transfer::where('kind', 'kycard_topup')->count());
        $this->assertSame(1, MlmPointLedgerEntry::where('client_user_id', $user->id)->count());
        $this->assertSame(1, MlmCommissionBaseLedgerEntry::where('client_user_id', $user->id)->count());

        // Il tetto cancel_at lo si mette una volta sola, con la prima rata.
        $this->assertCount(1, $this->stripe->cancelAt);
        $this->assertSame(2, $sub->fresh()->installments_paid);
    }

    public function test_last_installment_closes_the_plan_and_stops_stripe(): void
    {
        $this->makeSystemAccount();
        [$user, $account] = $this->makeBuyer();
        $sub = $this->linkStripe($this->startInstallments($this->bigCard(), $user, $account, 3));

        foreach (['in_1', 'in_2', 'in_3'] as $id) {
            $this->service()->handleInvoicePaid($this->invoice($sub, $id));
        }

        $sub->refresh();
        $this->assertSame('completed', $sub->status);
        $this->assertSame(3, $sub->installments_paid);
        $this->assertNotNull($sub->ended_at);
        $this->assertSame(['sub_' . $sub->uuid], $this->stripe->cancelled);
        $this->assertSame(150000, (int) $account->fresh()->available_balance);
    }

    public function test_an_extra_charge_beyond_the_installments_is_not_credited_and_alerts_the_admin(): void
    {
        $this->makeSystemAccount();
        $admin = $this->makeAdmin();
        [$user, $account] = $this->makeBuyer();
        $sub = $this->linkStripe($this->startInstallments($this->bigCard(), $user, $account, 3));

        foreach (['in_1', 'in_2', 'in_3', 'in_4'] as $id) {
            $this->service()->handleInvoicePaid($this->invoice($sub, $id));
        }

        $this->assertNull(KyCardPurchase::where('stripe_invoice_id', 'in_4')->first());
        $this->assertSame(3, $sub->fresh()->installments_paid);
        Notification::assertSentTo($admin, KyCardSubscriptionAdminAlert::class);
    }

    // ── Idempotenza e difese ───────────────────────────────────────────────

    public function test_the_same_invoice_delivered_twice_credits_once(): void
    {
        $this->makeSystemAccount();
        [$user, $account] = $this->makeBuyer();
        $this->attachMlmAgent($user);
        $sub = $this->linkStripe($this->startInstallments($this->bigCard(), $user, $account, 3));

        $invoice = $this->invoice($sub, 'in_1');
        $this->service()->handleInvoicePaid($invoice);
        $this->service()->handleInvoicePaid($invoice);

        $this->assertSame(1, KyCardPurchase::where('stripe_invoice_id', 'in_1')->count());
        $this->assertSame(1, Transfer::where('kind', 'kycard_topup')->count());
        $this->assertSame(150000, (int) $account->fresh()->available_balance);
        $this->assertSame(1, MlmPointLedgerEntry::where('client_user_id', $user->id)->count());
        $this->assertSame(1, $sub->fresh()->installments_paid);
    }

    public function test_a_redelivered_invoice_retries_a_credit_that_failed_halfway(): void
    {
        $this->makeSystemAccount();
        [$user, $account] = $this->makeBuyer();
        $sub = $this->linkStripe($this->startInstallments($this->bigCard(), $user, $account, 3));

        $invoice = $this->invoice($sub, 'in_1');
        $this->service()->handleInvoicePaid($invoice);

        // Simulo un accredito rimasto a meta': riga fallita, nessun movimento.
        $purchase = KyCardPurchase::where('stripe_invoice_id', 'in_1')->firstOrFail();
        Transfer::where('id', $purchase->transfer_id)->delete();
        $purchase->forceFill(['status' => 'failed', 'transfer_id' => null, 'completed_at' => null])->save();
        \Illuminate\Support\Facades\DB::table('accounts')->where('id', $account->id)->update(['available_balance' => 0]);

        $this->service()->handleInvoicePaid($invoice);

        $this->assertTrue($purchase->fresh()->isCompleted());
        $this->assertSame(150000, (int) $account->fresh()->available_balance);
        $this->assertSame(1, $sub->fresh()->installments_paid); // non si conta due volte
    }

    public function test_an_invoice_with_the_wrong_amount_creates_nothing_and_alerts_the_admin(): void
    {
        $this->makeSystemAccount();
        $admin = $this->makeAdmin();
        [$user, $account] = $this->makeBuyer();
        $sub = $this->linkStripe($this->startInstallments($this->bigCard(), $user, $account, 3));

        $this->service()->handleInvoicePaid($this->invoice($sub, 'in_1', 100)); // 1 EUR invece di 400

        $this->assertSame(0, KyCardPurchase::count());
        $this->assertSame(0, (int) $account->fresh()->available_balance);
        $this->assertSame(0, $sub->fresh()->installments_paid);
        Notification::assertSentTo($admin, KyCardSubscriptionAdminAlert::class);
    }

    public function test_an_unpaid_or_non_euro_invoice_creates_nothing(): void
    {
        $this->makeSystemAccount();
        [$user, $account] = $this->makeBuyer();
        $sub = $this->linkStripe($this->startInstallments($this->bigCard(), $user, $account, 3));

        $this->service()->handleInvoicePaid($this->invoice($sub, 'in_1', null, ['status' => 'open']));
        $this->service()->handleInvoicePaid($this->invoice($sub, 'in_2', null, ['currency' => 'usd']));

        $this->assertSame(0, KyCardPurchase::count());
        $this->assertSame(0, (int) $account->fresh()->available_balance);
    }

    public function test_an_invoice_of_a_subscription_that_is_not_ours_is_ignored(): void
    {
        $this->makeSystemAccount();
        $invoice = (object) [
            'id' => 'in_x', 'status' => 'paid', 'currency' => 'eur', 'amount_paid' => 500,
            'parent' => (object) ['subscription_details' => (object) ['subscription' => 'sub_altro']],
        ];

        $this->service()->handleInvoicePaid($invoice);

        $this->assertSame(0, KyCardPurchase::count());
    }

    public function test_the_first_invoice_can_arrive_before_checkout_completed(): void
    {
        $this->makeSystemAccount();
        [$user, $account] = $this->makeBuyer();
        $sub = $this->startInstallments($this->bigCard(), $user, $account, 3); // niente id Stripe sulla riga

        $this->stripe->subscriptionMetadata['sub_nuovo'] = $sub->uuid;
        $invoice = $this->invoice($sub, 'in_1');
        $invoice->parent->subscription_details->subscription = 'sub_nuovo';

        $this->service()->handleInvoicePaid($invoice);

        $this->assertSame(150000, (int) $account->fresh()->available_balance);
        $this->assertSame('sub_nuovo', $sub->fresh()->stripe_subscription_id);
    }

    public function test_checkout_completed_links_the_stripe_subscription_without_crediting(): void
    {
        $this->makeSystemAccount();
        [$user, $account] = $this->makeBuyer();
        $sub = $this->startInstallments($this->bigCard(), $user, $account, 3);

        $this->service()->handleCheckoutCompleted((object) [
            'id' => $sub->stripe_checkout_session_id, 'mode' => 'subscription',
            'subscription' => 'sub_abc', 'customer' => 'cus_abc',
        ]);

        $sub->refresh();
        $this->assertSame('sub_abc', $sub->stripe_subscription_id);
        $this->assertSame('cus_abc', $sub->stripe_customer_id);
        $this->assertSame(0, (int) $account->fresh()->available_balance);
        $this->assertSame(0, KyCardPurchase::count());
    }

    // ── Rinnovo mensile ────────────────────────────────────────────────────

    public function test_every_monthly_renewal_credits_the_card_ky_and_points(): void
    {
        $system = $this->makeSystemAccount();
        [$user, $account] = $this->makeBuyer();
        $this->attachMlmAgent($user);
        $sub = $this->linkStripe($this->startRecurring($this->monthlyCard(), $user, $account));

        $this->service()->handleInvoicePaid($this->invoice($sub, 'in_1'));
        $this->service()->handleInvoicePaid($this->invoice($sub, 'in_2'));

        $this->assertSame(30000, (int) $account->fresh()->available_balance);   // 2 x 150 KY
        $this->assertSame(2, Transfer::where('kind', 'kycard_topup')->count());
        $this->assertSame(2, MlmPointLedgerEntry::where('client_user_id', $user->id)->count());

        $sub->refresh();
        $this->assertSame('active', $sub->status);
        $this->assertSame(2, $sub->installments_paid);
        $this->assertSame([], $this->stripe->cancelAt);   // il rinnovo non ha tetto
        $this->assertSame([], $this->stripe->cancelled);
    }

    public function test_customer_can_cancel_a_renewal_but_not_installments(): void
    {
        [$user, $account] = $this->makeBuyer();
        $monthly = $this->linkStripe($this->startRecurring($this->monthlyCard(), $user, $account));

        $this->service()->cancelByCustomer($monthly);
        $this->assertSame('cancelling', $monthly->fresh()->status);
        $this->assertSame(['sub_' . $monthly->uuid], $this->stripe->periodEndCancels);

        $rate = $this->linkStripe($this->startInstallments($this->bigCard(), $user, $account, 3));
        try {
            $this->service()->cancelByCustomer($rate);
            $this->fail('le rate non si disdicono dal portale');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->assertSame('pending', $rate->fresh()->status);
    }

    // ── Rata non pagata ────────────────────────────────────────────────────

    public function test_first_failed_attempt_marks_past_due_and_tells_the_customer(): void
    {
        $this->makeSystemAccount();
        [$user, $account] = $this->makeBuyer();
        $sub = $this->linkStripe($this->startInstallments($this->bigCard(), $user, $account, 3));
        $this->service()->handleInvoicePaid($this->invoice($sub, 'in_1'));

        $this->service()->handleInvoicePaymentFailed($this->invoice($sub, 'in_2', null, [
            'status' => 'open', 'attempt_count' => 1, 'next_payment_attempt' => 1_900_000_000,
        ]));

        $sub->refresh();
        $this->assertSame('past_due', $sub->status);
        Notification::assertSentTo($user, KyCardSubscriptionPaymentFailed::class);
        $this->assertSame([], $this->stripe->cancelled);
    }

    public function test_later_failed_attempts_do_not_spam_the_customer(): void
    {
        $this->makeSystemAccount();
        [$user, $account] = $this->makeBuyer();
        $sub = $this->linkStripe($this->startInstallments($this->bigCard(), $user, $account, 3));
        $this->service()->handleInvoicePaid($this->invoice($sub, 'in_1'));

        $this->service()->handleInvoicePaymentFailed($this->invoice($sub, 'in_2', null, [
            'status' => 'open', 'attempt_count' => 3, 'next_payment_attempt' => 1_900_000_000,
        ]));

        Notification::assertNotSentTo($user, KyCardSubscriptionPaymentFailed::class);
    }

    public function test_when_stripe_gives_up_the_plan_is_defaulted_the_admin_is_alerted_and_the_account_stays_open(): void
    {
        $this->makeSystemAccount();
        $admin = $this->makeAdmin();
        [$user, $account] = $this->makeBuyer();
        $sub = $this->linkStripe($this->startInstallments($this->bigCard(), $user, $account, 3));
        $this->service()->handleInvoicePaid($this->invoice($sub, 'in_1'));

        $this->service()->handleInvoicePaymentFailed($this->invoice($sub, 'in_2', null, [
            'status' => 'open', 'attempt_count' => 4, 'next_payment_attempt' => null,
        ]));

        $sub->refresh();
        $this->assertSame('defaulted', $sub->status);
        $this->assertNotNull($sub->ended_at);
        $this->assertSame(['sub_' . $sub->uuid], $this->stripe->cancelled);
        Notification::assertSentTo($admin, KyCardSubscriptionAdminAlert::class);

        // Il conto NON si blocca e i KY gia' dati restano.
        $this->assertSame('active', $account->fresh()->status);
        $this->assertSame(150000, (int) $account->fresh()->available_balance);
    }

    public function test_subscription_deleted_with_installments_left_means_defaulted(): void
    {
        $this->makeSystemAccount();
        $admin = $this->makeAdmin();
        [$user, $account] = $this->makeBuyer();
        $sub = $this->linkStripe($this->startInstallments($this->bigCard(), $user, $account, 3));
        $this->service()->handleInvoicePaid($this->invoice($sub, 'in_1'));

        $this->service()->handleSubscriptionDeleted((object) ['id' => 'sub_' . $sub->uuid]);

        $this->assertSame('defaulted', $sub->fresh()->status);
        Notification::assertSentTo($admin, KyCardSubscriptionAdminAlert::class);
    }

    public function test_subscription_deleted_after_the_last_installment_is_not_a_default(): void
    {
        $this->makeSystemAccount();
        $admin = $this->makeAdmin();
        [$user, $account] = $this->makeBuyer();
        $sub = $this->linkStripe($this->startInstallments($this->bigCard(), $user, $account, 3));
        foreach (['in_1', 'in_2', 'in_3'] as $id) {
            $this->service()->handleInvoicePaid($this->invoice($sub, $id));
        }
        Notification::fake(); // azzera: conta solo cio' che succede dopo

        $this->service()->handleSubscriptionDeleted((object) ['id' => 'sub_' . $sub->uuid]);

        $this->assertSame('completed', $sub->fresh()->status);
        Notification::assertNothingSent();
    }

    public function test_subscription_deleted_on_a_renewal_closes_it_as_cancelled(): void
    {
        [$user, $account] = $this->makeBuyer();
        $sub = $this->linkStripe($this->startRecurring($this->monthlyCard(), $user, $account));

        $this->service()->handleSubscriptionDeleted((object) ['id' => 'sub_' . $sub->uuid]);

        $this->assertSame('cancelled', $sub->fresh()->status);
    }

    // ── Contestazioni ──────────────────────────────────────────────────────

    public function test_a_dispute_alerts_the_admin_and_touches_no_balance(): void
    {
        $this->makeSystemAccount();
        $admin = $this->makeAdmin();
        [$user, $account] = $this->makeBuyer();
        $sub = $this->linkStripe($this->startInstallments($this->bigCard(), $user, $account, 3));
        $this->service()->handleInvoicePaid($this->invoice($sub, 'in_1'));

        $this->service()->handleDispute((object) [
            'id' => 'dp_1', 'amount' => 40000, 'currency' => 'eur', 'reason' => 'fraudulent',
            'charge' => 'ch_1', 'payment_intent' => 'pi_in_1',
        ]);

        Notification::assertSentTo($admin, KyCardSubscriptionAdminAlert::class, function ($n) use ($admin) {
            $mail = $n->toArray($admin);

            return str_contains($mail['message'], 'dp_1') && str_contains($mail['message'], 'I KY accreditati NON sono stati toccati');
        });
        $this->assertSame(150000, (int) $account->fresh()->available_balance);
    }

    // ── Webhook, dal vivo ──────────────────────────────────────────────────

    private function signedPost(array $event)
    {
        config(['services.stripe.secret' => 'sk_test_x', 'services.stripe.webhook_secret' => 'whsec_test']);
        $payload = json_encode($event);
        $t = time();
        $sig = hash_hmac('sha256', $t . '.' . $payload, 'whsec_test');

        return $this->call('POST', '/stripe/webhook', [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => "t={$t},v1={$sig}",
            'CONTENT_TYPE'          => 'application/json',
        ], $payload);
    }

    public function test_the_webhook_endpoint_routes_invoice_paid_to_the_service(): void
    {
        $this->makeSystemAccount();
        [$user, $account] = $this->makeBuyer();
        $sub = $this->linkStripe($this->startInstallments($this->bigCard(), $user, $account, 3));

        $this->signedPost([
            'id' => 'evt_1', 'object' => 'event', 'type' => 'invoice.paid',
            'data' => ['object' => [
                'id' => 'in_w1', 'object' => 'invoice', 'status' => 'paid', 'currency' => 'eur',
                'amount_paid' => 40000, 'customer' => 'cus_w',
                'parent' => ['subscription_details' => ['subscription' => 'sub_' . $sub->uuid]],
                'lines' => ['object' => 'list', 'data' => [['period' => ['start' => 1800000000, 'end' => 1802592000]]]],
            ]],
        ])->assertOk();

        $this->assertSame(150000, (int) $account->fresh()->available_balance);
        $this->assertSame(1, KyCardPurchase::where('stripe_invoice_id', 'in_w1')->count());
    }

    public function test_the_webhook_rejects_a_bad_signature(): void
    {
        config(['services.stripe.secret' => 'sk_test_x', 'services.stripe.webhook_secret' => 'whsec_test']);

        $this->call('POST', '/stripe/webhook', [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => 't=1,v1=bad', 'CONTENT_TYPE' => 'application/json',
        ], json_encode(['type' => 'invoice.paid']))->assertStatus(400);
    }

    public function test_checkout_completed_in_subscription_mode_does_not_go_through_the_one_off_flow(): void
    {
        $this->makeSystemAccount();
        [$user, $account] = $this->makeBuyer();
        $sub = $this->startInstallments($this->bigCard(), $user, $account, 3);

        $this->signedPost([
            'id' => 'evt_2', 'object' => 'event', 'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'id' => $sub->stripe_checkout_session_id, 'object' => 'checkout.session', 'mode' => 'subscription',
                'payment_status' => 'paid', 'subscription' => 'sub_zzz', 'customer' => 'cus_zzz',
            ]],
        ])->assertOk();

        $this->assertSame('sub_zzz', $sub->fresh()->stripe_subscription_id);
        $this->assertSame(0, (int) $account->fresh()->available_balance);
    }

    // ── Admin: il «Riprova» sulle righe di abbonamento ─────────────────────

    public function test_admin_retry_recredits_a_failed_subscription_purchase_without_asking_checkout_sessions(): void
    {
        $this->makeSystemAccount();
        $admin = $this->makeAdmin();
        [$user, $account] = $this->makeBuyer();
        $sub = $this->linkStripe($this->startInstallments($this->bigCard(), $user, $account, 3));

        $purchase = KyCardPurchase::create([
            'ky_card_id' => $sub->ky_card_id, 'account_id' => $account->id, 'user_id' => $user->id,
            'price_eur_cents' => 40000, 'ky_amount' => 150000, 'status' => 'failed', 'payment_method' => 'stripe',
            'ky_card_subscription_id' => $sub->id, 'installment_no' => 1, 'stripe_invoice_id' => 'in_retry',
        ]);

        $this->actingAs($admin)
            ->post(route('admin.ky-cards.retry-credit', $purchase))
            ->assertRedirect(route('admin.ky-cards.orders'))
            ->assertSessionHas('success');

        $this->assertTrue($purchase->fresh()->isCompleted());
        $this->assertSame(150000, (int) $account->fresh()->available_balance);
    }

    // ── Fase 2: pagina d'acquisto e avvio checkout ─────────────────────────

    private function stripeConfigured(): void
    {
        config(['services.stripe.secret' => 'sk_test_x', 'services.stripe.key' => 'pk_test_x']);
    }

    public function test_checkout_page_offers_installments_and_recurring_only_where_the_flags_say_so(): void
    {
        $this->stripeConfigured();
        [$buyer] = $this->makeBuyer();

        // Come in produzione: le card hanno anche lo Stripe Price ID (pagamento una tantum).
        $big = $this->bigCard();
        $big->update(['stripe_price_id' => 'price_big']);
        $monthly = $this->monthlyCard();
        $monthly->update(['stripe_price_id' => 'price_monthly']);
        $plain = KyCard::create([
            'name' => 'KYCard Semplice', 'price_eur_cents' => 5000, 'ky_base_amount' => 5000,
            'bonus_type' => 'fixed', 'bonus_value' => 0, 'is_active' => true,
        ]);

        $this->actingAs($buyer)->get(route('portal.ky-cards.checkout', $big))
            ->assertOk()
            ->assertSee('data-mode="installments"', false)
            ->assertSee('data-n="3" data-amount="400,00"', false)
            ->assertSee('data-n="12" data-amount="100,00"', false)
            ->assertDontSee('data-mode="recurring"', false);

        $this->actingAs($buyer)->get(route('portal.ky-cards.checkout', $monthly))
            ->assertOk()
            ->assertSee('data-mode="recurring"', false)
            ->assertDontSee('data-mode="installments"', false);

        $this->actingAs($buyer)->get(route('portal.ky-cards.checkout', $plain))
            ->assertOk()
            ->assertDontSee('data-mode="installments"', false)
            ->assertDontSee('data-mode="recurring"', false);
    }

    public function test_checkout_page_hides_installments_when_stripe_is_not_configured(): void
    {
        config(['services.stripe.secret' => null, 'services.stripe.key' => null]);
        [$buyer] = $this->makeBuyer();

        $this->actingAs($buyer)->get(route('portal.ky-cards.checkout', $this->bigCard()))
            ->assertOk()
            ->assertDontSee('data-mode="installments"', false);
    }

    public function test_buying_in_installments_creates_the_plan_and_sends_the_customer_to_stripe(): void
    {
        $this->stripeConfigured();
        [$buyer, $account] = $this->makeBuyer();
        $card = $this->bigCard();

        $response = $this->actingAs($buyer)->post(route('portal.ky-cards.subscription-checkout', $card), [
            'kind' => 'installments', 'installments' => 6,
        ]);

        $response->assertRedirect('https://checkout.stripe.test/pay');

        $sub = KyCardSubscription::firstOrFail();
        $this->assertSame(6, $sub->installments_total);
        $this->assertSame(20000, $sub->amount_cents);
        $this->assertSame($account->id, $sub->account_id);
        $this->assertSame(KyCardSubscription::PENDING, $sub->status);

        // L'uuid della riga finisce nella pagina di ritorno, non il segnaposto.
        $params = $this->stripe->sessions[0];
        $this->assertStringContainsString($sub->uuid, $params['success_url']);
        $this->assertStringNotContainsString('__UUID__', $params['success_url']);
    }

    public function test_buying_recurring_creates_a_monthly_plan(): void
    {
        $this->stripeConfigured();
        [$buyer] = $this->makeBuyer();

        $this->actingAs($buyer)->post(route('portal.ky-cards.subscription-checkout', $this->monthlyCard()), [
            'kind' => 'recurring',
        ])->assertRedirect('https://checkout.stripe.test/pay');

        $sub = KyCardSubscription::firstOrFail();
        $this->assertSame(KyCardSubscription::KIND_RECURRING, $sub->kind);
        $this->assertSame(12000, $sub->amount_cents);
    }

    public function test_installments_not_allowed_on_the_card_are_refused_without_touching_stripe(): void
    {
        $this->stripeConfigured();
        [$buyer] = $this->makeBuyer();
        $card = $this->bigCard();

        $this->actingAs($buyer)->post(route('portal.ky-cards.subscription-checkout', $card), [
            'kind' => 'installments', 'installments' => 5,
        ])->assertStatus(422);

        $this->actingAs($buyer)->post(route('portal.ky-cards.subscription-checkout', $card), [
            'kind' => 'recurring',
        ])->assertStatus(422);

        $this->assertSame(0, KyCardSubscription::count());
        $this->assertCount(0, $this->stripe->sessions);
    }

    public function test_subscription_checkout_requires_login_and_a_valid_kind(): void
    {
        $this->stripeConfigured();
        $card = $this->bigCard();

        $this->post(route('portal.ky-cards.subscription-checkout', $card), ['kind' => 'installments', 'installments' => 3])
            ->assertRedirect(route('login'));

        [$buyer] = $this->makeBuyer();
        $this->actingAs($buyer)->post(route('portal.ky-cards.subscription-checkout', $card), ['kind' => 'boh'])
            ->assertSessionHasErrors('kind');
    }

    public function test_a_stripe_failure_sends_the_customer_back_to_the_checkout_with_an_error(): void
    {
        $this->stripeConfigured();
        [$buyer] = $this->makeBuyer();
        $card = $this->bigCard();

        $this->app->instance(StripeSubscriptionGateway::class, new class extends FakeStripeGateway {
            public function createCheckoutSession(array $params): object
            {
                throw new \RuntimeException('Stripe giu');
            }
        });

        $this->actingAs($buyer)->post(route('portal.ky-cards.subscription-checkout', $card), [
            'kind' => 'installments', 'installments' => 3,
        ])->assertRedirect(route('portal.ky-cards.checkout', $card))
          ->assertSessionHas('error');

        $this->assertSame(KyCardSubscription::CANCELLED, KyCardSubscription::firstOrFail()->status);
    }

    public function test_ricarica_list_advertises_installments_and_monthly_renewal_only_where_enabled(): void
    {
        $this->stripeConfigured();
        [$buyer] = $this->makeBuyer();
        $this->bigCard();
        $this->monthlyCard();
        KyCard::create([
            'name' => 'KYCard Semplice', 'price_eur_cents' => 5000, 'ky_base_amount' => 5000,
            'bonus_type' => 'fixed', 'bonus_value' => 0, 'is_active' => true,
        ]);

        $html = $this->actingAs($buyer)->get(route('portal.ky-cards.index'))
            ->assertOk()
            ->assertSee('Anche a rate', false)
            ->assertSee('100,00', false)
            ->assertSee('Anche <strong>ogni mese</strong>', false)
            ->getContent();

        // una sola card a rate e una sola a rinnovo: la card semplice non ne porta
        $this->assertSame(1, substr_count($html, 'Anche a rate'));
        $this->assertSame(1, substr_count($html, 'Anche <strong>ogni mese</strong>'));
    }

    public function test_ricarica_list_does_not_advertise_plans_without_stripe(): void
    {
        config(['services.stripe.secret' => null, 'services.stripe.key' => null]);
        [$buyer] = $this->makeBuyer();
        $this->bigCard();
        $this->monthlyCard();

        $this->actingAs($buyer)->get(route('portal.ky-cards.index'))
            ->assertOk()
            ->assertDontSee('Anche a rate', false)
            ->assertDontSee('Anche <strong>ogni mese</strong>', false);
    }

    public function test_with_test_keys_and_a_clock_id_the_customer_is_created_inside_the_test_clock(): void
    {
        config(['services.stripe.secret' => 'sk_test_x', 'services.stripe.key' => 'pk_test_x', 'services.stripe.test_clock' => 'clock_123']);
        [$buyer, $account] = $this->makeBuyer();

        $this->startInstallments($this->bigCard(), $buyer, $account, 3);

        $this->assertSame([[$buyer->email, 'clock_123']], $this->stripe->clockCustomers);
        $params = $this->stripe->sessions[0];
        $this->assertSame('cus_clock_1', $params['customer']);
        $this->assertArrayNotHasKey('customer_email', $params);
    }

    public function test_the_test_clock_is_never_used_with_live_keys(): void
    {
        config(['services.stripe.secret' => 'sk_live_x', 'services.stripe.key' => 'pk_live_x', 'services.stripe.test_clock' => 'clock_123']);
        [$buyer, $account] = $this->makeBuyer();

        $this->startInstallments($this->bigCard(), $buyer, $account, 3);

        $this->assertSame([], $this->stripe->clockCustomers);
        $this->assertSame($buyer->email, $this->stripe->sessions[0]['customer_email']);
    }

    public function test_fast_cycle_applies_only_to_listed_emails_and_only_when_configured(): void
    {
        [$buyer, $account] = $this->makeBuyer();
        [$tester, $testerAccount] = $this->makeBuyer();
        $card = $this->bigCard();
        config(['services.stripe.fast_cycle_days' => 1, 'services.stripe.fast_cycle_emails' => '  ' . strtoupper($tester->email) . ' , altro@x.it']);

        // cliente non in elenco: sempre al mese
        $this->startInstallments($card, $buyer, $account, 3);
        $this->assertSame(['interval' => 'month'], $this->stripe->sessions[0]['line_items'][0]['price_data']['recurring']);

        // utente di prova in elenco: ogni giorno
        $this->startInstallments($card, $tester, $testerAccount, 3);
        $this->assertSame(['interval' => 'day', 'interval_count' => 1], $this->stripe->sessions[1]['line_items'][0]['price_data']['recurring']);

        // senza giorni: normale anche per lui
        config(['services.stripe.fast_cycle_days' => null]);
        $this->startInstallments($card, $tester, $testerAccount, 3);
        $this->assertSame(['interval' => 'month'], $this->stripe->sessions[2]['line_items'][0]['price_data']['recurring']);

        // elenco vuoto: normale per tutti
        config(['services.stripe.fast_cycle_days' => 1, 'services.stripe.fast_cycle_emails' => '']);
        $this->startInstallments($card, $tester, $testerAccount, 3);
        $this->assertSame(['interval' => 'month'], $this->stripe->sessions[3]['line_items'][0]['price_data']['recurring']);
    }

    public function test_the_installment_cap_follows_a_fast_cycle(): void
    {
        $this->makeSystemAccount();
        [$user, $account] = $this->makeBuyer();
        $sub = $this->linkStripe($this->startInstallments($this->bigCard(), $user, $account, 3));

        $start = 1_800_000_000;
        $invoice = $this->invoice($sub, 'in_fast', null, [
            'lines' => (object) ['data' => [(object) ['period' => (object) ['start' => $start, 'end' => $start + 86400]]]],
        ]);
        $this->service()->handleInvoicePaid($invoice);

        // 3 rate da un giorno: ferma a inizio + 2 giorni + mezza giornata.
        [, $ts] = $this->stripe->cancelAt[0];
        $this->assertSame($start + 2 * 86400 + 43200, $ts);
    }

    public function test_result_page_shows_the_plan_only_to_its_owner(): void
    {
        [$buyer, $account] = $this->makeBuyer();
        $sub = $this->startInstallments($this->bigCard(), $buyer, $account, 3);

        $this->actingAs($buyer)->get(route('portal.ky-subscriptions.esito', $sub->uuid))
            ->assertOk()
            ->assertSee('Pagamento in verifica', false);

        $sub->forceFill(['status' => KyCardSubscription::ACTIVE, 'installments_paid' => 1])->save();
        $this->actingAs($buyer)->get(route('portal.ky-subscriptions.esito', $sub->uuid))
            ->assertOk()
            ->assertSee('Pagamento a rate attivato', false)
            ->assertSee('1 di 3 pagate', false);

        [$other] = $this->makeBuyer();
        $this->actingAs($other)->get(route('portal.ky-subscriptions.esito', $sub->uuid))
            ->assertNotFound();
    }
}

/** Gateway Stripe finto: registra le chiamate, non fa rete. */
class FakeStripeGateway extends StripeSubscriptionGateway
{
    public array $sessions = [];
    public array $cancelAt = [];
    public array $cancelled = [];
    public array $periodEndCancels = [];
    /** @var array<string,string> id abbonamento Stripe => uuid nostro */
    public array $subscriptionMetadata = [];

    public array $clockCustomers = [];

    public function createTestClockCustomer(string $email, string $clockId): object
    {
        $this->clockCustomers[] = [$email, $clockId];

        return (object) ['id' => 'cus_clock_' . count($this->clockCustomers)];
    }

    public function createCheckoutSession(array $params): object
    {
        $this->sessions[] = $params;

        return (object) ['id' => 'cs_test_' . count($this->sessions), 'url' => 'https://checkout.stripe.test/pay'];
    }

    public function retrieveSubscription(string $id): object
    {
        $uuid = $this->subscriptionMetadata[$id] ?? null;

        return (object) ['id' => $id, 'metadata' => (object) ($uuid ? ['ky_sub_uuid' => $uuid] : [])];
    }

    public function setCancelAt(string $subscriptionId, int $timestamp): object
    {
        $this->cancelAt[] = [$subscriptionId, $timestamp];

        return (object) [];
    }

    public function cancelAtPeriodEnd(string $subscriptionId): object
    {
        $this->periodEndCancels[] = $subscriptionId;

        return (object) [];
    }

    public function cancelNow(string $subscriptionId): object
    {
        $this->cancelled[] = $subscriptionId;

        return (object) [];
    }
}
