<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsShopScenarios;
use Tests\TestCase;

/**
 * COMPRARE E VENDERE SONO DUE PERMESSI DIVERSI (27/08/2026).
 *
 * `canAccessMarketplace()` risponde si' a chi ha `marketplace.buy` **oppure**
 * `marketplace.sell`, e con quella sola domanda erano protette quattro porte
 * che non riguardano il comprare: pubblicare un prodotto, salvarlo, e
 * configurare i gateway di incasso dell'azienda.
 *
 * ONESTA' SULLA GRAVITA'. Non era una porta aperta: pubblicare richiede anche
 * che l'azienda sia in directory, e i gateway richiedono un'azienda. Un
 * privato con il solo permesso di comprare veniva fermato lo stesso — ma da un
 * controllo che sta li' per un altro motivo. Il giorno in cui quel controllo
 * fosse cambiato, il fianco sarebbe rimasto scoperto senza che nessuno se ne
 * accorgesse.
 *
 * Il test che conta e' il primo: un utente che ha TUTTE le altre condizioni in
 * regola — azienda in directory, conto a posto — e solo il permesso di
 * comprare. Prima passava.
 */
class PermessiVenditaTest extends TestCase
{
    use BuildsShopScenarios;
    use RefreshDatabase;

    public function test_chi_ha_solo_il_permesso_di_comprare_non_pubblica(): void
    {
        // Tutto in regola tranne il permesso: e' il caso che prima passava,
        // perche' l'unica cosa che lo fermava era il controllo sull'azienda —
        // che qui e' soddisfatto.
        $utente = $this->utenteDiAziendaCon(['marketplace.buy']);

        $this->actingAs($utente)->get(route('portal.shop.create'))
            ->assertRedirect()
            ->assertSessionHas('portal_error', fn ($m) => str_contains($m, 'permessi'));

        $this->actingAs($utente)->post(route('portal.shop.store'), [
            'title'         => 'Prodotto di chi non puo\' vendere',
            'description'   => 'Descrizione sufficientemente lunga.',
            'category'      => 'informatica',
            'price_ky'      => '10.00',
            'ky_percentage' => 100,
            'stock_mode'    => 'unlimited',
            'delivery_type' => \App\Models\Listing::DELIVERY_TYPE_SERVIZIO,
        ])->assertForbidden();

        $this->assertSame(0, \App\Models\Listing::query()->count());
    }

    public function test_chi_ha_il_permesso_di_vendere_pubblica_ancora(): void
    {
        $utente = $this->utenteDiAziendaCon(['marketplace.sell']);

        $this->actingAs($utente)->get(route('portal.shop.create'))->assertOk();
    }

    public function test_il_titolare_d_azienda_non_perde_niente(): void
    {
        // Regressione che conta piu' di tutte: i venditori veri ricevono
        // `marketplace.sell` dal loro RUOLO, non da un permesso esplicito. Se
        // questa separazione li avesse toccati, avremmo spento lo shop.
        [, $sellerUser] = $this->makeSeller();
        $this->aziendaInDirectory($sellerUser);

        $this->actingAs($sellerUser->fresh())->get(route('portal.shop.create'))->assertOk();
    }

    public function test_chi_puo_solo_comprare_continua_a_comprare(): void
    {
        // L'altra faccia: separare non deve togliere niente a chi compra.
        $utente = $this->utenteDiAziendaCon(['marketplace.buy']);
        [$company] = $this->makeSeller();
        $this->makeListing($company, prezzo: 2000, kyPercentage: 100, extra: ['title' => 'Cosa in vendita']);

        $this->actingAs($utente)->get(route('portal.shop'))
            ->assertOk()
            ->assertSee('Cosa in vendita');
    }

    public function test_i_gateway_di_incasso_vogliono_il_permesso_di_vendere(): void
    {
        $compratore = $this->utenteDiAziendaCon(['marketplace.buy']);
        $venditore  = $this->utenteDiAziendaCon(['marketplace.sell']);

        $this->actingAs($compratore)->get(route('portal.payment-gateways.index'))->assertForbidden();
        $this->actingAs($venditore)->get(route('portal.payment-gateways.index'))->assertOk();
    }

    public function test_l_admin_del_circuito_passa_comunque(): void
    {
        $admin = User::create([
            'name'                => 'Admin del circuito',
            'email'               => 'admin-' . \Illuminate\Support\Str::random(8) . '@test.test',
            'password'            => 'secret123',
            'account_holder_type' => 'private',
            'company_id'          => null,
            'role'                => 'admin',
            'is_active'           => true,
            'is_super_admin'      => true,
            'email_verified_at'   => now(),
            'contract_signed_at'  => now(),
        ]);

        $this->assertTrue($admin->canSellInMarketplace());
    }

    // =========================================================================
    // Impalcatura
    // =========================================================================

    /**
     * Un utente con un'azienda in directory e un conto, a cui si danno
     * ESATTAMENTE i permessi chiesti — niente ruoli storici di mezzo.
     */
    private function utenteDiAziendaCon(array $permessi): User
    {
        [$company, $utente] = $this->makeSeller();
        $this->aziendaInDirectory($utente);

        // `role` neutro: i ruoli storici (owner, admin...) portano con se'
        // entrambi i permessi del marketplace, e falserebbero il test.
        $utente->forceFill(['role' => 'company-member'])->save();

        $ruolo = Role::firstOrCreate(
            ['slug' => 'prova-' . implode('-', array_map(fn ($p) => str_replace('.', '-', $p), $permessi))],
            ['name' => 'Ruolo di prova ' . implode(' ', $permessi), 'scope' => 'portal']
        );

        foreach ($permessi as $slug) {
            $permesso = Permission::firstOrCreate(['slug' => $slug], ['name' => $slug]);
            $ruolo->permissions()->syncWithoutDetaching([$permesso->id]);
        }

        $utente->roles()->syncWithoutDetaching([$ruolo->id]);

        return $utente->fresh(['roles.permissions', 'company']);
    }

    private function aziendaInDirectory(User $utente): void
    {
        $utente->company?->forceFill([
            'status'     => 'active',
            'kyc_status' => 'approved',
        ])->save();
    }
}
