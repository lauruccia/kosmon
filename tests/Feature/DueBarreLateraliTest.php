<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\Listing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * DUE BARRE LATERALI, UNA ALLA VOLTA — 08/09/2026
 *
 * Il negozio ha due colonne laterali: il menu del portale (272px) e i filtri
 * del catalogo (264px). Se stanno larghe tutte e due, su una finestra da 1440
 * si prendono 536px — il 39% — e il catalogo scende da cinque colonne a
 * quattro. La regola e' che una delle due stia stretta: il menu si riduce a
 * icone, i filtri si aprono e si chiudono.
 *
 * Questi test sorvegliano le quattro cose che si rompono per prime:
 *  1. i filtri devono restare RAGGIUNGIBILI anche senza JavaScript;
 *  2. lo stato delle due barre deve essere applicato PRIMA del primo paint,
 *     altrimenti ogni pagina si apre larga e si stringe di scatto;
 *  3. la larghezza del menu deve stare in UNA variabile (era cablata in tre
 *     punti, e tre numeri da cambiare insieme sono tre occasioni di sbagliare);
 *  4. il menu a icone NON deve applicarsi sotto i 769px, dove il menu e' gia'
 *     un pannello fuori schermo con l'hamburger.
 *
 * Vedi SHOP_COME_WOODMART_2026-09-08.md, capitolo 8.
 */
class DueBarreLateraliTest extends TestCase
{
    use RefreshDatabase;

    // ── I filtri sono una colonna ───────────────────────────────────────────

    public function test_i_filtri_stanno_in_una_colonna_e_non_piu_nella_striscia(): void
    {
        $html = $this->actingAs($this->venditore())->get(route('portal.shop'))->assertOk()->getContent();

        $this->assertStringContainsString('id="shop-filters"', $html, 'Manca la colonna dei filtri.');
        $this->assertStringContainsString('class="shop-layout"', $html, 'Manca il contenitore a due colonne.');

        $colonna = $this->ritaglia($html, '<aside class="shop-filters"', '</aside>');

        foreach (['name="q"', 'name="category"', 'name="ky_filter"'] as $campo) {
            $this->assertStringContainsString(
                $campo,
                $colonna,
                "Il filtro {$campo} e' rimasto fuori dalla colonna: se torna nella striscia orizzontale, "
                .'con attributi e prezzo in arrivo diventa un muro di controlli sopra il primo prodotto.'
            );
        }
    }

    public function test_i_filtri_funzionano_anche_a_javascript_spento(): void
    {
        $html = $this->actingAs($this->venditore())->get(route('portal.shop'))->assertOk()->getContent();

        // La colonna si chiude con una classe su <html> messa dal JavaScript.
        // Nell'HTML servito non deve esserci: a JavaScript spento i filtri
        // devono essere li', aperti, come il mini-carrello.
        $this->assertStringNotContainsString('<html class="shop-filters-closed"', $html);
        $this->assertMatchesRegularExpression(
            '/<form[^>]+method="GET"[^>]*>/i',
            $this->ritaglia($html, '<aside class="shop-filters"', '</aside>'),
            'I filtri devono essere un form GET vero, non un pannello che vive di JavaScript.'
        );

        // E il filtro deve filtrare davvero, senza una riga di JavaScript.
        [$azienda] = $this->venditoreConAzienda();
        $this->makeListing($azienda, 'Sedia impagliata');
        $this->makeListing($azienda, 'Miele di castagno');

        $risultato = $this->actingAs($this->venditore())
            ->get(route('portal.shop', ['q' => 'castagno']))->assertOk()->getContent();

        $this->assertStringContainsString('Miele di castagno', $risultato);
        $this->assertStringNotContainsString('Sedia impagliata', $risultato);
    }

    public function test_il_bottone_apre_la_colonna_e_ne_dichiara_il_bersaglio(): void
    {
        $html = $this->actingAs($this->venditore())->get(route('portal.shop'))->assertOk()->getContent();

        $this->assertStringContainsString('toggleShopFilters(true)', $html, 'Manca il bottone che riapre i filtri.');
        $this->assertStringContainsString('aria-controls="shop-filters"', $html, "Il bottone non dice cosa apre.");
    }

