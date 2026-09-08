<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Listing;
use App\Models\ListingCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsShopScenarios;
use Tests\TestCase;

/**
 * MODIFICA DI UN PRODOTTO GIA' PUBBLICATO — 08/09/2026.
 *
 * Due cose segnalate dai colleghi di Laura, con la stessa radice: il form di
 * modifica non permetteva di finire il lavoro dentro il form.
 *
 * 1. **L'azienda non si poteva cambiare.** Il selettore esisteva solo in
 *    "Nuovo prodotto per conto azienda": un prodotto caricato sotto l'azienda
 *    sbagliata si poteva solo cancellare e rifare, foto comprese. Adesso c'e'
 *    anche in modifica, ma SOLO per chi ha accesso al backoffice — il prodotto
 *    e' la strada per cui i soldi di un ordine arrivano su un conto, e un
 *    venditore che potesse spostarsi i prodotti sposterebbe gli incassi.
 *
 * 2. **Il pulsante varianti buttava via le modifiche.** Era un link: chi
 *    cambiava il titolo e poi andava alle varianti tornava a un prodotto con le
 *    combinazioni nuove e il titolo vecchio. Adesso e' un submit del form —
 *    prima si salva, poi si va — e la pagina varianti sa tornare indietro al
 *    form invece che alla scheda (che per un prodotto sospeso rimbalzava
 *    addirittura alla home dello shop).
 */
class ModificaProdottoTest extends TestCase
{
    use BuildsShopScenarios;
    use RefreshDatabase;

    // =========================================================================
    // 1. Riassegnare il prodotto a un'altra azienda
    // =========================================================================

    public function test_il_backoffice_puo_spostare_il_prodotto_su_un_altra_azienda(): void
    {
        $this->makeCategoria();
        [$vecchia] = $this->makeSeller();
        [$nuova]   = $this->makeSeller();
        $listing   = $this->makeListing($vecchia, prezzo: 1000, kyPercentage: 100);

        $this->actingAs($this->makeAdmin())
            ->put(route('portal.shop.update', $listing), $this->datiModifica([
                'company_id' => $nuova->id,
                'title'      => 'Titolo corretto',
            ]))
            ->assertSessionHasNoErrors();

        $listing->refresh();

        $this->assertSame($nuova->id, $listing->company_id);
        $this->assertSame('Titolo corretto', $listing->title);
    }

    public function test_il_venditore_non_puo_spostare_il_proprio_prodotto_su_un_altra_azienda(): void
    {
        $this->makeCategoria();
        [$sua, $titolare] = $this->makeSeller();
        [$altrui]         = $this->makeSeller();
        $listing          = $this->makeListing($sua, prezzo: 1000, kyPercentage: 100);

        // Il selettore non ce l'ha nemmeno in pagina: questa e' la richiesta
        // scritta a mano di chi ci prova lo stesso.
        $this->actingAs($titolare)
            ->put(route('portal.shop.update', $listing), $this->datiModifica([
                'company_id' => $altrui->id,
                'title'      => 'Titolo nuovo',
            ]))
            ->assertSessionHasNoErrors();

        $listing->refresh();

        // Il resto della modifica passa: si ignora il campo, non si rifiuta la
        // richiesta.
        $this->assertSame($sua->id, $listing->company_id);
        $this->assertSame('Titolo nuovo', $listing->title);
    }

    public function test_il_selettore_azienda_lo_vede_solo_il_backoffice(): void
    {
        $this->makeCategoria();
        [$company, $titolare] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 1000, kyPercentage: 100);

        $this->actingAs($this->makeAdmin())
            ->get(route('portal.shop.edit', $listing))
            ->assertOk()
            ->assertSee('name="company_id"', false);

