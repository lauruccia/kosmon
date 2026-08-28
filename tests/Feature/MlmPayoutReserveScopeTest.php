<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\MlmCommission;
use App\Models\MlmCommissionRun;
use App\Models\MlmPayout;
use App\Models\MlmWalletLedgerEntry;
use App\Models\User;
use App\Services\MlmPayoutService;
use App\Services\MlmWalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Copre l'AMBITO della riserva nel cassetto kmoney.
 *
 * Il motivo per cui questo file esiste: fino al 28/08/2026 MlmPayoutService
 * ritrovava la riserva di una liquidazione con
 * `idempotency_key LIKE "mlm_wallet_reserve_payout_{id}_%"`. In SQL
 * l'underscore e' un jolly da un carattere, quindi il pattern della
 * liquidazione #1 catturava anche la #12; e non c'era nessun filtro per
 * agente, quindi quel totale gonfiato veniva poi rilasciato sull'agente
 * sbagliato. Riprodotto ad agosto: rifiutando una liquidazione da 50 euro ne
 * uscivano 3.000 sul conto di un altro agente.
 */
class MlmPayoutReserveScopeTest extends TestCase
{
    use RefreshDatabase;

    private MlmPayoutService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new MlmPayoutService();
        $this->makeSuperAdmin();
    }

    private function makeSuperAdmin(): User
    {
        $user = User::create([
            'name'                => 'Super Admin Sistema',
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

    private function saldo(int $agentUserId): int
    {
        return (int) Account::where('owner_user_id', $agentUserId)
            ->whereNull('parent_account_id')
            ->firstOrFail()
            ->available_balance;
    }

    /** Commissione maturata + accredito immediato nel cassetto, come in produzione. */
    private function givePendingCommission(User $agent, int $amountEurCents): MlmCommission
    {
        $periodMonth = now()->copy()->startOfMonth();

        $run = MlmCommissionRun::whereDate('period_month', $periodMonth->toDateString())->first()
            ?? MlmCommissionRun::create([
                'period_month'    => $periodMonth->toDateString(),
                'idempotency_key' => 'run_' . Str::random(10),
                'status'          => 'completed',
                'started_at'      => now(),
                'completed_at'    => now(),
            ]);

        $client = User::create([
            'name'                => 'Cliente ' . Str::random(6),
            'email'               => 'cliente-' . Str::random(10) . '@test.test',
            'password'            => 'secret123',
            'account_holder_type' => 'private',
            'is_active'           => true,
            'mlm_role'            => 'cliente',
            'mlm_client_agent_id' => $agent->id,
        ]);

        $commission = MlmCommission::create([
            'mlm_commission_run_id' => $run->id,
            'agent_user_id'         => $agent->id,
            'type'                  => 'diretta',
            'source_client_id'      => $client->id,
            'base_amount_eur_cents' => $amountEurCents * 5,
            'percentage'            => 20.0,
            'amount_eur_cents'      => $amountEurCents,
            'status'                => 'pending',
            'idempotency_key'       => 'commission_' . Str::random(10),
        ]);

        app(MlmWalletService::class)->creditFromCommission($commission);

        return $commission;
    }

    /**
     * Dodici agenti, una commissione a testa, una sola generazione: le
     * liquidazioni prendono gli id 1..12, una per agente. E' la
     * configurazione minima in cui il vecchio LIKE sbagliava.
     *
     * @return array{0: MlmPayout, 1: MlmPayout}
     */
    private function dodiciLiquidazioni(): array
    {
        for ($i = 1; $i <= 12; $i++) {
            $this->givePendingCommission($this->makeAgent(), $i * 1_000);
        }

        $this->service->generateForMonth(now());

        $this->assertSame(12, MlmPayout::count());

        $prima = MlmPayout::findOrFail(1);
        $dodicesima = MlmPayout::findOrFail(12);

        $this->assertNotSame(
            $prima->agent_user_id,
            $dodicesima->agent_user_id,
            'Le due liquidazioni devono essere di agenti diversi, altrimenti il test non prova niente.'
        );

        return [$prima, $dodicesima];
    }

    // ── Il buco ─────────────────────────────────────────────────────────────

    public function test_rifiutare_la_prima_liquidazione_non_rilascia_i_soldi_della_dodicesima(): void
    {
        [$prima, $dodicesima] = $this->dodiciLiquidazioni();

        $saldoPrimaAgente     = $this->saldo($prima->agent_user_id);
        $saldoDodicesimoPrima = $this->saldo($dodicesima->agent_user_id);

        $this->service->reject($prima, $this->makeSuperAdmin(), 'prova');

        $this->assertSame(
            $saldoPrimaAgente + $prima->total_eur_cents,
            $this->saldo($prima->agent_user_id),
            'Il rilascio deve valere esattamente la liquidazione rifiutata, non anche quella di un altro agente.'
        );

        $this->assertSame(
            $saldoDodicesimoPrima,
            $this->saldo($dodicesima->agent_user_id),
            'La liquidazione #12 non c\'entra niente: il suo agente non deve essere toccato.'
        );
    }

    public function test_la_riserva_di_una_liquidazione_non_viene_gonfiata_da_quella_di_un_altra(): void
    {
        [$prima] = $this->dodiciLiquidazioni();

        $agente = User::findOrFail($prima->agent_user_id);
        $saldoDiPartenza = $this->saldo($agente->id);

        // Nuova commissione dello stesso mese: generateForMonth la aggancia
        // alla liquidazione #1 gia' aperta e la riserva deve crescere di
        // altrettanto. Col vecchio LIKE il "gia' riservato" comprendeva anche
        // i 12.000 della #12, quindi il delta usciva negativo e non veniva
        // riservato NIENTE: l'agente si teneva spendibile un importo gia'
        // impegnato.
        $this->givePendingCommission($agente, 1_000);
        $this->assertSame($saldoDiPartenza + 1_000, $this->saldo($agente->id));

        $this->service->generateForMonth(now());

        $this->assertSame(
            $saldoDiPartenza,
            $this->saldo($agente->id),
            'L\'aumento della liquidazione doveva essere riservato per intero.'
        );

        $this->assertSame(
            $prima->total_eur_cents + 1_000,
            (int) MlmPayout::findOrFail($prima->id)->total_eur_cents
        );
    }

    // ── La struttura su cui poggia il fix ───────────────────────────────────

    public function test_la_riga_di_riserva_registra_agente_e_id_della_liquidazione(): void
    {
        $agente = $this->makeAgent();
        $this->givePendingCommission($agente, 5_000);

        $payout = $this->service->generateForMonth(now())->first();

        $riga = MlmWalletLedgerEntry::where('source_type', 'withdrawal_reserve')
            ->where('agent_user_id', $agente->id)
            ->firstOrFail();

        $this->assertSame($payout->id, (int) $riga->source_id, 'source_id deve dire a quale liquidazione appartiene la riserva.');
        $this->assertSame(-5_000, (int) $riga->amount_cents);
    }

    public function test_il_rilascio_registra_a_sua_volta_l_id_della_liquidazione(): void
    {
        $agente = $this->makeAgent();
        $this->givePendingCommission($agente, 5_000);

        $payout = $this->service->generateForMonth(now())->first();
        $this->service->reject($payout, $this->makeSuperAdmin(), 'prova');

        $riga = MlmWalletLedgerEntry::where('source_type', 'withdrawal_release')
            ->where('agent_user_id', $agente->id)
            ->firstOrFail();

        $this->assertSame($payout->id, (int) $riga->source_id);
        $this->assertSame(5_000, (int) $riga->amount_cents);
    }
}