    // ── Il menu a icone ─────────────────────────────────────────────────────

    public function test_il_bottone_del_menu_dichiara_il_suo_stato(): void
    {
        $html = $this->actingAs($this->venditore())->get(route('portal.dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('id="nav-rail-btn"', $html, 'Manca il bottone del menu a icone.');
        $this->assertStringContainsString('aria-controls="portal-sidebar"', $html);
        $this->assertStringContainsString('id="portal-sidebar"', $html, 'aria-controls punta a un id che non esiste.');
        $this->assertStringContainsString('aria-expanded', $html);
    }

    public function test_lo_stato_delle_due_barre_arriva_prima_del_primo_paint(): void
    {
        $html = $this->actingAs($this->venditore())->get(route('portal.shop'))->assertOk()->getContent();

        $fineTesta = strpos($html, '</head>');
        $this->assertNotFalse($fineTesta);

        foreach (["'km-nav'", "'km-shop-filters'"] as $chiave) {
            $posizione = strpos($html, $chiave);
            $this->assertNotFalse($posizione, "Lo stato {$chiave} non viene letto affatto.");
            $this->assertLessThan(
                $fineTesta,
                $posizione,
                "Lo stato {$chiave} viene applicato dopo </head>: ogni caricamento mostrerebbe la barra "
                .'larga che si stringe di scatto.'
            );
        }
    }

    public function test_la_larghezza_del_menu_sta_in_una_variabile_sola(): void
    {
        $layout = file_get_contents(resource_path('views/layouts/portal.blade.php'));

        $this->assertStringContainsString(':root { --nav-w: 272px; }', $layout);
        $this->assertStringContainsString('grid-template-columns: var(--nav-w) minmax(0, 1fr);', $layout);
        $this->assertStringNotContainsString(
            'grid-template-columns: 272px',
            $layout,
            'La larghezza del menu e\' tornata a essere scritta a mano: cambiarne una su tre non cambia niente.'
        );

        // Le custom property si risolvono per elemento: se --nav-w venisse
        // ridefinita su .app-shell, quella vincerebbe SEMPRE su html.nav-rail
        // e il menu a icone smetterebbe di funzionare senza un errore.
        $this->assertStringNotContainsString('.app-shell { --nav-w', $layout);
    }

    public function test_il_menu_a_icone_non_si_applica_sul_telefono(): void
    {
        $layout = file_get_contents(resource_path('views/layouts/portal.blade.php'));

        $this->assertStringContainsString(
            "@media (min-width: 769px) {\n            html.nav-rail { --nav-w: 68px; }",
            $layout,
            'Il menu a icone deve stare dentro la @media 769: sotto, il menu e\' gia\' un pannello '
            .'con l\'hamburger, e un rail sopra un pannello non vuol dire niente.'
        );
    }

    public function test_il_bottone_del_menu_e_visibile_su_desktop(): void
    {
        // TROVATO A MANI IN PASTA L'08/09, e nessun test lo avrebbe preso:
        // il bottone c'era nell'HTML, aveva l'aria giusta, rispondeva ai
        // click da codice — ma `.rail-btn { display: none; }` stava DOPO la
        // @media che lo accende e, a parita' di specificita', vinceva lui.
        // Sullo schermo non c'era nessun bottone da premere.
        $layout = file_get_contents(resource_path('views/layouts/portal.blade.php'));

        $spento = strpos($layout, '.rail-btn { display: none; }');
        $media  = strpos($layout, "@media (min-width: 769px) {\n            html.nav-rail");

        $this->assertNotFalse($spento, 'Manca il default del bottone.');
        $this->assertNotFalse($media);
        $this->assertLessThan(
            $media,
            $spento,
            'Il default del bottone deve stare PRIMA della @media che lo accende: scritto dopo, '
            .'a parita\' di specificita\' vince lui e su desktop il bottone sparisce senza un errore.'
        );
    }

    public function test_il_menu_a_icone_non_trabocca(): void
    {
        // .sidebar-inner e' una griglia: la colonna e' larga quanto il
        // contenuto piu' largo. Scesa a 68px, il contenuto restava a 140 e le
        // icone uscivano mezze dal bordo.
        $layout = file_get_contents(resource_path('views/layouts/portal.blade.php'));

        $this->assertStringContainsString('html.nav-rail .sidebar-inner,', $layout);
        $this->assertStringContainsString('{ grid-template-columns: minmax(0, 1fr); }', $layout);
        $this->assertStringContainsString('html.nav-rail .sidebar { padding: 20px 8px; overflow-x: hidden; }', $layout);
    }

    public function test_la_scelta_dell_utente_sul_menu_vince_sull_automatismo(): void
    {
        $layout = file_get_contents(resource_path('views/layouts/portal.blade.php'));

        $this->assertStringContainsString('window.__navRailByUser = true;', $layout);
        $this->assertStringContainsString('!window.__navRailByUser', $layout,
            'La regola dell\'una alla volta deve fermarsi davanti a una scelta esplicita: '
            .'un menu che si richiude dopo che l\'utente l\'ha aperto e\' un menu rotto.');
    }

    // ── Il catalogo ─────────────────────────────────────────────────────────

    public function test_il_catalogo_conta_le_colonne_sullo_spazio_e_non_sulla_finestra(): void
    {
        $layout = file_get_contents(resource_path('views/layouts/portal.blade.php'));

        $this->assertStringContainsString(
            '.catalog-grid { grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 14px; }',
            $layout
        );
        $this->assertStringNotContainsString(
            '.catalog-grid { grid-template-columns: repeat(5, minmax(0, 1fr));',
            $layout,
            'Con cinque colonne fisse, la stessa finestra da 1440 sbaglia in due casi su tre: '
            .'il catalogo ha 856, 1060 o 1344 pixel a seconda di come stanno le due barre.'
        );
    }

    public function test_il_catalogo_pagina_a_venti(): void
    {
        [$azienda] = $this->venditoreConAzienda();

        for ($i = 1; $i <= 21; $i++) {
            $this->makeListing($azienda, sprintf('Prodotto %02d', $i));
        }

        $html = $this->actingAs($this->venditore())->get(route('portal.shop'))->assertOk()->getContent();

        $this->assertSame(
            20,
            substr_count($html, 'class="catalog-card'),
            '20 e non 15: con le colonne che variano (6/5/4/2) e\' il numero che riempie le righe '
            .'nei casi che capitano su desktop.'
        );
        $this->assertStringContainsString('page=2', $html, 'Il ventunesimo prodotto deve finire in seconda pagina.');
    }

    // ── Il foglio di stile ──────────────────────────────────────────────────

    public function test_la_larghezza_dei_filtri_e_un_token_e_non_un_numero_sparso(): void
    {
        $css = file_get_contents(public_path('assets/css/shop.css'));

        $this->assertStringContainsString('--shop-filter-w: 264px;', $css);
        $this->assertStringContainsString('width: var(--shop-filter-w)', $css);
        $this->assertStringContainsString('html.shop-filters-closed .shop-filters { display: none; }', $css);
    }

    // ── Aiuti ───────────────────────────────────────────────────────────────

    private function ritaglia(string $html, string $da, string $a): string
    {
        $inizio = strpos($html, $da);
        $this->assertNotFalse($inizio, "Non trovo «{$da}» nella pagina.");
        $fine = strpos($html, $a, $inizio);
        $this->assertNotFalse($fine, "Non trovo «{$a}» dopo «{$da}».");

        return substr($html, $inizio, $fine - $inizio);
    }

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

        $company = $this->makeCompany('Bottega delle due barre');

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
        $autore = User::query()->where('company_id', $company->id)->value('id');

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
