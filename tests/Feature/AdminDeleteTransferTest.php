<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\KyCard;
use App\Models\KyCardPurchase;
use App\Models\LedgerEntry;
use App\Models\NettingProposal;
use App\Models\PaymentPlan;
use App\Models\PaymentPlanInstallment;
use App\Models\PaymentRequest;
use App\Models\SubAccountLimitRequest;
use App\Models\Transfer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Verifica la cancellazione FISICA dei movimenti dal backoffice admin,
 * con particolare attenzione all'invariante del circuito chiuso: SUM(saldi) = 0.
 */
class AdminDeleteTransferTest extends TestCase
{
    use RefreshDatabase;

    public function test_superadmin_can_delete_transfer_and_circuit_stays_zero(): void
    {
        $this->seed();

        $admin    = User::where('email', 'superadmin@kmoney.test')->firstOrFail();
        $transfer = Transfer::query()->where('kind', 'trade_payment')->latest('id')->firstOrFail();

        $totalBefore = (int) Account::query()->sum('available_balance');

        $response = $this->actingAs($admin)
            ->post('/admin/transfers/' . $transfer->id . '/delete');

        $response->assertRedirect();

        // Il movimento e le sue partite sono spariti
        $this->assertDatabaseMissing('transfers', ['id' => $transfer->id]);
        $this->assertSame(0, LedgerEntry::where('transfer_id', $transfer->id)->count());

        // Invariante: la somma globale dei saldi resta invariata e pari a 0 (circuito chiuso).
        // Non verifichiamo i delta per-conto perché un eventuale fee/cashback collegato
        // viene eliminato a cascata, modificando anche altri conti — ma sempre a saldo zero.
        $this->assertSame($totalBefore, (int) Account::query()->sum('available_balance'));
        $this->assertSame(0, (int) Account::query()->sum('available_balance'));
    }

    public function test_deleting_transfer_cascades_to_linked_fee(): void
    {
        $this->seed();

        $admin   = User::where('email', 'superadmin@kmoney.test')->firstOrFail();
        $payment = Transfer::query()->where('kind', 'trade_payment')->latest('id')->firstOrFail();

        // Crea una commissione collegata (come bookFee) con relativa partita doppia
        $system = Account::systemAccount() ?? Account::query()->where('id', '!=', $payment->from_account_id)->firstOrFail();
        $payer  = Account::findOrFail($payment->from_account_id);
        $fee    = 50;

        $payer->forceFill(['available_balance' => $payer->available_balance - $fee])->save();
        $system->forceFill(['available_balance' => $system->available_balance + $fee])->save();

        $feeTransfer = Transfer::create([
            'from_account_id'     => $payer->id,
            'to_account_id'       => $system->id,
            'amount'              => $fee,
            'currency_code'       => $payer->currency_code ?? 'KY',
            'kind'                => 'portal_fee',
            'status'              => 'booked',
            'description'         => 'Commissione test',
            'idempotency_key'     => 'fee_' . $payment->uuid,
            'booked_at'           => now(),
            'related_transfer_id' => $payment->id,
        ]);
        LedgerEntry::create(['transfer_id' => $feeTransfer->id, 'account_id' => $payer->id,  'direction' => 'debit',  'amount' => $fee, 'balance_after' => $payer->available_balance,  'posted_at' => now()]);
        LedgerEntry::create(['transfer_id' => $feeTransfer->id, 'account_id' => $system->id, 'direction' => 'credit', 'amount' => $fee, 'balance_after' => $system->available_balance, 'posted_at' => now()]);

        $totalBefore = (int) Account::query()->sum('available_balance');

        $this->actingAs($admin)
            ->post('/admin/transfers/' . $payment->id . '/delete')
            ->assertRedirect();

        // Sia il pagamento sia la commissione collegata sono stati eliminati
        $this->assertDatabaseMissing('transfers', ['id' => $payment->id]);
        $this->assertDatabaseMissing('transfers', ['id' => $feeTransfer->id]);

        // Circuito ancora a 0 e somma invariata
        $this->assertSame($totalBefore, (int) Account::query()->sum('available_balance'));
        $this->assertSame(0, (int) Account::query()->sum('available_balance'));
    }

    public function test_bulk_delete_removes_multiple_transfers(): void
    {
        $this->seed();

        $admin     = User::where('email', 'superadmin@kmoney.test')->firstOrFail();
        $transfers = Transfer::query()->where('kind', 'trade_payment')->latest('id')->take(2)->get();
        $this->assertGreaterThanOrEqual(1, $transfers->count());
        $ids = $transfers->pluck('id')->all();

        $this->actingAs($admin)
            ->post('/admin/transfers/bulk-delete', ['transfer_ids' => $ids])
            ->assertRedirect();

        foreach ($ids as $id) {
            $this->assertDatabaseMissing('transfers', ['id' => $id]);
        }

        $this->assertSame(0, (int) Account::query()->sum('available_balance'));
    }

