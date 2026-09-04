<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\Listing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * LA BASE DEL TEMA DELLO SHOP — fase 0, 02/09/2026
 *
 * Prima di oggi ogni vista dello shop si portava dentro il proprio blocco
 * <style>: la card prodotto era copiata quattro volte e ~290 colori erano
 * scritti a mano invece che presi dai token, con il risultato che in modalita'
 * scura si leggeva testo scuro su fondo scuro su otto viste su dodici.
 *
 * Questi test non guardano com'e' bello il negozio: guardano che la base
 * regga. Sono la rete che impedisce di tornare indietro senza accorgersene —
 * il giorno in cui qualcuno riaprira' un <style> dentro una vista o
 * riscrivera' un #10263d a mano, qui si accende una luce rossa.
 *
 * Vedi ANALISI_SHOP_AVANZATO_2026-09-02.md, capitolo 3.
 */
class TemaShopFase0Test extends TestCase
{
    use RefreshDatabase;

    /**
     * I colori che NON devono piu' comparire nel corpo delle pagine dello shop.
     * Sono quelli che non avevano nessun corrispettivo in tema scuro: testo
     * quasi nero, fondi bianchi e grigi chiari cablati a mano.
     */
    private const COLORI_PROIBITI = ['#10263d', '#334155', '#94a3b8', '#f1f5f9', '#eef2f7', '#0c4a86'];

    public static function paginaProvider(): array
    {
        return [
            'catalogo'         => ['portal.shop'],
            'i miei prodotti'  => ['portal.shop.mine'],
            'offerte'          => ['portal.shop.offers'],
            'carrello'         => ['portal.cart'],
            'i miei ordini'    => ['portal.orders.index'],
            'vendite'          => ['portal.sales.index'],
        ];
    }

    #[DataProvider('paginaProvider')]
    public function test_ogni_pagina_dello_shop_carica_il_foglio_di_stile_condiviso(string $rotta): void
    {
        $html = $this->actingAs($this->venditore())->get(route($rotta))->assertOk()->getContent();

        $this->assertStringContainsString(
            'assets/css/shop.css',
            $html,
            "La pagina {$rotta} non carica shop.css: manca <x-shop.styles /> in cima alla vista."
        );
    }

    #[DataProvider('paginaProvider')]
    public function test_nessuna_pagina_dello_shop_si_porta_dietro_il_proprio_css(string $rotta): void
    {
        $html = $this->actingAs($this->venditore())->get(route($rotta))->assertOk()->getContent();

        // Queste regole vivono in public/assets/css/shop.css e in nessun altro
        // posto. Se ricompaiono nell'HTML, qualcuno ha riaperto un <style>
        // dentro una vista e la card prodotto sta tornando a duplicarsi.
        foreach (['.catalog-card {', '.product-title {', '.product-media {'] as $regola) {
            $this->assertStringNotContainsString(
                $regola,
                $html,
                "La pagina {$rotta} ridefinisce «{$regola}» per conto suo: quel CSS sta in shop.css."
            );
        }
    }

