<?php

namespace Tests\Feature;

use App\Models\CartItem;
use App\Models\Listing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsShopScenarios;
use Tests\TestCase;

/**
 * IL MINI-CARRELLO (27/08/2026 — audit 26/08, blocco 5).
 *
 * Due attriti, e sono quelli che si sentivano a ogni singolo acquisto:
 *
 *   - **aggiungere al carrello ricaricava la pagina** e ti sbatteva in cima:
 *     dalla scheda prodotto si perdeva anche la galleria, dal catalogo il
 *     punto in cui si stava scorrendo. Per comprare tre cose, tre ricariche;
 *   - **dal catalogo non si poteva aggiungere affatto**: il bottone della card
 *     era solo un link alla scheda.
 *
 * LA REGOLA CHE TIENE IN PIEDI TUTTO: i form restano FORM VERI. Senza
 * JavaScript la pagina si comporta esattamente come prima — e se la richiesta
 * in background fallisce, si lascia proseguire l'invio normale. Un bottone che
 * non fa niente sarebbe peggio di una pagina che si ricarica.
 *
 * E il conteggio del carrello lo dice il SERVER, non il JavaScript sommando:
 * due schede aperte sullo stesso conto devono vedere lo stesso numero.
 */
class MiniCarrelloTest extends TestCase
{
    use BuildsShopScenarios;
    use RefreshDatabase;

    // =========================================================================
    // La risposta in JSON
    // =========================================================================

    public function test_aggiungere_in_background_risponde_col_conteggio_del_server(): void
    {
        [$buyer] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 2000, kyPercentage: 100, extra: ['title' => 'Lampada']);

        $risposta = $this->actingAs($buyer)
            ->postJson(route('portal.cart.add', $listing), ['quantity' => 2])
            ->assertOk();