    public function test_cannot_delete_transfer_that_settles_an_accepted_netting(): void
    {
        $this->seed();

        $admin = User::where('email', 'superadmin@kmoney.test')->firstOrFail();
        $accounts = Account::query()->where('is_system_account', false)->take(2)->get();
        $this->assertSame(2, $accounts->count());
        [$proposer, $counterparty] = $accounts;

        // Movimento netto generato dall'accettazione di una compensazione: sposta KY
        // da counterparty a proposer per pareggiare i crediti incrociati.
        $netTransfer = Transfer::create([
            'from_account_id' => $counterparty->id,
            'to_account_id'   => $proposer->id,
            'amount'          => 100,
            'currency_code'   => 'KY',
            'kind'            => 'portal_netting',
            'status'          => 'booked',
            'description'     => 'Netting test',
            'idempotency_key' => (string) Str::uuid(),
            'booked_at'       => now(),
        ]);
        LedgerEntry::create(['transfer_id' => $netTransfer->id, 'account_id' => $counterparty->id, 'direction' => 'debit',  'amount' => 100, 'balance_after' => $counterparty->available_balance - 100, 'posted_at' => now()]);
        LedgerEntry::create(['transfer_id' => $netTransfer->id, 'account_id' => $proposer->id,     'direction' => 'credit', 'amount' => 100, 'balance_after' => $proposer->available_balance + 100,     'posted_at' => now()]);
        $counterparty->forceFill(['available_balance' => $counterparty->available_balance - 100])->save();
        $proposer->forceFill(['available_balance' => $proposer->available_balance + 100])->save();

        NettingProposal::create([
            'uuid'                     => (string) Str::uuid(),
            'proposer_account_id'      => $proposer->id,
            'counterparty_account_id'  => $counterparty->id,
            'proposer_transfer_ids'    => [],
            'counterparty_transfer_ids' => [],
            'proposer_total'           => 0,
            'counterparty_total'       => 100,
            'currency_code'            => 'KY',
            'net_payer_account_id'     => $counterparty->id,
            'net_amount'               => 100,
            'status'                   => 'accepted',
            'net_transfer_id'          => $netTransfer->id,
            'actioned_by'              => $admin->id,
            'actioned_at'              => now(),
            'proposed_by'              => $admin->id,
        ]);

        $totalBefore = (int) Account::query()->sum('available_balance');

        // Deve fallire in modo pulito (422), non con un errore SQL grezzo da vincolo FK.
        $this->actingAs($admin)
            ->post('/admin/transfers/' . $netTransfer->id . '/delete')
            ->assertStatus(422);

        // Il movimento resta e i saldi non cambiano: nessuna corruzione a metà.
        $this->assertDatabaseHas('transfers', ['id' => $netTransfer->id]);
        $this->assertSame($totalBefore, (int) Account::query()->sum('available_balance'));
    }

    public function test_deleting_transfer_cancels_linked_payment_request(): void
    {
        $this->seed();

        $admin    = User::where('email', 'superadmin@kmoney.test')->firstOrFail();
        $transfer = Transfer::query()->where('kind', 'trade_payment')->latest('id')->firstOrFail();

        $paymentRequest = PaymentRequest::create([
            'to_account_id'   => $transfer->to_account_id,
            'from_account_id' => $transfer->from_account_id,
            'amount'          => $transfer->amount,
            'status'          => 'paid',
            'expires_at'      => now()->addMinutes(5),
            'paid_at'         => now(),
            'transfer_id'     => $transfer->id,
        ]);

        $this->actingAs($admin)
            ->post('/admin/transfers/' . $transfer->id . '/delete')
            ->assertRedirect();

        $paymentRequest->refresh();

        // La FK nullOnDelete azzera transfer_id: lo status deve seguirla, non
        // restare "paid" su un pagamento che non esiste più.
        $this->assertNull($paymentRequest->transfer_id);
        $this->assertSame('cancelled', $paymentRequest->status);
        $this->assertNull($paymentRequest->paid_at);
    }

