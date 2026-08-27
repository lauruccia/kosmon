<?php

namespace Tests\Feature;

use App\Models\Listing;
use App\Models\ListingVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsShopScenarios;
use Tests\TestCase;

/**
 * BLOCCO 5 — gli attriti che si sentono a ogni acquisto (27/08/2026).
 *
 * Tre bug veri dell'audit del 26/08, piu' due fastidi che costano vendite:
 *
 *   1. **Il badge diceva "Disponibile" anche a magazzino vuoto.** Su un
 *      prodotto variabile le scorte stanno sulle combinazioni e il prodotto
 *      padre non ne ha: `stock_label` rispondeva sempre "Disponibile", persino
 *      dentro una pastiglia ROSSA quando erano finite tutte le taglie.
 *   2. **Il bottone del catalogo prometteva un acquisto e apriva una pagina.**
 *      Diceva "Acquista ora" ma era un link alla scheda.
 *   3. **Il doppio banner**: la stessa frase stampata due volte dopo ogni
 *      aggiunta al carrello, una dal layout e una dalla pagina.
 *   4. **I campi indirizzo senza `autocomplete`**: sul telefono il
 *      compilatore automatico non parte e in cassa si riscrive tutto a mano.
 *   5. **Gli errori solo in cima**: si sapeva che mancava qualcosa, non dove.
 */
class AttritiAcquistoTest extends TestCase
{
    use BuildsShopScenarios;
    use RefreshDatabase;

    // =========================================================================
    // Il badge delle scorte
    // =========================================================================

    public function test_con_tutte_le_taglie_esaurite_il_badge_dice_esaurito(): void
    {
        [$listing] = $this->prodottoConTaglie(scorte: [0, 0]);
        [$buyer] = $this->makeBuyer(saldo: 100000);

        $html = $this->actingAs($buyer)->get(route('portal.shop.show', $listing))
            ->assertOk()
            ->getContent();

        $badge = $this->contenutoDelBadge($html);

        $this->assertSame('Esaurito', $badge,
            'Con tutte le combinazioni finite il badge non puo\' dire "Disponibile": era rosso e diceva il contrario.');
    }

    public function test_con_almeno_una_taglia_il_badge_dice_disponibile(): void
    {
        [$listing] = $this->prodottoConTaglie(scorte: [0, 3]);
        [$buyer] = $this->makeBuyer(saldo: 100000);

        $html = $this->actingAs($buyer)->get(route('portal.shop.show', $listing))
            ->assertOk()
            ->getContent();

        $this->assertSame('Disponibile', $this->contenutoDelBadge($html));
    }

    public function test_ogni_taglia_si_porta_dietro_quante_ne_restano(): void
    {
        [$listing] = $this->prodottoConTaglie(scorte: [1, 5, 0]);
        [$buyer] = $this->makeBuyer(saldo: 100000);

        $html = $this->actingAs($buyer)->get(route('portal.shop.show', $listing))
            ->assertOk()
            ->getContent();

        // Sono i dati che il JavaScript usa per riscrivere il badge quando si
        // sceglie: senza, il badge resterebbe fermo su quello del padre.
        $this->assertStringContainsString('data-scorte="Ultimo pezzo"', $html);
        $this->assertStringContainsString('data-scorte="5 disponibili"', $html);
        $this->assertStringContainsString('data-scorte="Esaurita"', $html);
        $this->assertStringContainsString('data-disponibile="0"', $html);

        // E il pezzo che li usa: senza questa riga i dati sarebbero li' e il
        // badge resterebbe comunque fermo su quello del prodotto padre.
        $this->assertStringContainsString('scorte.textContent = etichetta', $html);
    }

    public function test_anche_il_menu_a_tendina_porta_le_scorte(): void
    {
        // Oltre le dodici combinazioni il selettore non e' piu' a pulsanti ma
        // a tendina: e' un ramo di Blade diverso, con i suoi attributi, e
        // senza un test suo puo' restare indietro senza che si veda.
        [$listing] = $this->prodottoConTaglie(scorte: array_fill(0, 13, 2));
        [$buyer] = $this->makeBuyer(saldo: 100000);

        $html = $this->actingAs($buyer)->get(route('portal.shop.show', $listing))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('<select', $html);
        $this->assertStringContainsString('data-scorte="2 disponibili"', $html);
    }