        $this->actingAs($titolare)
            ->get(route('portal.shop.edit', $listing))
            ->assertOk()
            ->assertDontSee('name="company_id"', false);
    }

    public function test_non_si_sposta_su_un_azienda_che_non_ha_un_conto_su_cui_incassare(): void
    {
        $this->makeCategoria();
        [$company] = $this->makeSeller();
        $listing   = $this->makeListing($company, prezzo: 1000, kyPercentage: 100);

        $senzaConto = $this->makeCompanySenzaConto();

        $this->actingAs($this->makeAdmin())
            ->put(route('portal.shop.update', $listing), $this->datiModifica([
                'company_id' => $senzaConto->id,
                'title'      => 'Titolo nuovo',
            ]))
            ->assertSessionHas('portal_error');

        $listing->refresh();

        // Niente e' cambiato: nemmeno il titolo, perche' il prodotto resterebbe
        // in vetrina comprabile con un venditore che non puo' incassare.
        $this->assertSame($company->id, $listing->company_id);
        $this->assertNotSame('Titolo nuovo', $listing->title);
    }

    public function test_spostandolo_valgono_le_regole_ky_dell_azienda_che_se_lo_prende(): void
    {
        $this->makeCategoria();
        [$sana]      = $this->makeSeller(saldo: 0);
        [$inDebito]  = $this->makeSeller(saldo: -5000);
        $listing     = $this->makeListing($sana, prezzo: 1000, kyPercentage: 50);

        // L'azienda di destinazione ha il saldo negativo: puo' vendere solo al
        // 100% KY (Account::allowedKyPercentages()). Il mix 50/50 che andava
        // bene alla vecchia qui non passa.
        $this->actingAs($this->makeAdmin())
            ->put(route('portal.shop.update', $listing), $this->datiModifica([
                'company_id'    => $inDebito->id,
                'ky_percentage' => 50,
            ]))
            ->assertSessionHasErrors('ky_percentage');

        $this->assertSame($sana->id, $listing->fresh()->company_id);
    }

    // =========================================================================
    // 2. Il giro modifica → varianti → modifica
    // =========================================================================

    public function test_salva_e_gestisci_varianti_salva_prima_e_apre_le_varianti_dopo(): void
    {
        $this->makeCategoria();
        [$company, $titolare] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 1000, kyPercentage: 100);

        $this->actingAs($titolare)
            ->put(route('portal.shop.update', $listing), $this->datiModifica([
                'title'             => 'Maglietta di cotone',
                'dopo_salvataggio'  => 'varianti',
            ]))
            ->assertRedirect(route('portal.shop.variants', [$listing, 'ritorno' => 'modifica']));

        // Il titolo NON si perde per strada: era il cuore della segnalazione.
        $this->assertSame('Maglietta di cotone', $listing->fresh()->title);
    }

    public function test_dalle_varianti_si_torna_al_form_di_modifica_da_cui_si_veniva(): void
    {
        $this->makeCategoria();
        [$company, $titolare] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 1000, kyPercentage: 100);

        $this->actingAs($titolare)
            ->get(route('portal.shop.variants', [$listing, 'ritorno' => 'modifica']))
            ->assertOk()
            ->assertSee('Torna alla modifica del prodotto')
            ->assertSee('href="' . route('portal.shop.edit', $listing) . '"', false);
    }

    public function test_arrivando_da_altrove_le_varianti_riportano_alla_scheda_prodotto(): void
    {
        $this->makeCategoria();
        [$company, $titolare] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 1000, kyPercentage: 100);

        $this->actingAs($titolare)
            ->get(route('portal.shop.variants', $listing))
            ->assertOk()
            ->assertSee('href="' . route('portal.shop.show', $listing) . '"', false)
            ->assertDontSee('Torna alla modifica del prodotto');
    }

    public function test_le_varianti_di_un_prodotto_sospeso_riportano_alla_modifica(): void
    {
        $this->makeCategoria();
        [$company, $titolare] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 1000, kyPercentage: 100, extra: ['status' => 'suspended']);

        // La scheda di un prodotto sospeso rimbalza alla home dello shop
        // (ListingController::show()): mandarci il link "torna indietro"
        // significava buttare fuori chi stava lavorando.
        $this->actingAs($titolare)
            ->get(route('portal.shop.variants', $listing))
            ->assertOk()
            ->assertSee('Torna alla modifica del prodotto');
    }

    public function test_salvare_un_prodotto_sospeso_non_butta_fuori_dallo_shop(): void
    {
        $this->makeCategoria();
        [$company, $titolare] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 1000, kyPercentage: 100, extra: ['status' => 'suspended']);

        $this->actingAs($titolare)
            ->put(route('portal.shop.update', $listing), $this->datiModifica(['title' => 'Titolo nuovo']))
            ->assertRedirect(route('portal.shop.edit', $listing));

        $this->assertSame('Titolo nuovo', $listing->fresh()->title);
    }

    // =========================================================================
    // Impalcature
    // =========================================================================

    /**
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function datiModifica(array $override = []): array
    {
        return array_merge([
            'title'         => 'Prodotto modificato',
            'description'   => 'Descrizione sufficientemente lunga.',
            'category'      => 'informatica',
            'price_ky'      => '10.00',
            'ky_percentage' => 100,
            'stock_mode'    => 'unlimited',
            'delivery_type' => Listing::DELIVERY_TYPE_SERVIZIO,
        ], $override);
    }

    private function makeCategoria(string $slug = 'informatica'): ListingCategory
    {
        return ListingCategory::create([
            'parent_id'  => null,
            'slug'       => $slug,
            'name'       => 'Informatica',
            'is_active'  => true,
            'sort_order' => 0,
        ]);
    }

    private function makeAdmin(): User
    {
        $user = User::create([
            'name'                => 'Amministratore',
            'email'               => 'admin-' . Str::random(8) . '@test.test',
            'password'            => 'secret123',
            'account_holder_type' => 'private',
            'company_id'          => null,
            'role'                => 'admin',
            'is_active'           => true,
            'is_super_admin'      => true,
        ]);

        $user->forceFill([
            'email_verified_at'  => now(),
            'contract_signed_at' => now(),
        ])->save();

        return $user->fresh();
    }

    /** Un'azienda in regola ma senza conto business: non puo' incassare. */
    private function makeCompanySenzaConto(): Company
    {
        $slug = 'no-conto-' . Str::random(6);

        return Company::create([
            'name'          => 'Senza Conto ' . Str::random(4),
            'slug'          => $slug,
            'email'         => $slug . '@test.test',
            'status'        => 'active',
            'kyc_status'    => 'approved',
            'currency_code' => 'KY',
            'sector'        => 'informatica',
            'description'   => 'Azienda di test senza conto business.',
        ]);
    }
}
