<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\LedgerEntry;
use App\Models\Transfer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