    public function test_il_prodotto_semplice_continua_a_dire_quante_ne_restano(): void
    {
        // Regressione: il prodotto NON variabile ha scorte proprie, e il badge
        // deve continuare a raccontarle come prima.
        [$company] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 2000, kyPercentage: 100, extra: [
            'stock_quantity' => 1,
        ]);
        [$buyer] = $this->makeBuyer(saldo: 100000);

        $html = $this->actingAs($buyer)->get(route('portal.shop.show', $listing))
            ->assertOk()
            ->getContent();

        $this->assertSame('Ultimo pezzo disponibile', $this->contenutoDelBadge($html));
    }

    public function test_l_etichetta_della_combinazione_dice_il_vero(): void
    {
        [$listing, $varianti] = $this->prodottoConTaglie(scorte: [0, 1, 4, null]);

        $this->assertSame('Esaurita', $varianti[0]->stock_label);
        $this->assertSame('Ultimo pezzo', $varianti[1]->stock_label);
        $this->assertSame('4 disponibili', $varianti[2]->stock_label);
        $this->assertSame('Disponibile', $varianti[3]->stock_label);
    }

    // =========================================================================
    // Il bottone che non deve mentire
    // =========================================================================

    public function test_il_catalogo_non_promette_un_acquisto_che_non_fa(): void
    {
        [$company] = $this->makeSeller();
        $this->makeListing($company, prezzo: 2000, kyPercentage: 100, extra: ['title' => 'Tavolo di noce']);
        [$buyer] = $this->makeBuyer(saldo: 100000);

        $html = $this->actingAs($buyer)->get(route('portal.shop'))->assertOk()->getContent();

        // Dal 27/08 la card di un prodotto semplice e disponibile l'acquisto
        // lo FA davvero: "Aggiungi al carrello" mette nel carrello senza
        // ricaricare la pagina. Il divieto invece resta identico, ed e' la
        // parte che conta: nessun bottone puo' dire "Acquista ora" e limitarsi
        // ad aprire un'altra pagina.
        $this->assertStringContainsString('Aggiungi al carrello', $html);
        $this->assertStringNotContainsString('>Acquista ora</a>', $html);
    }

    public function test_dove_si_compra_davvero_il_bottone_dice_ancora_acquista(): void
    {
        [$company] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 2000, kyPercentage: 100);
        [$buyer] = $this->makeBuyer(saldo: 100000);

        $this->actingAs($buyer)->get(route('portal.shop.show', $listing))
            ->assertOk()
            ->assertSee('Acquista ora');
    }

    public function test_nemmeno_la_fascia_in_primo_piano_promette_l_acquisto(): void
    {
        // La fascia in cima e' un blocco di Blade suo, con il suo bottone:
        // una modifica alla griglia puo' lasciarla indietro, e si vede prima.
        [$company] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 2000, kyPercentage: 100, extra: [
            'title' => 'Poltrona in primo piano',
        ]);
        $listing->forceFill(['featured' => true])->save();
        [$buyer] = $this->makeBuyer(saldo: 100000);

        $this->actingAs($buyer)->get(route('portal.shop'))
            ->assertOk()
            ->assertSee('Prodotti in primo piano')
            ->assertDontSee('>Acquista ora</a>', false);
    }

    // =========================================================================
    // Il doppio banner
    // =========================================================================

    public function test_un_avviso_si_legge_una_volta_sola(): void
    {
        [$company] = $this->makeSeller();
        $this->makeListing($company, prezzo: 2000, kyPercentage: 100);
        [$buyer] = $this->makeBuyer(saldo: 100000);

        $html = $this->actingAs($buyer)
            ->withSession(['portal_success' => 'Prodotto aggiunto al carrello.'])
            ->get(route('portal.shop'))
            ->assertOk()
            ->getContent();

        $this->assertSame(1, substr_count($html, 'Prodotto aggiunto al carrello.'),
            'Lo stesso avviso veniva stampato dal layout E dalla pagina.');
    }

    // =========================================================================
    // I campi dell'indirizzo
    // =========================================================================

    public function test_i_campi_indirizzo_aiutano_il_telefono_a_compilarli(): void
    {
        [$buyer] = $this->makeBuyer(saldo: 100000);

        $html = $this->actingAs($buyer)->get(route('portal.shipping-addresses.index'))
            ->assertOk()
            ->getContent();

        // Senza questi, il compilatore automatico del telefono non parte e in
        // cassa l'indirizzo va riscritto a mano tutto.
        $this->assertStringContainsString('autocomplete="name"', $html);
        $this->assertStringContainsString('autocomplete="street-address"', $html);
        $this->assertStringContainsString('autocomplete="postal-code"', $html);
        $this->assertStringContainsString('autocomplete="address-level2"', $html);
        $this->assertStringContainsString('inputmode="numeric"', $html);
    }

    public function test_i_quattro_campi_obbligatori_lo_dichiarano(): void
    {
        [$buyer] = $this->makeBuyer(saldo: 100000);

        $html = $this->actingAs($buyer)->get(route('portal.shipping-addresses.index'))
            ->assertOk()
            ->getContent();

        foreach (['recipient_name', 'address', 'postal_code', 'city'] as $campo) {
            $this->assertMatchesRegularExpression(
                '/name="' . $campo . '"[^>]*required/s',
                $html,
                "Il campo {$campo} e' obbligatorio per il server ma non lo dichiarava al browser."
            );
        }
    }

    public function test_l_errore_compare_sotto_il_campo_che_lo_riguarda(): void
    {
        [$buyer] = $this->makeBuyer(saldo: 100000);

        $this->actingAs($buyer)
            ->from(route('portal.shipping-addresses.index'))
            ->post(route('portal.shipping-addresses.store'), [
                'recipient_name' => 'Mario Rossi',
                'address'        => 'Via Verdi 3',
                'city'           => 'Torino',
                // CAP mancante: e' il caso dell'audit, "mancava qualcosa ma
                // non si capiva quale".
            ])
            ->assertSessionHasErrors('postal_code');

        $html = $this->actingAs($buyer)->get(route('portal.shipping-addresses.index'))
            ->assertOk()
            ->getContent();

        // `campo-errore` da solo NON basta: la stessa parola sta nel foglio di
        // stile del layout, quindi la pagina la contiene sempre e il test
        // sarebbe verde anche senza il messaggio. Serve il TAG.
        $this->assertStringContainsString('<p class="campo-errore">', $html,
            'Il messaggio deve comparire sotto il campo, non solo in cima alla pagina.');
    }

    // =========================================================================
    // Impalcatura
    // =========================================================================

    /**
     * Il contenuto testuale del badge delle scorte, ripulito.
     */
    private function contenutoDelBadge(string $html): string
    {
        $trovato = preg_match('/id="badge-scorte"[^>]*>(.*?)<\/span>/s', $html, $pezzi);

        $this->assertSame(1, $trovato, 'Il badge delle scorte non e\' nella pagina.');

        return trim(html_entity_decode($pezzi[1]));
    }

    /**
     * Un prodotto variabile con una taglia per ogni scorta chiesta.
     * `null` = scorta illimitata.
     *
     * @param  array<int, int|null>  $scorte
     * @return array{0: Listing, 1: array<int, ListingVariant>}
     */
    private function prodottoConTaglie(array $scorte): array
    {
        [$company] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 2000, kyPercentage: 100, extra: [
            'title' => 'Maglione di lana',
        ]);

        $nomi = [];

        for ($i = 0; $i < count($scorte); $i++) {
            $nomi[] = 'T' . ($i + 1);
        }

        $valori = $this->makeAttributo('Taglia', $nomi);

        $varianti = [];

        foreach (array_values($scorte) as $i => $quante) {
            $varianti[] = $this->makeVariante($listing, [$valori[$nomi[$i]]], scorte: $quante);
        }

        return [$listing->fresh(['variantiAttive.values.attribute']), $varianti];
    }
}
