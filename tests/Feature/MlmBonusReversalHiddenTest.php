<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\MlmBonusPayout;
use App\Models\MlmWalletLedgerEntry;
use App\Models\Transfer;
use App\Models\User;
use App\Services\MlmWalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 2026-08-14, richiesta di Laura: i movimenti correttivi generati per annullare
 * i bonus creati per errore non devono comparire nelle liste — "mantenere il
 * circuito chiuso, ma non visualizzare questo tipo di movimenti".
 *
 * Quindi: le righe restano nel database e i saldi non cambiano (circuito
 * chiuso), ma spariscono da ogni elenco insieme all'accredito che annullano.
 * In backoffice tornano visibili solo con la spunta "Movimenti tecnici".
 */
class MlmBonusReversalHiddenTest extends TestCase
{
    use RefreshDatabase;

    private MlmWalletService $wallet;

    protected function setUp(): void
    {
        parent::setUp();
        $this->wallet = new MlmWalletService();
        $this->makeSuperAdmin();
    }

    private function makeSuperAdmin(): User
    {
        $user = User::create([
            'name'                => 'Super Admin ' . Str::random(4),
            'email'               => 'superadmin-' . Str::random(8) . '@test.test',
            'password'            => 'secret123',
            'account_holder_type' => 'private',
            'is_active'           => true,
            'is_super_admin'      => true,
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();

        return $user;
    }

    private function makeAgent(): User
    {
        $agent = User::create([
            'name'                => 'Agente ' . Str::random(6),
            'email'               => 'agente-' . Str::random(10) . '@test.test',
            'password'            => 'secret123',
            'account_holder_type' => 'private',
            'is_active'           => true,
            'mlm_role'            => 'agente',
            'mlm_rank'            => 'basic',
            'mlm_activated_at'    => now(),
        ]);
        $agent->forceFill(['email_verified_at' => now()])->save();

        Account::create([
            'owner_user_id'     => $agent->id,
            'owner_type'        => 'private',
            'type'              => 'primary',
            'account_name'      => 'Conto personale ' . $agent->name,
            'currency_code'     => 'KY',
            'status'            => 'active',
            'available_balance' => 0,
        ]);

        return $agent->refresh();
    }

    private function makeCreditedPayout(User $agent, int $amountCents = 20_000): MlmBonusPayout
    {
        $payout = MlmBonusPayout::create([
            'mlm_bonus_event_id'  => null,
            'beneficiary_user_id' => $agent->id,
            'rank_at_time'        => null,
            'kind'                => 'diretto',
            'amount_eur_cents'    => $amountCents,
            'week_ending'         => now()->toDateString(),
            'status'              => 'pending',
            'idempotency_key'     => 'bonus_' . Str::random(10),
        ]);

        $this->wallet->creditFromBonusPayout($payout);

        return $payout;
    }

    /** @return array{0:Transfer,1:Transfer} [accredito originale, storno] */
    private function reverseAndFetchPair(MlmBonusPayout $payout): array
    {
        $creditTransferId = (int) MlmWalletLedgerEntry::where('source_type', 'bonus_payout')
            ->where('source_id', $payout->id)
            ->firstOrFail()
            ->transfer_id;

        $this->assertTrue($this->wallet->reverseBonusPayout($payout));

        $reversalTransferId = (int) MlmWalletLedgerEntry::where('source_type', 'bonus_payout_reversal')
            ->firstOrFail()
            ->transfer_id;

        return [Transfer::findOrFail($creditTransferId), Transfer::findOrFail($reversalTransferId)];
    }

    public function test_reversal_marks_both_the_storno_and_the_original_credit(): void
    {
        $agent  = $this->makeAgent();
        $payout = $this->makeCreditedPayout($agent);

        [$credit, $reversal] = $this->reverseAndFetchPair($payout);

        $this->assertSame(Transfer::MLM_BONUS_REVERSAL_ACTION, $credit->admin_action);
        $this->assertSame(Transfer::MLM_BONUS_REVERSAL_ACTION, $reversal->admin_action);
        $this->assertTrue($credit->isTechnicalCorrection());
        $this->assertTrue($reversal->isTechnicalCorrection());
    }

    public function test_hidden_pair_stays_in_the_database_and_the_circuit_stays_closed(): void
    {
        $agent  = $this->makeAgent();
        $totalBefore = (int) Account::query()->sum('available_balance');

        $payout = $this->makeCreditedPayout($agent, 20_000);
        [$credit, $reversal] = $this->reverseAndFetchPair($payout);

        // Le righe NON vengono cancellate...
        $this->assertDatabaseHas('transfers', ['id' => $credit->id, 'status' => 'booked']);
        $this->assertDatabaseHas('transfers', ['id' => $reversal->id, 'status' => 'booked']);

        // ...e la partita doppia resta identica: il KY e' tornato alla Cassa Circuito.
        $this->assertSame($totalBefore, (int) Account::query()->sum('available_balance'));
        $this->assertSame(0, (int) Account::where('owner_user_id', $agent->id)
            ->whereNull('parent_account_id')
            ->firstOrFail()
            ->available_balance);
    }

    public function test_technical_scope_hides_the_pair_from_the_lists(): void
    {
        $agent  = $this->makeAgent();
        $payout = $this->makeCreditedPayout($agent);

        [$credit, $reversal] = $this->reverseAndFetchPair($payout);

        $visibleIds = Transfer::query()->excludeTechnicalCorrections()->pluck('id')->all();

        $this->assertNotContains($credit->id, $visibleIds);
        $this->assertNotContains($reversal->id, $visibleIds);
        $this->assertSame(2, Transfer::query()->count(), 'Le righe devono restare nel database.');

        // L'alias storico usato in decine di query deve nascondere le stesse righe.
        $this->assertSame(
            $visibleIds,
            Transfer::query()->excludeLedgerCorrections()->pluck('id')->all()
        );
    }

    public function test_normal_movements_are_not_hidden(): void
    {
        $agent  = $this->makeAgent();
        $payout = $this->makeCreditedPayout($agent);

        $creditId = (int) MlmWalletLedgerEntry::where('source_type', 'bonus_payout')
            ->where('source_id', $payout->id)
            ->firstOrFail()
            ->transfer_id;

        // Finche' il bonus non viene annullato, l'accredito e' un movimento
        // normalissimo e deve restare visibile.
        $this->assertContains($creditId, Transfer::query()->excludeTechnicalCorrections()->pluck('id')->all());
    }

    public function test_admin_movements_page_hides_the_pair_unless_the_checkbox_is_ticked(): void
    {
        $admin  = $this->makeSuperAdmin();
        $agent  = $this->makeAgent();
        $payout = $this->makeCreditedPayout($agent);

        [$credit, $reversal] = $this->reverseAndFetchPair($payout);

        $this->actingAs($admin)
            ->get('/admin/transfers?period=all')
            ->assertOk()
            ->assertDontSee($credit->reference)
            ->assertDontSee($reversal->reference);

        $this->actingAs($admin)
            ->get('/admin/transfers?period=all&show_technical=1')
            ->assertOk()
            ->assertSee($credit->reference)
            ->assertSee($reversal->reference)
            ->assertSee('TECNICO', false);
    }
}
