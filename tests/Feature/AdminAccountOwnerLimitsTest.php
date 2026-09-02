<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\CreditLimit;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Il massimale cambiato dalla PAGINA DEL CONTO (/admin/accounts/{id}).
 *
 * Il bug (02/09/2026): quel riquadro faceva POST su `admin.users.update`,
 * che pretende `name`, `email` e `account_holder_type`. Il form non li
 * mandava, la validazione bocciava tutto e il massimale non si salvava MAI —
 * in silenzio, perche' l'errore compariva in cima alla pagina mentre il form
 * sta in fondo. Laura scriveva 6.000, ricaricava, e ritrovava 15.000.
 *
 * La riparazione non e' stata "aggiungo gli hidden mancanti": in quel
 * controller `is_super_admin` e `primary_account_allow_negative` passano da
 * `$request->boolean()`, quindi salvare il massimale da qui avrebbe tolto il
 * superadmin all'intestatario e spento il flag che al massimale da' senso.
 * Da qui i due test di NON-danno, che sono il vero motivo di questo file.
 */
class AdminAccountOwnerLimitsTest extends TestCase
{
    use RefreshDatabase;

    private function superadmin(): User
    {
        $this->seed();

        return User::where('email', 'superadmin@kmoney.test')->firstOrFail();
    }

    private function conto(array $utente = [], array $conto = []): Account
    {
        $owner = User::factory()->create(array_merge([
            'negative_balance_limit'       => 600000,
            'circuit_capacity_limit'       => null,
            'transfer_limits_use_defaults' => false,
        ], $utente));

        return Account::factory()->create(array_merge([
            'owner_user_id'          => $owner->id,
            'available_balance'      => -506142,
            'allow_negative_balance' => true,
            'max_balance'            => 1000000,
        ], $conto));
    }

