<?php

namespace Tests\Feature;

use App\Models\CreditLimit;
use App\Models\Listing;
use App\Models\ListingOffer;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsShopScenarios;
use Tests\TestCase;

/**
 * PRESTAZIONI DELLO SHOP — il resto del blocco 4 (27/08/2026).
 *
 * Dopo le miniature e gli indici, i quattro punti minori dell'audit. Uno dei
 * quattro pero' non era affatto minore, e si e' visto solo leggendo il codice:
 *
 * **LE COMBINAZIONI SI BLOCCAVANO IN ORDINE CASUALE.** In cassa i prodotti
 * venivano bloccati tutti insieme e ordinati per id — giusto — ma le taglie
 * una per volta, dentro il ciclo, nell'ordine in cui capitavano nel carrello.
 * Due clienti che comprano le stesse due taglie in ordine opposto si bloccano
 * a vicenda: e' la definizione di deadlock, e sarebbe uscita fuori solo sotto
 * carico, cioe' nel giorno peggiore possibile.
 *
 * I test qui sotto contano le QUERY. Non e' una misura di velocita' — quella
 * non si scrive con phpunit — ma di forma: se un giorno qualcuno rimette una
 * query dentro un ciclo, il numero cambia e il test cade.
 */
class PrestazioniShopTest extends TestCase
{
    use BuildsShopScenarios;
    use RefreshDatabase;

    // =========================================================================
    // La cassa: un solo blocco, in un ordine solo
    // =========================================================================