        $risposta->assertJsonPath('ok', true);
        $risposta->assertJsonPath('righe', 2);
        $risposta->assertJsonPath('prodotto.titolo', 'Lampada');
        $this->assertSame(2, (int) CartItem::query()->sole()->quantity);
    }

    public function test_il_conteggio_somma_tutto_il_carrello_non_solo_l_ultimo(): void
    {
        [$buyer] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller();
        $uno  = $this->makeListing($company, prezzo: 2000, kyPercentage: 100);
        $due  = $this->makeListing($company, prezzo: 3000, kyPercentage: 100);

        $this->actingAs($buyer)->postJson(route('portal.cart.add', $uno), ['quantity' => 2]);

        $this->actingAs($buyer)
            ->postJson(route('portal.cart.add', $due), ['quantity' => 1])
            ->assertOk()
            ->assertJsonPath('righe', 3);
    }

    public function test_un_rifiuto_torna_col_motivo_e_senza_aggiungere_niente(): void
    {
        // Le regole restano quelle di CartService: qui cambia solo la forma
        // della risposta.
        [$buyer] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 2000, kyPercentage: 100);
        $listing->forceFill(['status' => 'suspended'])->save();

        $this->actingAs($buyer)
            ->postJson(route('portal.cart.add', $listing->fresh()))
            ->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('messaggio', 'Questo prodotto non è più disponibile.');

        $this->assertSame(0, CartItem::query()->count());
    }

    public function test_senza_javascript_la_risposta_resta_quella_di_prima(): void
    {
        // La strada vecchia non e' stata sostituita: e' ancora li', ed e'
        // quella che il browser usa da solo se qualcosa va storto.
        [$buyer] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 2000, kyPercentage: 100, extra: ['title' => 'Sgabello']);

        $this->actingAs($buyer)
            ->post(route('portal.cart.add', $listing))
            ->assertRedirect()
            ->assertSessionHas('portal_success', fn ($m) => str_contains($m, 'Sgabello'));

        $this->assertSame(1, CartItem::query()->count());
    }

    // =========================================================================
    // Il bottone nel catalogo
    // =========================================================================

    public function test_dal_catalogo_si_aggiunge_al_carrello(): void
    {
        [$buyer] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller();
        $this->makeListing($company, prezzo: 2000, kyPercentage: 100, extra: ['title' => 'Tazza']);

        $html = $this->actingAs($buyer)->get(route('portal.shop'))->assertOk()->getContent();

        $this->assertStringContainsString('Aggiungi al carrello', $html);

        // Il TAG, non l'attributo nudo: `data-carrello` compare anche nella
        // card "in primo piano" e dentro lo script del layout
        // (`form[data-carrello]`), quindi cercarlo da solo sarebbe verde
        // anche con il form del catalogo smarcato.
        $this->assertMatchesRegularExpression(
            '/<form[^>]*action="[^"]*carrello[^"]*"[^>]*data-carrello/',
            $html,
            'Il form del catalogo deve essere marcato, altrimenti resta un invio con ricarica.'
        );
    }

    public function test_il_prodotto_con_le_taglie_manda_alla_scheda(): void
    {
        // La taglia va scelta, e si sceglie nella scheda: un "aggiungi" qui
        // finirebbe contro "scegli una variante".
        [$buyer] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 2000, kyPercentage: 100, extra: ['title' => 'Maglione']);
        $valori = $this->makeAttributo('Taglia', ['S', 'M']);
        $this->makeVariante($listing, [$valori['S']], scorte: 3);
        $this->makeVariante($listing, [$valori['M']], scorte: 3);

        $html = $this->actingAs($buyer)->get(route('portal.shop'))->assertOk()->getContent();

        $this->assertStringContainsString('Vedi il prodotto', $html);
        $this->assertStringNotContainsString('Aggiungi al carrello', $html);
    }

    public function test_il_prodotto_esaurito_non_offre_l_aggiunta(): void
    {
        [$buyer] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller();
        $this->makeListing($company, prezzo: 2000, kyPercentage: 100, extra: ['stock_quantity' => 0]);

        $html = $this->actingAs($buyer)->get(route('portal.shop'))->assertOk()->getContent();

        $this->assertStringNotContainsString('Aggiungi al carrello', $html);
    }

    public function test_non_si_offre_di_comprare_la_roba_della_propria_azienda(): void
    {
        [$company, $sellerUser] = $this->makeSeller();
        $this->makeListing($company, prezzo: 2000, kyPercentage: 100);

        $html = $this->actingAs($sellerUser)->get(route('portal.shop'))->assertOk()->getContent();

        $this->assertStringNotContainsString('Aggiungi al carrello', $html,
            'Il server lo rifiuterebbe: mostrarlo e poi rifiutare e\' peggio che non mostrarlo.');
    }

    public function test_del_venditore_sospeso_non_si_vede_proprio_niente(): void
    {
        // Questo test prima diceva "non offre l'aggiunta", ed era verde per il
        // motivo sbagliato: i prodotti di un'azienda sospesa NON COMPAIONO
        // AFFATTO nel catalogo, li esclude gia' lo scope `active()` (decisione
        // di Laura del 26/08). Una mutazione l'ha smascherato. Adesso il test
        // dice la cosa vera, che e' anche piu' forte.
        [$buyer] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller();
        $this->makeListing($company, prezzo: 2000, kyPercentage: 100, extra: ['title' => 'Roba di sospeso']);
        $company->forceFill(['suspended_at' => now()])->save();

        $this->actingAs($buyer)->get(route('portal.shop'))
            ->assertOk()
            ->assertDontSee('Roba di sospeso');
    }

    // =========================================================================
    // Il riquadro e i numerini
    // =========================================================================

    public function test_il_numerino_del_carrello_esiste_anche_a_zero(): void
    {
        // Senza, il JavaScript non avrebbe niente da aggiornare al PRIMO
        // prodotto aggiunto, e il numerino comparirebbe solo alla ricarica.
        [$buyer] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller();
        $this->makeListing($company, prezzo: 2000, kyPercentage: 100);

        $html = $this->actingAs($buyer)->get(route('portal.shop'))->assertOk()->getContent();

        // Sono DUE: quello nella voce di menu e quello sull'icona in alto.
        // Cercare l'attributo una volta sola lascerebbe passare la modifica
        // che ne smarca uno.
        $this->assertStringContainsString('class="nav-count" data-carrello-conteggio', $html);
        $this->assertStringContainsString('class="notif-badge" data-carrello-conteggio', $html);
        $this->assertSame(0, CartItem::query()->count());
    }

    public function test_il_riquadro_di_conferma_c_e_ed_e_nascosto(): void
    {
        [$buyer] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller();
        $this->makeListing($company, prezzo: 2000, kyPercentage: 100);

        $html = $this->actingAs($buyer)->get(route('portal.shop'))->assertOk()->getContent();

        $this->assertStringContainsString('<div id="mini-carrello"', $html);
        $this->assertStringContainsString('mini-carrello" class="mini-carrello" hidden', $html);
    }

    public function test_se_la_richiesta_in_background_fallisce_si_invia_il_form_normale(): void
    {
        // E' la rete di sicurezza che rende accettabile tutto il resto: se
        // sparisse, un guasto di rete lascerebbe l'utente davanti a un bottone
        // che non fa niente.
        [$buyer] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller();
        $this->makeListing($company, prezzo: 2000, kyPercentage: 100);

        $html = $this->actingAs($buyer)->get(route('portal.shop'))->assertOk()->getContent();

        $this->assertStringContainsString("form.removeAttribute('data-carrello')", $html);
        $this->assertStringContainsString('form.submit();', $html);
    }
}