    public function test_nessun_colore_cablato_sopravvive_nelle_viste_dello_shop(): void
    {
        // Controllo sui FILE, non sull'HTML: il layout del portale ha 3.000
        // righe di CSS suo e stampa colori a mano anche nel corpo pagina —
        // guardare l'HTML finito vorrebbe dire misurare anche quelli, che non
        // sono il perimetro di questa fase.
        $viste = [
            'shop', 'shop-show', 'shop-mine', 'shop-offers', 'shop-create',
            'shop-variants', 'shop-order-pay', 'cart', 'checkout',
            'checkout-thanks', 'orders', 'order-show', 'sales',
            'shipping-addresses', 'payment-gateways',
        ];

        foreach ($viste as $vista) {
            $file = resource_path("views/portal/{$vista}.blade.php");
            // I commenti Blade non finiscono mai in pagina: se uno CITA un
            // colore vecchio per spiegare perche' e' stato tolto, va bene cosi'.
            $html = preg_replace('/\{\{--.*?--\}\}/s', '', file_get_contents($file));

            foreach (self::COLORI_PROIBITI as $colore) {
                $this->assertStringNotContainsString(
                    $colore,
                    $html,
                    "portal/{$vista}.blade.php contiene ancora il colore {$colore} scritto a mano: "
                    . 'va sostituito con la variabile corrispondente (vedi i token in layouts/portal.blade.php).'
                );
            }
        }

        // La pastiglia di stato ordine e' l'unico pezzo che le tre pagine
        // ordini condividono: se torna a colorarsi da sola, tornano a essere
        // illeggibili tutte e tre insieme.
        $badge = file_get_contents(resource_path('views/portal/partials/order-status-badge.blade.php'));
        $this->assertDoesNotMatchRegularExpression('/#[0-9a-fA-F]{3,8}\b/', $badge);
    }

    public function test_la_card_prodotto_esce_dal_componente_unico(): void
    {
        [$azienda] = $this->venditoreConAzienda();
        $altra     = $this->makeCompany('Panificio Test');
        $this->makeListing($altra, 'Pane di segale');

        $html = $this->actingAs(User::where('company_id', $azienda->id)->first())
            ->get(route('portal.shop'))->assertOk()->getContent();

        // Le classi che il componente x-shop.product-card stampa sempre.
        $this->assertStringContainsString('class="catalog-card', $html);
        $this->assertStringContainsString('product-price', $html);
        $this->assertStringContainsString('Pane di segale', $html);
    }

    public function test_il_bottone_dell_acquisto_ha_il_suo_colore_e_non_quello_dei_filtri(): void
    {
        [$azienda]  = $this->venditoreConAzienda();
        $compratore = User::where('company_id', $azienda->id)->first();
        $altra      = $this->makeCompany('Ferramenta Test');
        $this->makeListing($altra, 'Martello');

        $html = $this->actingAs($compratore)->get(route('portal.shop'))->assertOk()->getContent();

        // "Aggiungi al carrello" e' l'unica azione che porta soldi: deve avere
        // la classe .cta.buy, che nel foglio di stile e' arancio. Se torna a
        // essere un .cta e basta, ridiventa indistinguibile da "Filtra".
        $this->assertStringContainsString('class="cta buy"', $html);
    }

    public function test_il_carattere_del_portale_e_servito_da_casa_nostra(): void
    {
        // La CSP dichiara font-src 'self' data:. Un @font-face che punta a
        // Google Fonts verrebbe bloccato in silenzio e il portale tornerebbe ai
        // font di sistema senza che nessuno se ne accorga.
        $html = $this->actingAs($this->venditore())->get(route('portal.shop'))->assertOk()->getContent();

        $this->assertStringContainsString('/fonts/inter-latin-wght-normal.woff2', $html);
        $this->assertStringNotContainsString('fonts.googleapis.com', $html);
        $this->assertFileExists(public_path('fonts/inter-latin-wght-normal.woff2'));
    }

    public function test_i_token_del_commercio_esistono_in_entrambi_i_temi(): void
    {
        $layout = file_get_contents(resource_path('views/layouts/portal.blade.php'));

        // Ogni token nuovo dev'essere dichiarato DUE volte: una in :root (tema
        // chiaro) e una in [data-theme="dark"]. Uno solo dei due significa un
        // colore che in un tema non esiste — cioe' il bug che questa fase
        // e' venuta a chiudere. Le eccezioni sono i token --on-dark-*, che
        // stanno sopra superfici scure in entrambi i temi e per definizione
        // non cambiano.
        foreach (['--buy', '--buy-strong', '--buy-soft', '--buy-on', '--buy-line',
                  '--sale', '--sale-soft', '--sale-on',
                  '--in-stock', '--in-stock-soft', '--in-stock-on',
                  '--success-line', '--warning-line', '--danger-line', '--danger-on',
                  '--info', '--info-soft', '--info-line', '--accent-line',
                  '--media-veil'] as $token) {
            $this->assertSame(
                2,
                substr_count($layout, $token.':'),
                "Il token {$token} dev'essere dichiarato due volte (tema chiaro e tema scuro)."
            );
        }
    }