    public function test_salva_davvero_il_massimale_dalla_pagina_del_conto(): void
    {
        $admin = $this->superadmin();
        $conto = $this->conto();

        $this->actingAs($admin)
            ->post('/admin/accounts/' . $conto->id . '/limiti-proprietario', [
                'negative_balance_limit' => '1500,00',
                'circuit_capacity_limit' => '2000',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $owner = $conto->ownerUser->fresh();

        $this->assertSame(150000, (int) $owner->negative_balance_limit);
        $this->assertSame(200000, (int) $owner->circuit_capacity_limit);
        $this->assertSame(150000, $conto->fresh()->massimale());
    }

    /** La virgola italiana e' quella che l'admin digita davvero. */
    public function test_accetta_la_virgola_come_separatore(): void
    {
        $admin = $this->superadmin();
        $conto = $this->conto();

        $this->actingAs($admin)
            ->post('/admin/accounts/' . $conto->id . '/limiti-proprietario', [
                'negative_balance_limit' => '80,40',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(8040, (int) $conto->ownerUser->fresh()->negative_balance_limit);
    }

    /** Campo svuotato = nessun limite proprio dell'intestatario, non zero implicito. */
    public function test_campo_vuoto_azzera_il_limite(): void
    {
        $admin = $this->superadmin();
        $conto = $this->conto();

        $this->actingAs($admin)
            ->post('/admin/accounts/' . $conto->id . '/limiti-proprietario', [
                'negative_balance_limit' => '',
                'circuit_capacity_limit' => '',
            ])
            ->assertSessionHasNoErrors();

        $this->assertNull($conto->ownerUser->fresh()->negative_balance_limit);
        $this->assertSame(0, $conto->fresh()->massimale());
    }

    /**
     * Il danno numero uno che il rattoppo con gli hidden avrebbe fatto:
     * salvare il massimale di un intestatario superadmin lo declassava.
     */
    public function test_non_tocca_il_superadmin_dell_intestatario(): void
    {
        $admin = $this->superadmin();
        $conto = $this->conto(['is_super_admin' => true]);

        $this->actingAs($admin)
            ->post('/admin/accounts/' . $conto->id . '/limiti-proprietario', [
                'negative_balance_limit' => '100',
            ])
            ->assertSessionHasNoErrors();

        $this->assertTrue((bool) $conto->ownerUser->fresh()->is_super_admin);
    }

    /**
     * Il danno numero due, il piu' beffardo: salvare il massimale spegneva
     * `allow_negative_balance`, cioe' il flag senza il quale il massimale
     * appena scritto non serve a niente. Insieme controlliamo che non
     * vengano toccati nemmeno gli altri limiti del conto e dell'utente.
     */
    public function test_non_spegne_il_saldo_negativo_ne_gli_altri_limiti(): void
    {
        $admin = $this->superadmin();
        $conto = $this->conto([
            'daily_transaction_limit' => 300000,
            'per_movement_limit'      => 50000,
            'is_active'               => true,
        ], [
            'max_balance'     => 1000000,
            'spending_limit'  => 70000,
        ]);

        $this->actingAs($admin)
            ->post('/admin/accounts/' . $conto->id . '/limiti-proprietario', [
                'negative_balance_limit' => '100',
            ])
            ->assertSessionHasNoErrors();

        $contoAggiornato = $conto->fresh();
        $owner = $conto->ownerUser->fresh();

        $this->assertTrue((bool) $contoAggiornato->allow_negative_balance);
        $this->assertSame(1000000, (int) $contoAggiornato->max_balance);
        $this->assertSame(70000, (int) $contoAggiornato->spending_limit);
        $this->assertSame(300000, (int) $owner->daily_transaction_limit);
        $this->assertSame(50000, (int) $owner->per_movement_limit);
        $this->assertTrue((bool) $owner->is_active);
    }

    /**
     * Il malinteso da cui e' partita tutta la storia: con un fido attivo piu'
     * alto, il numero scritto nel campo si salva ma NON e' il massimale che
     * vale. La pagina deve dirlo, invece di lasciar credere a un salvataggio
     * perso.
     */
    public function test_avvisa_quando_il_fido_attivo_e_piu_alto_del_limite_utente(): void
    {
        $admin = $this->superadmin();
        $conto = $this->conto();
        CreditLimit::factory()->forAccount($conto->id)->withLimit(1500000)->create();

        $this->actingAs($admin)
            ->post('/admin/accounts/' . $conto->id . '/limiti-proprietario', [
                'negative_balance_limit' => '600',
            ])
            ->assertSessionHas('portal_success', function (string $messaggio): bool {
                return str_contains($messaggio, '15.000,00')
                    && str_contains($messaggio, 'fido attivo');
            });

        $this->assertSame(60000, (int) $conto->ownerUser->fresh()->negative_balance_limit);
        $this->assertSame(1500000, $conto->fresh()->massimale());
    }

    /** Massimale scritto ma inutilizzabile: va detto subito. */
    public function test_avvisa_se_il_saldo_negativo_e_bloccato_sul_conto(): void
    {
        $admin = $this->superadmin();
        $conto = $this->conto([], ['allow_negative_balance' => false]);

        $this->actingAs($admin)
            ->post('/admin/accounts/' . $conto->id . '/limiti-proprietario', [
                'negative_balance_limit' => '600',
            ])
            ->assertSessionHas('portal_success', fn (string $m): bool => str_contains($m, 'Non consentito'));
    }

    public function test_rifiuta_un_massimale_negativo_senza_scrivere_niente(): void
    {
        $admin = $this->superadmin();
        $conto = $this->conto();

        $this->actingAs($admin)
            ->post('/admin/accounts/' . $conto->id . '/limiti-proprietario', [
                'negative_balance_limit' => '-10',
            ])
            ->assertSessionHasErrors('negative_balance_limit');

        $this->assertSame(600000, (int) $conto->ownerUser->fresh()->negative_balance_limit);
    }

    public function test_scrive_l_audit_log_con_il_prima_e_il_dopo(): void
    {
        $admin = $this->superadmin();
        $conto = $this->conto();

        $this->actingAs($admin)
            ->post('/admin/accounts/' . $conto->id . '/limiti-proprietario', [
                'negative_balance_limit' => '900',
            ])
            ->assertSessionHasNoErrors();

        $log = AuditLog::where('event', 'admin.account.owner_limits_updated')
            ->where('auditable_id', $conto->id)
            ->firstOrFail();

        $this->assertSame($admin->id, (int) $log->actor_user_id);
        $this->assertSame(600000, (int) $log->context['da']['negative_balance_limit']);
        $this->assertSame(90000, (int) $log->context['a']['negative_balance_limit']);
    }

    public function test_un_utente_di_portale_non_puo_toccare_il_massimale(): void
    {
        $this->seed();
        $estraneo = User::where('email', 'operatore-panificio-canale@kmoney.test')->firstOrFail();
        $conto = $this->conto();

        $this->actingAs($estraneo)
            ->post('/admin/accounts/' . $conto->id . '/limiti-proprietario', [
                'negative_balance_limit' => '999999',
            ])
            ->assertForbidden();

        $this->assertSame(600000, (int) $conto->ownerUser->fresh()->negative_balance_limit);
    }

    /**
     * Guardia contro il ritorno del bug: se qualcuno ripunta il form su
     * `admin.users.update`, questo test cade. E' l'unica cosa che distingue
     * "il campo si salva" da "il campo sembra salvarsi".
     */
    public function test_la_pagina_del_conto_punta_alla_rotta_dedicata(): void
    {
        $admin = $this->superadmin();
        $conto = $this->conto();

        $this->actingAs($admin)
            ->get('/admin/accounts/' . $conto->id)
            ->assertOk()
            ->assertSee('/admin/accounts/' . $conto->id . '/limiti-proprietario', false)
            ->assertDontSee('action="' . url('/admin/users/' . $conto->owner_user_id) . '"', false);
    }

    /** Un conto senza intestatario non deve esplodere: lo dice e basta. */
    public function test_conto_senza_intestatario_risponde_con_un_errore_leggibile(): void
    {
        $admin = $this->superadmin();
        $conto = Account::factory()->create(['owner_user_id' => null]);

        $this->actingAs($admin)
            ->post('/admin/accounts/' . $conto->id . '/limiti-proprietario', [
                'negative_balance_limit' => '100',
            ])
            ->assertSessionHasErrors('negative_balance_limit');
    }
}
