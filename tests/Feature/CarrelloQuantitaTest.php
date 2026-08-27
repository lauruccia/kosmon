<?php

namespace Tests\Feature;

use App\Models\CartItem;
use App\Services\CartService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsShopScenarios;
use Tests\TestCase;

/**
 * IL CARRELLO NON DEVE FAR PAGARE UN NUMERO DIVERSO DA QUELLO CHE SI VEDE
 * (27/08/2026 — audit 26/08, blocco 5).
 *
 * Il problema non era estetico. La quantita' viveva in un form suo con un
 * bottoncino "Aggiorna" da 12px senza sfondo: chi cambiava il numero e andava
 * in cassa senza premerlo **pagava la quantita' vecchia**. Vedeva tre e pagava
 * uno, e se ne accorgeva a soldi usciti.
 *
 * Accanto, alto uguale e a due pixel di distanza, c'era "Rimuovi", che
 * cancellava la riga senza chiedere niente — mentre "Svuota il carrello", che
 * e' un gesto molto piu' deliberato, la conferma ce l'aveva. La protezione
 * stava sull'azione sbagliata.
 *
 * QUESTI TEST GUARDANO ANCHE IL JAVASCRIPT, per stringhe. E' un controllo
 * debole ma non inutile: senza, si potrebbe togliere l'invio automatico e
 * nessun test cadrebbe, perche' il server continuerebbe a rispondere benissimo
 * alla richiesta che nessuno gli manda piu'.
 */
class CarrelloQuantitaTest extends TestCase
{
    use BuildsShopScenarios;
    use RefreshDatabase;

    public function test_la_quantita_si_invia_da_sola_quando_la_lasci(): void
    {
        $html = $this->carrelloConUnProdotto();

        $this->assertStringContainsString("addEventListener('change'", $html);
        $this->assertStringContainsString('requestSubmit', $html,
            'Senza l\'invio automatico si torna a poter pagare il numero vecchio.');
    }

    public function test_senza_javascript_resta_il_bottone_aggiorna(): void
    {
        $html = $this->carrelloConUnProdotto();

        // La rete di sicurezza: il bottone e' nel markup e lo nasconde solo
        // lo script, cioe' solo dove l'invio automatico funziona davvero.
        $this->assertStringContainsString('class="qta-conferma"', $html);
        $this->assertStringContainsString('Aggiorna', $html);
    }

    public function test_rimuovi_chiede_conferma(): void
    {
        $html = $this->carrelloConUnProdotto();

        // Il TAG, non il nome nudo della classe: `rimuovi-conferma` compare
        // anche nel foglio di stile e dentro lo script, quindi la pagina lo
        // conterrebbe comunque e il test sarebbe verde senza il riquadro.
        $this->assertStringContainsString('<span class="rimuovi-conferma"', $html);
        $this->assertStringContainsString('Togliere dal carrello?', $html);
        $this->assertStringContainsString('Sì, togli', $html);
    }

    public function test_la_conferma_intercetta_l_invio_non_il_clic(): void
    {
        $html = $this->carrelloConUnProdotto();

        // E' la differenza che tiene in piedi il caso senza JavaScript: se la
        // conferma fosse agganciata al CLIC del bottone, e il bottone fosse
        // `type="button"`, senza script non si potrebbe piu' togliere niente
        // dal carrello.
        $this->assertStringContainsString("form.addEventListener('submit'", $html);
        $this->assertStringContainsString('class="rimuovi-avvia"', $html);
        $this->assertMatchesRegularExpression(
            '/<button type="submit" class="rimuovi-avvia"/',
            $html,
            'Il bottone deve restare un invio vero, altrimenti senza JavaScript non toglie piu\' niente.'
        );
    }

    public function test_il_messaggio_della_quantita_minima_e_in_italiano(): void
    {
        $html = $this->carrelloConUnProdotto();

        // Il messaggio nativo del browser esce in inglese su ogni browser non
        // localizzato, e non dice cosa fare.
        $this->assertStringContainsString('setCustomValidity', $html);
        $this->assertStringContainsString('La quantità minima è 1', $html);
    }

    // =========================================================================
    // Regressione: il server continua a fare il suo mestiere
    // =========================================================================

    public function test_aggiornare_la_quantita_funziona_ancora(): void
    {
        [$buyer, $riga] = $this->carrelloVero();

        $this->actingAs($buyer)
            ->patch(route('portal.cart.item.update', $riga), ['quantity' => 3])
            ->assertRedirect();

        $this->assertSame(3, (int) $riga->fresh()->quantity);
    }

    public function test_togliere_una_riga_funziona_ancora(): void
    {
        [$buyer, $riga] = $this->carrelloVero();

        $this->actingAs($buyer)
            ->delete(route('portal.cart.item.remove', $riga))
            ->assertRedirect();

        $this->assertNull(CartItem::query()->find($riga->id));
    }

    public function test_la_quantita_oltre_le_scorte_resta_rifiutata_dal_server(): void
    {
        // Il `max` nel campo e' una cortesia del browser: la difesa vera sta
        // dove e' sempre stata.
        [$buyer, $riga] = $this->carrelloVero(scorte: 2);

        $this->actingAs($buyer)
            ->patch(route('portal.cart.item.update', $riga), ['quantity' => 99])
            ->assertSessionHas('portal_error');

        $this->assertSame(1, (int) $riga->fresh()->quantity);
    }

    // =========================================================================
    // Impalcatura
    // =========================================================================

    private function carrelloConUnProdotto(): string
    {
        [$buyer] = $this->carrelloVero();

        return $this->actingAs($buyer)->get(route('portal.cart'))
            ->assertOk()
            ->getContent();
    }

    /** @return array{0: \App\Models\User, 1: CartItem} */
    private function carrelloVero(?int $scorte = null): array
    {
        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 2000, kyPercentage: 100, extra: array_filter([
            'title'          => 'Sedia di paglia',
            'stock_quantity' => $scorte,
        ], fn ($v) => $v !== null));

        app(CartService::class)->aggiungi($buyerAccount, $listing, 1);

        return [$buyer, CartItem::query()->sole()];
    }
}