    public function test_le_combinazioni_si_bloccano_con_una_query_sola(): void
    {
        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 500000);
        [$company] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 1000, kyPercentage: 100);
        $valori  = $this->makeAttributo('Taglia', ['S', 'M', 'L']);

        $varianti = [
            $this->makeVariante($listing, [$valori['S']], scorte: 10),
            $this->makeVariante($listing, [$valori['M']], scorte: 10),
            $this->makeVariante($listing, [$valori['L']], scorte: 10),
        ];

        $righe = array_map(fn ($v) => [
            'listing'  => $listing,
            'variant'  => $v,
            'quantity' => 1,
        ], $varianti);

        $query = $this->contaQuery(fn () => app(OrderService::class)->place(
            buyerAccount: $buyerAccount,
            user: $buyer,
            righe: $righe,
        ));

        $selectVarianti = collect($query)
            ->filter(fn ($sql) => str_contains($sql, 'listing_variants')
                && str_starts_with(strtolower(trim($sql)), 'select'))
            ->count();

        // Una sola, per tutte e tre le taglie. Prima erano tre, ognuna dentro
        // il ciclo e con il lock gia' preso.
        $this->assertSame(1, $selectVarianti,
            'Le combinazioni devono essere bloccate tutte insieme, non una per riga.');
    }

    public function test_le_combinazioni_si_bloccano_in_ordine_crescente(): void
    {
        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 500000);
        [$company] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 1000, kyPercentage: 100);
        $valori  = $this->makeAttributo('Taglia', ['S', 'M']);

        $primaVariante  = $this->makeVariante($listing, [$valori['S']], scorte: 10);
        $secondaVariante = $this->makeVariante($listing, [$valori['M']], scorte: 10);

        // Nel carrello in ordine INVERSO: e' il caso che generava il deadlock,
        // perche' un altro cliente le avrebbe bloccate nell'altro verso.
        $righe = [
            ['listing' => $listing, 'variant' => $secondaVariante, 'quantity' => 1],
            ['listing' => $listing, 'variant' => $primaVariante,   'quantity' => 1],
        ];

        $query = $this->contaQuery(fn () => app(OrderService::class)->place(
            buyerAccount: $buyerAccount,
            user: $buyer,
            righe: $righe,
        ));

        // `for update` NON compare nel SQL sotto SQLite (il driver lo ignora),
        // quindi qui si guarda quello che si puo' guardare davvero: che la
        // lettura delle combinazioni sia UNA, su piu' id, e ORDINATA. E' la
        // forma che rende impossibile il deadlock; il lock vero lo mette il
        // database in produzione.
        $lettura = collect($query)->first(fn ($sql) => str_contains($sql, 'listing_variants')
            && str_starts_with(strtolower(trim($sql)), 'select')
            && str_contains(strtolower($sql), ' in ('));

        $this->assertNotNull($lettura, 'Le combinazioni vanno lette tutte insieme.');
        $this->assertStringContainsString('order by', strtolower($lettura),
            'Senza un ordine fisso due carrelli opposti si bloccano a vicenda.');
    }

    public function test_l_ordine_con_le_varianti_resta_corretto(): void
    {
        // Regressione: il modo di bloccare cambia, il risultato no.
        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 500000);
        [$company] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 1000, kyPercentage: 100);
        $valori  = $this->makeAttributo('Taglia', ['S', 'M']);
        $s = $this->makeVariante($listing, [$valori['S']], scorte: 4);
        $m = $this->makeVariante($listing, [$valori['M']], scorte: 4);

        $ordine = app(OrderService::class)->place(
            buyerAccount: $buyerAccount,
            user: $buyer,
            righe: [
                ['listing' => $listing, 'variant' => $s, 'quantity' => 2],
                ['listing' => $listing, 'variant' => $m, 'quantity' => 1],
            ],
        );

        $this->assertSame(2, $ordine->items()->count());
        $this->assertSame(2, (int) $s->fresh()->stock_quantity);
        $this->assertSame(3, (int) $m->fresh()->stock_quantity);
        $this->assertSame(3000, (int) $ordine->total_ky);
    }

    // =========================================================================
    // Il massimale: tre query, una volta sola
    // =========================================================================

    public function test_il_massimale_non_si_ricalcola_a_ogni_chiamata(): void
    {
        [, $account] = $this->makeBuyer(saldo: 100000);
        CreditLimit::create([
            'account_id'   => $account->id,
            'credit_limit' => 5000,
            'status'       => 'active',
            'approved_at'  => now(),
        ]);

        $primo = $this->contaQuery(fn () => $account->massimale());
        $altre = $this->contaQuery(function () use ($account) {
            $account->massimale();
            $account->massimale();
            $account->massimale();
        });

        $this->assertGreaterThan(0, count($primo));
        $this->assertCount(0, $altre, 'Le chiamate successive non devono toccare il database.');
    }

    public function test_il_saldo_disponibile_resta_vero_quando_il_saldo_cambia(): void
    {
        // La memoria e' solo sul massimale: il SALDO deve restare quello vero,
        // sempre, perche' su quello si decide se un pagamento passa.
        [, $account] = $this->makeBuyer(saldo: 100000);

        $this->assertSame(100000, $account->saldoDisponibile());

        $account->forceFill(['available_balance' => 40000])->save();

        $this->assertSame(40000, $account->saldoDisponibile());
    }

    public function test_ricaricare_il_conto_dimentica_anche_il_massimale(): void
    {
        [, $account] = $this->makeBuyer(saldo: 100000);
        $this->assertSame(0, $account->massimale());

        CreditLimit::create([
            'account_id'   => $account->id,
            'credit_limit' => 7000,
            'status'       => 'active',
            'approved_at'  => now(),
        ]);

        // Senza refresh il ricordo resta, ed e' voluto.
        $this->assertSame(0, $account->massimale());

        $account->refresh();

        $this->assertSame(7000, $account->massimale(),
            'refresh() deve restituire un saldo nuovo E un fido nuovo, non uno dei due.');
    }

    // =========================================================================
    // Le offerte: paginate e ordinate dal database
    // =========================================================================

    public function test_le_offerte_sono_paginate(): void
    {
        [$company] = $this->makeSeller();

        for ($i = 0; $i < 18; $i++) {
            $this->inOfferta($company, giorni: $i + 1);
        }

        [$buyer] = $this->makeBuyer(saldo: 1000);

        $risposta = $this->actingAs($buyer)->get(route('portal.shop.offers'))->assertOk();

        $this->assertSame(15, $risposta->viewData('listings')->count(),
            'Prima si caricavano TUTTE le offerte attive in memoria.');
        $this->assertSame(18, $risposta->viewData('listings')->total());
    }

    public function test_le_offerte_escono_in_ordine_di_scadenza(): void
    {
        [$company] = $this->makeSeller();
        $tardi = $this->inOfferta($company, giorni: 9, titolo: 'Scade tardi');
        $presto = $this->inOfferta($company, giorni: 2, titolo: 'Scade presto');

        [$buyer] = $this->makeBuyer(saldo: 1000);

        $elenco = $this->actingAs($buyer)->get(route('portal.shop.offers'))
            ->assertOk()
            ->viewData('listings');

        $this->assertSame($presto->id, $elenco->first()->id,
            'Quella che scade prima va mostrata per prima.');
    }

    // =========================================================================
    // Il contatore visite
    // =========================================================================

    public function test_il_contatore_visite_non_sta_piu_nella_richiesta(): void
    {
        // ONESTA' SUL LIMITE DI QUESTO TEST: in produzione `afterResponse`
        // fa girare la UPDATE dopo che la pagina e' partita, ma nei test
        // Laravel chiude la richiesta in modo sincrono dentro `get()` — quindi
        // contare le query non distingue i due casi. Si controlla allora la
        // FORMA: che il contatore sia rimandato e non piu' incrementato in
        // linea. Se qualcuno rimettesse `$listing->increment('views_count')`
        // nel corpo dell'azione, questo test cade.
        $sorgente = file_get_contents(app_path('Http/Controllers/ListingController.php'));

        $this->assertStringContainsString('->afterResponse()', $sorgente);
        $this->assertStringNotContainsString("\$listing->increment('views_count')", $sorgente,
            'Il contatore non deve tornare a incrementarsi dentro la richiesta.');
    }

    public function test_il_contatore_visite_sale_comunque(): void
    {
        [$company] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 2000, kyPercentage: 100);
        [$buyer] = $this->makeBuyer(saldo: 1000);

        $partenza = (int) $listing->views_count;

        $this->actingAs($buyer)->get(route('portal.shop.show', $listing))->assertOk();

        // Spostare la scrittura fuori dall'attesa non vuol dire buttarla via.
        $this->assertSame($partenza + 1, (int) $listing->fresh()->views_count);
    }

    // =========================================================================
    // Impalcatura
    // =========================================================================

    /**
     * Le query eseguite mentre gira il pezzo di codice dato.
     *
     * @return array<int, string>
     */
    private function contaQuery(\Closure $cosa): array
    {
        $query = [];

        DB::listen(function ($evento) use (&$query) {
            $query[] = $evento->sql;
        });

        $cosa();

        DB::getEventDispatcher()->forget(\Illuminate\Database\Events\QueryExecuted::class);

        return $query;
    }

    private function inOfferta($company, int $giorni, ?string $titolo = null): Listing
    {
        $listing = $this->makeListing($company, prezzo: 5000, kyPercentage: 100, extra: array_filter([
            'title' => $titolo,
        ]));

        ListingOffer::create([
            'listing_id'              => $listing->id,
            'created_by_user_id'      => null,
            'full_price_ky_snapshot'  => 5000,
            'offer_price_ky'          => 3000,
            'offer_ky_percentage'     => 100,
            'expires_at'              => now()->addDays($giorni),
        ]);

        return $listing;
    }
}
