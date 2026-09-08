<?php

namespace Tests\Feature;

use App\Models\Listing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsShopScenarios;
use Tests\TestCase;

/**
 * LA CORNICE DELLE FOTO — 08/09/2026.
 *
 * Fino a ieri la foto del prodotto veniva RITAGLIATA per riempire un riquadro
 * orizzontale (16:10, `object-fit: cover`): a un elettrodomestico fotografato
 * in verticale spariva la parte alta, di un logo tondo restava una fascia, di
 * un barattolo si vedeva il centro. Chi vende nel circuito fotografa col
 * telefono, e le foto del telefono sono quadrate o verticali — cioè proprio
 * quelle che ci rimettevano di più.
 *
 * Adesso la cornice è QUADRATA e la foto ci sta dentro tutta. Il prezzo lo
 * pagano le foto orizzontali, che mostrano due bande di --media-frame: è il
 * baratto scelto da Laura dopo aver visto le due versioni a confronto.
 *
 * Nella scheda prodotto la stessa idea cambia anche la pagina: la foto non è
 * più uno striscione alto 420px largo quanto la colonna — che si mangiava
 * tutta la prima schermata — ma un riquadro con le miniature a fianco, e la
 * larghezza che avanza la prende la descrizione.
 */
class FotoProdottoCorniceTest extends TestCase
{
    use BuildsShopScenarios;
    use RefreshDatabase;

    // =========================================================================
    // 1. La cornice, nel foglio di stile
    // =========================================================================

    public function test_la_cornice_della_card_e_quadrata_e_la_foto_non_si_taglia(): void
    {
        $regola = $this->regolaCss('.product-media {');

        $this->assertStringContainsString('aspect-ratio: 1 / 1', $regola);
        $this->assertStringNotContainsString('16 / 10', $regola);
        $this->assertStringContainsString('var(--media-frame)', $regola);

        // È `contain` che fa la differenza: `cover` riempie tagliando.
        $this->assertStringContainsString('object-fit: contain', $this->regolaCss('.product-media img {'));
    }

    public function test_anche_le_miniature_della_scheda_mostrano_la_foto_intera(): void
    {
        $this->assertStringContainsString('object-fit: contain', $this->regolaCss('.thumb-strip-img {'));
    }

    public function test_le_misure_dichiarate_sulla_foto_sono_quelle_della_cornice(): void
    {
        // width/height sull'<img> servono al browser per riservare lo spazio
        // giusto: se restano quelli della cornice vecchia (600x375) la griglia
        // salta mentre le foto arrivano, esattamente come se non ci fossero.
        $componente = file_get_contents(resource_path('views/components/shop/media.blade.php'));

        $this->assertStringContainsString('width="600" height="600"', $componente);
        $this->assertStringNotContainsString('height="375"', $componente);
    }

    // =========================================================================
    // 2. La scheda prodotto
    // =========================================================================

    public function test_la_scheda_mette_la_foto_in_un_riquadro_con_le_miniature_a_fianco(): void
    {
        $html = $this->schedaDi($this->prodottoConFoto(3));

        $this->assertStringContainsString('scheda-galleria', $html);
        $this->assertStringContainsString('scheda-thumbs', $html);
        $this->assertStringContainsString('scheda-foto', $html);
        $this->assertStringContainsString('scheda-descrizione', $html);

        // Lo striscione ritagliato non deve tornare.
        $this->assertStringNotContainsString('max-height:420px', $html);
    }

    public function test_con_una_foto_sola_le_miniature_non_compaiono(): void
    {
        $html = $this->schedaDi($this->prodottoConFoto(1));

        $this->assertStringContainsString('scheda-foto', $html);
        $this->assertStringNotContainsString('scheda-thumbs', $html);
    }

    public function test_il_contatore_della_galleria_ha_un_id_suo(): void
    {
        // Il JavaScript cercava "il div subito dopo la foto": nella galleria
        // nuova, accanto alla foto, di div ce n'è un altro.
        $html = $this->schedaDi($this->prodottoConFoto(2));

        $this->assertStringContainsString('id="gallery-counter"', $html);
        $this->assertStringContainsString("getElementById('gallery-counter')", $html);
        $this->assertStringNotContainsString("querySelector('#gallery-main + div')", $html);
    }

    public function test_un_prodotto_senza_foto_mostra_lo_stesso_la_descrizione(): void
    {
        // La descrizione è finita DENTRO il blocco della galleria: se il
        // prodotto non ha foto deve uscire lo stesso, o sparisce mezza pagina.
        $html = $this->schedaDi($this->prodottoConFoto(0));

        $this->assertStringContainsString('scheda-foto--vuota', $html);
        $this->assertStringContainsString('Descrizione del prodotto di prova.', $html);
    }

    // =========================================================================
    // Aiuti
    // =========================================================================

    /** Il corpo di una regola di shop.css, dalla graffa aperta alla chiusa. */
    private function regolaCss(string $selettore): string
    {
        $css = file_get_contents(public_path('assets/css/shop.css'));
        $inizio = strpos($css, $selettore);

        $this->assertNotFalse($inizio, "shop.css non contiene più la regola «{$selettore}».");

        $fine = strpos($css, '}', $inizio);

        return substr($css, $inizio, $fine - $inizio);
    }

    private function prodottoConFoto(int $quante): Listing
    {
        [$company] = $this->makeSeller();

        $foto = [];
        for ($i = 1; $i <= $quante; $i++) {
            $foto[] = "listings/finte/foto{$i}.jpg";
        }

        return $this->makeListing($company, prezzo: 2000, kyPercentage: 100, extra: ['images' => $foto]);
    }

    private function schedaDi(Listing $listing): string
    {
        [$compratore] = $this->makeBuyer();

        return $this->actingAs($compratore)
            ->get(route('portal.shop.show', $listing))
            ->assertOk()
            ->getContent();
    }
}
