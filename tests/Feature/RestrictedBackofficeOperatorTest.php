<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Transfer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A7 e A9 (31/08) — le due porte che aggiravano il ruolo ristretto.
 *
 * Il 12/08 e' nato "Gestore Aziende e Prodotti": un operatore che gestisce
 * aziende e prodotti e basta. `EnsureCanAccessBackoffice` lo tiene fuori da
 * tutto il resto con una lista chiusa. Due strade la scavalcavano:
 *
 * - **A7** le rotte `/broker` stanno nel gruppo PORTALE, quindi quel middleware
 *   non le vede: il controllo era scritto a mano e chiedeva
 *   `canAccessBackoffice()`, vero anche per lui. Risultato: saldi e movimenti
 *   di TUTTE le aziende, cioe' esattamente cio' che gli era stato chiuso su
 *   /admin/accounts e /admin/movimenti.
 * - **A9** le tre rotte dei gateway di incasso erano ELENCATE sotto
 *   `companies.manage`, quindi poteva riscrivere IBAN, intestatario e le
 *   chiavi Stripe/PayPal di qualsiasi azienda — dirottando gli incassi in euro.
 *
 * Ogni test verifica anche il rovescio: chi ha l'accesso PIENO continua a
 * passare, e l'operatore ristretto continua a fare il suo lavoro. Una chiusura
 * che blocca anche chi doveva restare dentro non e' una chiusura, e' un guasto.
 */
class RestrictedBackofficeOperatorTest extends TestCase
{
    use RefreshDatabase;

    private function permesso(string $slug): Permission
    {
        return Permission::firstOrCreate(['slug' => $slug], ['name' => $slug]);
    }

    private function utente(bool $superAdmin = false): User
    {
        return User::create([
            'name'                => 'Operatore ' . Str::random(4),
            'email'               => 'op-' . Str::random(8) . '@test.test',
            'password'            => 'secret123',
            'account_holder_type' => 'private',
            'company_id'          => null,
            'is_active'           => true,
            'is_super_admin'      => $superAdmin,
            'email_verified_at'   => now(),
            'contract_signed_at'  => now(),
        ]);
    }

    /** L'operatore del 12/08: backoffice.access ma NON backoffice.full. */
    private function operatoreRistretto(): User
    {
        $ruolo = Role::firstOrCreate(
            ['slug' => 'company-listings-operator'],
            ['name' => 'Gestore Aziende e Prodotti', 'scope' => 'system']
        );
        $ruolo->permissions()->syncWithoutDetaching([
            $this->permesso('backoffice.access')->id,
            $this->permesso('companies.read')->id,
            $this->permesso('companies.manage')->id,
            $this->permesso('listings.read')->id,
            $this->permesso('listings.manage')->id,
        ]);

        $utente = $this->utente();
        $utente->roles()->syncWithoutDetaching([$ruolo->id]);

        return $utente->fresh();
    }

    /** L'operatore di control room: ha anche backoffice.full. */
    private function operatorePieno(): User
    {
        $ruolo = Role::firstOrCreate(
            ['slug' => 'backoffice-operator'],
            ['name' => 'Backoffice Operator', 'scope' => 'system']
        );
        $ruolo->permissions()->syncWithoutDetaching([
            $this->permesso('backoffice.access')->id,
            $this->permesso('backoffice.full')->id,
            $this->permesso('companies.read')->id,
        ]);

        $utente = $this->utente();
        $utente->roles()->syncWithoutDetaching([$ruolo->id]);

        return $utente->fresh();
    }

    private function azienda(?int $brokerUserId = null, int $saldo = 500000): Company
    {
        $company = Company::create([
            'name'           => 'Azienda ' . Str::random(6),
            'slug'           => 'azienda-' . Str::random(6),
            'status'         => 'active',
            'broker_user_id' => $brokerUserId,
        ]);

        Account::create([
            'company_id'             => $company->id,
            'owner_type'             => 'company',
            'type'                   => 'primary',
            'status'                 => 'active',
            'available_balance'      => $saldo,
            'allow_negative_balance' => false,
        ]);

        return $company;
    }

    // ── A7: /broker ──────────────────────────────────────────────────────────

    public function test_the_restricted_operator_is_out_of_the_broker_dashboard(): void
    {
        $this->azienda();

        $this->actingAs($this->operatoreRistretto())
            ->get(route('broker.dashboard'))
            ->assertForbidden();
    }

