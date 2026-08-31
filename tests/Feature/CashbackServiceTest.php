<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\CashbackRule;
use App\Models\Company;
use App\Models\Transfer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Il cashback e' uno SCONTO DIFFERITO A CARICO DEL VENDITORE.
 *
 * Decisione di Laura del 31/08/2026. Prima il cashback usciva dal conto di
 * sistema — cioe' coniava KY nuovi — e non ha comunque MAI erogato niente,
 * perche' pretendeva un conto di sistema con intestatario e con saldo
 * positivo: due condizioni che in produzione non possono essere vere insieme.
 *
 * Su questo servizio non esisteva un solo test (c'era solo
 * CashbackRuleControllerTest, che prova le regole, non l'erogazione).
 */
class CashbackServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->makeSuperAdmin();
    }

    // ─── Il cuore: chi paga ──────────────────────────────────────────────────

    public function test_il_cashback_esce_dal_conto_del_venditore_e_non_dal_sistema(): void
    {
        $sistema = $this->makeSystemAccount(50000);
        [$cliente, $contoCliente] = $this->makePrivate(100000);
        [, , $contoVenditore]     = $this->makeCompany(0);

        $this->rule(10);
        $this->pay($cliente, $contoCliente, $contoVenditore, 10000);

        $cashback = Transfer::where('kind', 'portal_cashback')->sole();

        $this->assertSame($contoVenditore->id, $cashback->from_account_id, 'Il cashback deve uscire dal venditore.');
        $this->assertSame($contoCliente->id, $cashback->to_account_id);
        $this->assertSame(1000, (int) $cashback->amount);

        $this->assertSame(9000, (int) $contoVenditore->fresh()->available_balance);
        $this->assertSame(91000, (int) $contoCliente->fresh()->available_balance);

        $this->assertSame(
            50000,
            (int) $sistema->fresh()->available_balance,
            'Il conto di sistema non deve essere toccato: il circuito non conia KY per il cashback.'
        );
    }

    public function test_il_cashback_lascia_traccia_in_auditlog(): void
    {
        [$cliente, $contoCliente] = $this->makePrivate(100000);
        [, , $contoVenditore]     = $this->makeCompany(0);

        $this->rule(10);
        $transfer = $this->pay($cliente, $contoCliente, $contoVenditore, 10000);

        $log = AuditLog::where('event', 'cashback.paid')->sole();

        $this->assertSame($transfer->id, (int) $log->auditable_id);
        $this->assertSame(1000, $log->context['amount']);
        $this->assertSame($contoVenditore->id, $log->context['merchant_account_id']);
    }

    // ─── Chi NON paga ────────────────────────────────────────────────────────

    /** Fra privati un pagamento e' un trasferimento, non una vendita. */
    public function test_niente_cashback_se_chi_incassa_e_un_privato(): void
    {
        [$cliente, $contoCliente] = $this->makePrivate(100000);
        [, $contoAltroPrivato]    = $this->makePrivate(0);

        $this->rule(10);
        $this->pay($cliente, $contoCliente, $contoAltroPrivato, 10000);

        $this->assertSame(0, Transfer::where('kind', 'portal_cashback')->count());
        $this->assertSame(10000, (int) $contoAltroPrivato->fresh()->available_balance);
    }

    /** Pagare il conto di sistema e' un canone, non una vendita. */
    public function test_niente_cashback_se_chi_incassa_e_il_conto_di_sistema(): void
    {
        $sistema = $this->makeSystemAccount(50000);
        [$cliente, $contoCliente] = $this->makePrivate(100000);

        $this->rule(10);
        $this->pay($cliente, $contoCliente, $sistema, 10000);

        $this->assertSame(0, Transfer::where('kind', 'portal_cashback')->count());
        $this->assertSame(60000, (int) $sistema->fresh()->available_balance);
    }

    // ─── Il tetto che sostituisce il vecchio vincolo ─────────────────────────

    /**
     * Il vecchio codice pretendeva il saldo di sistema positivo. Ora il tetto
     * e' la capienza del venditore entro il SUO fido: stessa regola del bonus
     * amico e del rimborso al commerciante.
     */
    public function test_il_venditore_puo_andare_sotto_ma_solo_fino_al_fido(): void
    {
        [$cliente, $contoCliente]           = $this->makePrivate(100000);
        [, $titolare, $contoVenditore]      = $this->makeCompany(-9500);
        $titolare->forceFill(['transfer_limits_use_defaults' => false, 'negative_balance_limit' => 1000])->save();

        $this->rule(10);
        $this->pay($cliente, $contoCliente, $contoVenditore, 10000);

        // -9500 + 10000 = 500 di saldo, piu' 1000 di fido = 1500 disponibili
        $this->assertSame(1, Transfer::where('kind', 'portal_cashback')->count());
        $this->assertSame(-500, (int) $contoVenditore->fresh()->available_balance);
    }

    public function test_niente_cashback_se_il_venditore_non_ha_capienza_nemmeno_col_fido(): void
    {
        [$cliente, $contoCliente]      = $this->makePrivate(100000);
        [, $titolare, $contoVenditore] = $this->makeCompany(-9500);
        $titolare->forceFill(['transfer_limits_use_defaults' => false, 'negative_balance_limit' => 0])->save();

        $this->rule(10);
        $this->pay($cliente, $contoCliente, $contoVenditore, 10000);

        // 500 di saldo, zero fido, servirebbero 1000
        $this->assertSame(0, Transfer::where('kind', 'portal_cashback')->count());
        $this->assertSame(500, (int) $contoVenditore->fresh()->available_balance);
    }

    // ─── Limiti dell'importo ─────────────────────────────────────────────────

    /** Uno sconto piu' grande della vendita non e' uno sconto. */
    public function test_il_cashback_non_supera_il_pagamento_su_cui_matura(): void
    {
        [$cliente, $contoCliente] = $this->makePrivate(100000);
        [, , $contoVenditore]     = $this->makeCompany(0);

        $this->rule(150);
        $this->pay($cliente, $contoCliente, $contoVenditore, 10000);

        $cashback = Transfer::where('kind', 'portal_cashback')->sole();

        $this->assertSame(10000, (int) $cashback->amount);
        $this->assertSame(0, (int) $contoVenditore->fresh()->available_balance);
    }

    public function test_il_cashback_non_genera_altro_cashback(): void
    {
        [$cliente, $contoCliente] = $this->makePrivate(100000);
        [, , $contoVenditore]     = $this->makeCompany(0);

        $this->rule(10);
        $this->pay($cliente, $contoCliente, $contoVenditore, 10000);

        $this->assertSame(1, Transfer::where('kind', 'portal_cashback')->count());
    }

    /**
     * IL CASO CHE CONTA DAVVERO, e che la prima stesura di questi test non
     * copriva: fra due AZIENDE. Il cashback del venditore torna al cliente,
     * ma se il cliente e' a sua volta un'azienda quel movimento e' di nuovo un
     * incasso di un'azienda — e senza la guardia sul tipo `portal_cashback`
     * farebbe scattare un altro cashback in senso opposto, e poi un altro, a
     * importi calanti finche' non si azzerano. Un ping-pong.
     *
     * Col cliente privato la guardia non serve (la ferma quella sull'azienda),
     * e infatti guastarla non faceva fallire niente: e' stata una mutazione
     * sopravvissuta a dire che mancava questo scenario.
     */
    public function test_fra_due_aziende_il_cashback_non_rimbalza(): void
    {
        [, $titolareCliente, $contoCliente] = $this->makeCompany(100000);
        [, , $contoVenditore]               = $this->makeCompany(0);

        $this->rule(10);
        $this->pay($titolareCliente, $contoCliente, $contoVenditore, 10000);

        $this->assertSame(
            1,
            Transfer::where('kind', 'portal_cashback')->count(),
            'Un pagamento fra aziende deve produrre UN cashback, non una catena.'
        );
        $this->assertSame(9000, (int) $contoVenditore->fresh()->available_balance);
        $this->assertSame(91000, (int) $contoCliente->fresh()->available_balance);
    }

    public function test_senza_regole_attive_non_succede_niente(): void
    {
        [$cliente, $contoCliente] = $this->makePrivate(100000);
        [, , $contoVenditore]     = $this->makeCompany(0);

        $this->rule(10, active: false);
        $this->pay($cliente, $contoCliente, $contoVenditore, 10000);

        $this->assertSame(0, Transfer::where('kind', 'portal_cashback')->count());
        $this->assertSame(10000, (int) $contoVenditore->fresh()->available_balance);
    }

    // ─── La regola, provata al suo livello ───────────────────────────────────

    /**
     * Perche' questi test esistono, visto che sopra c'e' gia'
     * "senza regole attive non succede niente": l'interruttore e' controllato
     * DUE volte — dal filtro `where('is_active', true)` nella query del
     * servizio e da isCurrentlyActive() dentro il modello. Ognuna delle due
     * copre l'altra, quindi guastandone una alla volta i test restavano verdi
     * e la mutazione sopravviveva: passando dal servizio non si distingue chi
     * delle due sta lavorando.
     *
     * La risposta non e' togliere una delle due difese, e' provarle al livello
     * a cui vivono. Qui la regola viene interrogata direttamente, senza
     * passare dal servizio, e la finestra di validita' — mai testata prima —
     * viene coperta insieme.
     */
    public function test_una_regola_spenta_non_calcola_niente(): void
    {
        $rule = $this->rule(10, active: false);

        $this->assertSame(0, $rule->calculateCashback(10000, 'portal_payment'));
    }

    public function test_una_regola_non_ancora_iniziata_non_calcola_niente(): void
    {
        $rule = $this->rule(10);
        $rule->forceFill(['valid_from' => now()->addDay()->toDateString()])->save();

        $this->assertSame(0, $rule->fresh()->calculateCashback(10000, 'portal_payment'));
    }

    public function test_una_regola_scaduta_non_calcola_niente(): void
    {
        $rule = $this->rule(10);
        $rule->forceFill(['valid_until' => now()->subDay()->toDateString()])->save();

        $this->assertSame(0, $rule->fresh()->calculateCashback(10000, 'portal_payment'));
    }

    public function test_una_regola_valida_oggi_calcola(): void
    {
        $rule = $this->rule(10);
        $rule->forceFill([
            'valid_from'  => now()->subDay()->toDateString(),
            'valid_until' => now()->addDay()->toDateString(),
        ])->save();

        $this->assertSame(1000, $rule->fresh()->calculateCashback(10000, 'portal_payment'));
    }

    // ─── Helper ──────────────────────────────────────────────────────────────

    private function pay(User $initiator, Account $from, Account $to, int $amount): Transfer
    {
        return app(\App\Services\TransferBookingService::class)->book([
            'initiated_by'    => $initiator->id,
            'from_account_id' => $from->id,
            'to_account_id'   => $to->id,
            'amount'          => $amount,
            'kind'            => 'portal_payment',
            'description'     => 'Pagamento di prova',
            'idempotency_key' => (string) Str::uuid(),
        ]);
    }

    private function rule(int $percentuale, bool $active = true): CashbackRule
    {
        return CashbackRule::create([
            'name'             => 'Regola di prova',
            'min_amount'       => 0,
            'percentage'       => $percentuale,
            'max_cashback'     => null,
            'applicable_kinds' => ['*'],
            'is_active'        => $active,
            'target_type'      => 'all',
            'created_by'       => User::where('is_super_admin', true)->value('id'),
        ]);
    }

    private function makeSuperAdmin(): User
    {
        return User::create([
            'name'                => 'Super Admin',
            'email'               => 'sa-' . Str::random(8) . '@test.test',
            'password'            => 'secret123',
            'account_holder_type' => 'private',
            'role'                => 'private-owner',
            'is_active'           => true,
            'is_super_admin'      => true,
            'email_verified_at'   => now(),
            'contract_signed_at'  => now(),
        ]);
    }

    /** @return array{0: User, 1: Account} */
    private function makePrivate(int $saldo): array
    {
        $user = User::create([
            'name'                => 'Privato ' . Str::random(4),
            'email'               => 'priv-' . Str::random(8) . '@test.test',
            'password'            => 'secret123',
            'account_holder_type' => 'private',
            'company_id'          => null,
            'role'                => 'private-owner',
            'is_active'           => true,
            'is_super_admin'      => false,
            'email_verified_at'   => now(),
            'contract_signed_at'  => now(),
        ]);

        $account = Account::create([
            'owner_user_id'     => $user->id,
            'owner_type'        => 'private',
            'type'              => 'member',
            'status'            => 'active',
            'available_balance' => $saldo,
        ]);

        return [$user->fresh(), $account->fresh()];
    }

    /** @return array{0: Company, 1: User, 2: Account} */
    private function makeCompany(int $saldo): array
    {
        $slug = 'venditore-' . Str::random(6);

        $company = Company::create([
            'name'          => 'Venditore ' . Str::random(4),
            'slug'          => $slug,
            'email'         => $slug . '@test.test',
            'status'        => 'active',
            'kyc_status'    => 'approved',
            'currency_code' => 'KY',
            'sector'        => 'informatica',
            'description'   => 'Azienda di test',
        ]);

        $user = User::create([
            'name'                => 'Titolare ' . Str::random(4),
            'email'               => 'owner-' . Str::random(8) . '@test.test',
            'password'            => 'secret123',
            'account_holder_type' => 'company',
            'company_id'          => $company->id,
            'role'                => 'owner',
            'is_active'           => true,
            'is_super_admin'      => false,
            'email_verified_at'   => now(),
            'contract_signed_at'  => now(),
        ]);

        $account = Account::create([
            'company_id'        => $company->id,
            'owner_user_id'     => $user->id,
            'owner_type'        => 'company',
            'type'              => 'member',
            'status'            => 'active',
            'available_balance' => $saldo,
            'is_system_account' => false,
        ]);

        return [$company, $user->fresh(), $account->fresh()];
    }

    /**
     * Come in produzione: il conto di sistema del circuito e' intestato a
     * un'azienda vera (oggi Knm srl). Senza company attiva il motore rifiuta
     * il pagamento prima ancora di arrivare al cashback.
     */
    private function makeSystemAccount(int $saldo): Account
    {
        $slug = 'circuito-' . Str::random(6);

        $company = Company::create([
            'name'          => 'Circuito ' . Str::random(4),
            'slug'          => $slug,
            'email'         => $slug . '@test.test',
            'status'        => 'active',
            'kyc_status'    => 'approved',
            'currency_code' => 'KY',
            'sector'        => 'servizi',
            'description'   => 'Conto sistema di test',
        ]);

        return Account::create([
            'company_id'        => $company->id,
            'owner_type'        => 'company',
            'type'              => 'member',
            'status'            => 'active',
            'available_balance' => $saldo,
            'is_system_account' => true,
        ]);
    }
}