    public function test_il_foglio_di_stile_dello_shop_non_contiene_colori_scritti_a_mano(): void
    {
        $css = file_get_contents(public_path('assets/css/shop.css'));

        // Le frecce delle select sono SVG in data-uri: dentro un url() le
        // variabili CSS non si risolvono, quindi quei due colori sono l'unica
        // eccezione ammessa, insieme al bianco sopra il velo scuro.
        $css = preg_replace('/\/\*.*?\*\//s', '', $css);
        $css = preg_replace('/url\("data:image\/svg\+xml[^"]*"\)/', '', $css);

        preg_match_all('/#[0-9a-fA-F]{3,8}\b/', $css, $trovati);
        $residui = array_values(array_diff(array_unique($trovati[0]), ['#fff']));

        $this->assertSame(
            [],
            $residui,
            'shop.css contiene colori scritti a mano invece dei token: '.implode(', ', $residui)
        );
    }

    // ── Aiuti ───────────────────────────────────────────────────────────────

    private function venditore(): User
    {
        [, $user] = $this->venditoreConAzienda();

        return $user;
    }

    /** @return array{0: Company, 1: User} */
    private function venditoreConAzienda(): array
    {
        static $memo = null;
        if ($memo && Company::find($memo[0]->id)) {
            return $memo;
        }

        $company = $this->makeCompany('Bottega di prova');

        $user = User::create([
            'name'                => 'Titolare',
            'email'               => 'titolare-'.Str::random(8).'@test.test',
            'password'            => 'secret123',
            'account_holder_type' => 'company',
            'company_id'          => $company->id,
            'role'                => 'owner',
            'is_active'           => true,
            'is_super_admin'      => false,
            'email_verified_at'   => now(),
            'contract_signed_at'  => now(),
        ]);

        return $memo = [$company, $user->fresh()];
    }

    private function makeCompany(string $nome): Company
    {
        $slug = Str::slug($nome).'-'.Str::random(6);

        $company = Company::create([
            'name'          => $nome,
            'slug'          => $slug,
            'email'         => $slug.'@test.test',
            'status'        => 'active',
            'kyc_status'    => 'approved',
            'currency_code' => 'KY',
            'sector'        => 'informatica',
            'description'   => 'Azienda di test',
        ]);

        Account::create([
            'company_id'        => $company->id,
            'owner_type'        => 'company',
            'type'              => 'member',
            'status'            => 'active',
            'available_balance' => 500000,
            'is_system_account' => false,
        ]);

        return $company;
    }

    private function makeListing(Company $company, string $titolo): Listing
    {
        $autore = User::query()->where('company_id', $company->id)->value('id')
            ?? User::create([
                'name'                => 'Titolare '.$titolo,
                'email'               => 'a-'.Str::random(8).'@test.test',
                'password'            => 'secret123',
                'account_holder_type' => 'company',
                'company_id'          => $company->id,
                'role'                => 'owner',
                'is_active'           => true,
                'email_verified_at'   => now(),
                'contract_signed_at'  => now(),
            ])->id;

        return Listing::create([
            'company_id'         => $company->id,
            'created_by_user_id' => $autore,
            'title'              => $titolo,
            'description'        => 'Descrizione di '.$titolo,
            'category'           => 'informatica',
            'price_ky'           => 5000,
            'ky_percentage'      => 100,
            'status'             => 'active',
            'delivery_type'      => Listing::DELIVERY_TYPE_SERVIZIO,
        ]);
    }
}