    public function test_the_restricted_operator_cannot_open_a_company_from_broker(): void
    {
        $azienda = $this->azienda();

        $this->actingAs($this->operatoreRistretto())
            ->get(route('broker.clients.show', $azienda))
            ->assertForbidden();
    }

    public function test_full_backoffice_still_sees_the_broker_dashboard(): void
    {
        $this->azienda();

        $this->actingAs($this->operatorePieno())
            ->get(route('broker.dashboard'))
            ->assertOk();
    }

    public function test_the_super_admin_still_sees_the_broker_dashboard(): void
    {
        $this->azienda();

        $this->actingAs($this->utente(superAdmin: true))
            ->get(route('broker.dashboard'))
            ->assertOk();
    }

    /**
     * Il broker vero non deve essere toccato: e' l'errore in cui si cadrebbe
     * mettendo il middleware `backoffice` sulle rotte, visto che un broker
     * `backoffice.access` non ce l'ha e verrebbe rifiutato.
     */
    public function test_the_real_broker_still_sees_only_their_own_clients(): void
    {
        $broker = $this->utente();
        $ruolo  = Role::firstOrCreate(['slug' => 'broker'], ['name' => 'Broker', 'scope' => 'system']);
        $broker->roles()->syncWithoutDetaching([$ruolo->id]);

        $suo   = $this->azienda($broker->id);
        $altrui = $this->azienda();

        $this->actingAs($broker->fresh())
            ->get(route('broker.dashboard'))
            ->assertOk()
            ->assertSee($suo->name)
            ->assertDontSee($altrui->name);
    }

    // ── A9: le credenziali di incasso ────────────────────────────────────────

    public function test_the_restricted_operator_cannot_touch_the_payment_credentials(): void
    {
        $azienda = $this->azienda();

        $this->actingAs($this->operatoreRistretto())
            ->post(route('admin.companies.payment-gateways.update', [$azienda, 'bank_transfer']), [
                'credentials' => ['iban' => 'IT60X0542811101000000123456', 'intestatario' => 'Chi Non Deve'],
            ])
            ->assertForbidden();
    }

    public function test_the_restricted_operator_cannot_delete_a_payment_gateway(): void
    {
        $azienda = $this->azienda();

        $this->actingAs($this->operatoreRistretto())
            ->delete(route('admin.companies.payment-gateways.destroy', [$azienda, 'bank_transfer']))
            ->assertForbidden();
    }

    // ── E il suo lavoro deve continuare a funzionare ─────────────────────────

    public function test_the_restricted_operator_still_does_their_own_job(): void
    {
        $this->azienda();

        $operatore = $this->operatoreRistretto();

        $this->actingAs($operatore)->get(route('admin.companies.index'))->assertOk();
        $this->actingAs($operatore)->get(route('admin.listings.index'))->assertOk();
    }

    // ── M1 sull'ultimo flusso rimasto: il pagamento operato dal broker ───────

    public function test_the_broker_payment_form_sent_twice_charges_once(): void
    {
        $broker = $this->utente();
        $ruolo  = Role::firstOrCreate(['slug' => 'broker'], ['name' => 'Broker', 'scope' => 'system']);
        $broker->roles()->syncWithoutDetaching([$ruolo->id]);
        $broker = $broker->fresh();

        $cliente = $this->azienda($broker->id, 500000);
        $da      = $cliente->accounts()->firstOrFail();
        $a       = $this->azienda(saldo: 0)->accounts()->firstOrFail();

        $token = (string) Str::uuid();
        $invia = fn () => $this->actingAs($broker)->post(route('broker.pay.submit', $cliente), [
            'to_account_id' => $a->id,
            'amount'        => '20.00',
            'description'   => 'Pagamento di prova',
            'invio_token'   => $token,
        ]);

        $invia();
        $invia();

        $this->assertSame(1, Transfer::where('kind', 'broker_payment')->count());
        $this->assertSame(498000, (int) $da->fresh()->available_balance);
        $this->assertSame(2000, (int) $a->fresh()->available_balance);
    }

    public function test_the_broker_pay_form_carries_the_token(): void
    {
        $broker = $this->utente();
        $ruolo  = Role::firstOrCreate(['slug' => 'broker'], ['name' => 'Broker', 'scope' => 'system']);
        $broker->roles()->syncWithoutDetaching([$ruolo->id]);

        $cliente = $this->azienda($broker->id);

        $this->actingAs($broker->fresh())
            ->get(route('broker.pay.form', $cliente))
            ->assertOk()
            ->assertSee('name="invio_token"', false);
    }
}