    public function test_deleting_transfer_reopens_completed_payment_plan(): void
    {
        $this->seed();

        $admin    = User::where('email', 'superadmin@kmoney.test')->firstOrFail();
        $transfer = Transfer::query()->where('kind', 'trade_payment')->latest('id')->firstOrFail();

        $plan = PaymentPlan::create([
            'initiated_by'        => $admin->id,
            'from_account_id'     => $transfer->from_account_id,
            'to_account_id'       => $transfer->to_account_id,
            'total_amount'        => $transfer->amount,
            'installments_count'  => 1,
            'frequency'           => 'monthly',
            'first_due_date'      => now()->toDateString(),
            'status'              => 'completed',
        ]);

        $installment = PaymentPlanInstallment::create([
            'payment_plan_id'    => $plan->id,
            'installment_number' => 1,
            'amount'             => $transfer->amount,
            'due_date'           => now()->toDateString(),
            'status'             => 'paid',
            'transfer_id'        => $transfer->id,
            'processed_at'       => now(),
        ]);

        $this->actingAs($admin)
            ->post('/admin/transfers/' . $transfer->id . '/delete')
            ->assertRedirect();

        $installment->refresh();
        $plan->refresh();

        // La rata "pagata" non può restare tale senza il movimento che la provava;
        // e il piano non può dirsi "completed" se in realtà una rata è saltata.
        $this->assertNull($installment->transfer_id);
        $this->assertSame('cancelled', $installment->status);
        $this->assertSame('active', $plan->status);
    }

    public function test_deleting_transfer_releases_sub_account_overdraft(): void
    {
        $this->seed();

        $admin    = User::where('email', 'superadmin@kmoney.test')->firstOrFail();
        $transfer = Transfer::query()->where('kind', 'trade_payment')->latest('id')->firstOrFail();

        $overdraft = SubAccountLimitRequest::create([
            'sub_account_id'        => $transfer->from_account_id,
            'requested_by_user_id'  => $admin->id,
            'decided_by_user_id'    => $admin->id,
            'type'                  => 'temporary_overdraft',
            'requested_amount'      => $transfer->amount,
            'reason'                => 'Test overdraft',
            'status'                => 'approved',
            'overdraft_expires_at'  => now()->addDay(),
            'overdraft_used'        => true,
            'overdraft_transfer_id' => $transfer->id,
        ]);

        $this->actingAs($admin)
            ->post('/admin/transfers/' . $transfer->id . '/delete')
            ->assertRedirect();

        $overdraft->refresh();

        // Il movimento che aveva "consumato" lo sforamento non esiste più:
        // il credito concesso torna disponibile.
        $this->assertNull($overdraft->overdraft_transfer_id);
        $this->assertFalse($overdraft->overdraft_used);
    }

    public function test_deleting_transfer_does_not_alter_completed_kycard_purchase_status(): void
    {
        $this->seed();

        $admin    = User::where('email', 'superadmin@kmoney.test')->firstOrFail();
        $transfer = Transfer::query()->where('kind', 'trade_payment')->latest('id')->firstOrFail();

        $card = KyCard::create([
            'name'             => 'Test Pack',
            'price_eur_cents'  => 10000,
            'bonus_type'       => 'fixed',
            'ky_base_amount'   => 10000,
            'bonus_value'      => 0,
            'is_active'        => true,
        ]);

        $purchase = KyCardPurchase::create([
            'ky_card_id'      => $card->id,
            'account_id'      => $transfer->to_account_id,
            'user_id'         => $admin->id,
            'price_eur_cents' => 10000,
            'ky_amount'       => $transfer->amount,
            'status'          => 'completed',
            'payment_method'  => 'stripe',
            'transfer_id'     => $transfer->id,
            'completed_at'    => now(),
        ]);

        $this->actingAs($admin)
            ->post('/admin/transfers/' . $transfer->id . '/delete')
            ->assertRedirect();

        $purchase->refresh();

        // Il pagamento reale (Stripe) è già avvenuto FUORI dal circuito KY:
        // lo status "completed" resta l'unica verità corretta anche se il
        // transfer KY collegato viene eliminato. Cambiarlo rischierebbe un
        // doppio accredito (creditKy() usa isCompleted() come guardia).
        $this->assertNull($purchase->transfer_id);
        $this->assertSame('completed', $purchase->status);
        $this->assertNotNull($purchase->admin_notes);
    }

    public function test_non_admin_cannot_delete_transfer(): void
    {
        $this->seed();

        $user     = User::factory()->create(['is_super_admin' => false]);
        $transfer = Transfer::query()->where('kind', 'trade_payment')->latest('id')->firstOrFail();

        $this->actingAs($user)
            ->post('/admin/transfers/' . $transfer->id . '/delete')
            ->assertForbidden();

        $this->assertDatabaseHas('transfers', ['id' => $transfer->id]);
    }
}
