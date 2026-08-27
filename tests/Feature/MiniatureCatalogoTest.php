<?php

namespace Tests\Feature;

use App\Models\Listing;
use App\Services\ImageResizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsShopScenarios;
use Tests\TestCase;

/**
 * MINIATURE E INDICI DEL CATALOGO (27/08/2026).
 *
 * Le tre cose che questi test difendono:
 *
 *   1. **La ricaduta sull'originale.** Le miniature possono mancare per tre
 *      motivi tutti legittimi: la foto e' di prima di questo meccanismo, era
 *      gia' piccola, oppure GD non ce l'ha fatta. In nessuno dei tre casi la
 *      pagina deve restare senza immagine. Una card vuota e' un guasto; una
 *      card lenta e' solo lenta.
 *   2. **Non si ingrandisce mai.** Stirare una foto da 300px a 600 la fa
 *      pesare di piu' E la fa vedere peggio: e' il contrario di quello che
 *      stiamo facendo.
 *   3. **La lente resta sull'originale.** Se anche l'ingrandimento
 *      pescasse dalla versione media, avremmo tolto al venditore l'unico
 *      posto dove la sua foto si vede per davvero.
 */
class MiniatureCatalogoTest extends TestCase
{
    use BuildsShopScenarios;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

        if (! app(ImageResizer::class)->disponibile()) {
            $this->markTestSkipped('GD non disponibile su questa macchina.');
        }
    }

    // =========================================================================
    // Il ridimensionatore
    // =========================================================================

    public function test_una_foto_grande_produce_le_due_misure(): void
    {
        $path = $this->fotoSulDisco('listings/abc/foto.jpg', 2400, 1600);

        $fatte = app(ImageResizer::class)->generaTutte($path);

        $this->assertArrayHasKey(ImageResizer::CARD, $fatte);
        $this->assertArrayHasKey(ImageResizer::MEDIUM, $fatte);

        Storage::disk('public')->assertExists('listings/abc/card/foto.jpg');
        Storage::disk('public')->assertExists('listings/abc/medium/foto.jpg');
    }

    public function test_ogni_misura_rispetta_il_suo_lato_lungo(): void
    {
        $path = $this->fotoSulDisco('listings/abc/foto.jpg', 2400, 1600);
        app(ImageResizer::class)->generaTutte($path);

        [$lCard]   = getimagesize(Storage::disk('public')->path('listings/abc/card/foto.jpg'));
        [$lMedium] = getimagesize(Storage::disk('public')->path('listings/abc/medium/foto.jpg'));

        $this->assertSame(ImageResizer::MISURE[ImageResizer::CARD], $lCard);
        $this->assertSame(ImageResizer::MISURE[ImageResizer::MEDIUM], $lMedium);
    }

    public function test_la_miniatura_pesa_molto_meno_dell_originale(): void
    {
        $path = $this->fotoSulDisco('listings/abc/foto.jpg', 2400, 1600);
        app(ImageResizer::class)->generaTutte($path);

        $disco = Storage::disk('public');
        $originale = $disco->size($path);
        $miniatura = $disco->size('listings/abc/card/foto.jpg');

        // E' tutto il punto del lavoro: se un giorno la miniatura smettesse di
        // essere piu' leggera, questa modifica avrebbe solo aggiunto file.
        $this->assertLessThan($originale, $miniatura);
    }

    public function test_una_foto_gia_piccola_non_viene_ingrandita(): void
    {
        $path = $this->fotoSulDisco('listings/abc/piccola.jpg', 320, 240);

        $fatte = app(ImageResizer::class)->generaTutte($path);

        $this->assertSame([], $fatte);
        Storage::disk('public')->assertMissing('listings/abc/card/piccola.jpg');
        Storage::disk('public')->assertMissing('listings/abc/medium/piccola.jpg');
    }

    public function test_la_generazione_non_rifa_un_lavoro_gia_fatto(): void
    {
        $path = $this->fotoSulDisco('listings/abc/foto.jpg', 2400, 1600);
        $resizer = app(ImageResizer::class);

        $resizer->generaTutte($path);
        $primaVolta = Storage::disk('public')->lastModified('listings/abc/card/foto.jpg');

        // Con la seconda passata il file NON deve essere riscritto: e' quello
        // che rende il comando di backfill ri-eseguibile su migliaia di foto.
        $resizer->genera($path, ImageResizer::CARD);

        $this->assertSame($primaVolta, Storage::disk('public')->lastModified('listings/abc/card/foto.jpg'));
    }

    public function test_un_file_che_non_e_un_immagine_non_fa_esplodere_niente(): void
    {
        Storage::disk('public')->put('listings/abc/finto.jpg', 'questo non e un jpeg');

        $fatte = app(ImageResizer::class)->generaTutte('listings/abc/finto.jpg');

        $this->assertSame([], $fatte);
    }

    public function test_un_png_resta_un_png(): void
    {
        $path = $this->fotoSulDisco('listings/abc/logo.png', 1200, 900, 'png');
        app(ImageResizer::class)->generaTutte($path);

        $info = getimagesize(Storage::disk('public')->path('listings/abc/card/logo.png'));

        $this->assertSame(IMAGETYPE_PNG, $info[2]);
    }

    // =========================================================================
    // Che cosa vede la pagina
    // =========================================================================

    public function test_la_card_usa_la_miniatura_quando_c_e(): void
    {
        $listing = $this->prodottoConFoto(2400, 1600, generaMiniature: true);

        $this->assertStringContainsString('/card/', (string) $listing->card_image_url);
    }

    public function test_la_card_ripiega_sull_originale_per_le_foto_vecchie(): void
    {
        // Nessuna miniatura sul disco: e' la situazione di TUTTE le foto gia'
        // in produzione, finche' non gira il comando di backfill.
        $listing = $this->prodottoConFoto(2400, 1600, generaMiniature: false);

        $url = (string) $listing->card_image_url;

        $this->assertStringNotContainsString('/card/', $url);
        $this->assertStringContainsString('foto.jpg', $url);
    }

    public function test_senza_foto_non_si_inventa_niente(): void
    {
        [$company] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 2000, kyPercentage: 100);

        $this->assertNull($listing->card_image_url);
        $this->assertSame([], $listing->card_image_urls);
    }

    public function test_la_griglia_dello_shop_carica_le_miniature_e_non_gli_originali(): void
    {
        $listing = $this->prodottoConFoto(2400, 1600, generaMiniature: true);
        [$buyer] = $this->makeBuyer(saldo: 1000);

        $html = $this->actingAs($buyer)->get(route('portal.shop'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('/card/foto.jpg', $html);
        // L'originale non deve comparire in griglia: sarebbe la lentezza che
        // stiamo togliendo, rientrata dalla finestra.
        $this->assertStringNotContainsString('listings/' . $listing->uuid . '/foto.jpg', $html);
    }

    /**
     * La fascia "in primo piano" e' un blocco di Blade SUO, sopra la griglia,
     * con il suo `@if` e la sua immagine. Senza un test dedicato una modifica
     * puo' toccare la griglia e lasciare indietro la fascia — che e' proprio
     * la parte in cima alla pagina, quella che si vede per prima.
     */
    public function test_anche_la_fascia_in_primo_piano_usa_le_miniature(): void
    {
        $listing = $this->prodottoConFoto(2400, 1600, generaMiniature: true);
        $listing->forceFill(['featured' => true])->save();
        [$buyer] = $this->makeBuyer(saldo: 1000);

        $html = $this->actingAs($buyer)->get(route('portal.shop'))
            ->assertOk()
            ->assertSee('Prodotti in primo piano')
            ->getContent();

        $this->assertStringNotContainsString('listings/' . $listing->uuid . '/foto.jpg', $html);
        $this->assertStringContainsString('/card/foto.jpg', $html);
    }

    public function test_la_scheda_prodotto_mostra_la_media_e_tiene_l_originale_per_la_lente(): void
    {
        $listing = $this->prodottoConFoto(2400, 1600, generaMiniature: true);
        [$buyer] = $this->makeBuyer(saldo: 1000);

        $html = $this->actingAs($buyer)->get(route('portal.shop.show', $listing))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('/medium/foto.jpg', $html);
        // L'originale c'e' ancora, ma solo dentro l'elenco della lente.
        $this->assertStringContainsString('listings\/' . $listing->uuid . '\/foto.jpg', $html);

        // E cambiando miniatura la foto grande deve restare la media: se
        // ripescasse dall'elenco degli originali, il primo clic vanificherebbe
        // tutto il lavoro proprio nel momento in cui l'utente sta guardando.
        $this->assertStringContainsString("gallery-main').src = medi[", $html);
    }

    // =========================================================================
    // Pulizia
    // =========================================================================

    public function test_cancellando_una_foto_spariscono_anche_le_miniature(): void
    {
        $listing = $this->prodottoConFoto(2400, 1600, generaMiniature: true);
        $path = $listing->images[0];

        $listing->deleteImage($path);

        Storage::disk('public')->assertMissing($path);
        Storage::disk('public')->assertMissing(ImageResizer::pathDerivato($path, ImageResizer::CARD));
        Storage::disk('public')->assertMissing(ImageResizer::pathDerivato($path, ImageResizer::MEDIUM));
    }

    // =========================================================================
    // Il caricamento vero, dal form
    // =========================================================================

    public function test_pubblicando_un_prodotto_le_miniature_nascono_da_sole(): void
    {
        [, $sellerUser] = $this->makeSeller();
        $this->makeCategoria();

        $this->actingAs($sellerUser)->post(route('portal.shop.store'), [
            'title'         => 'Poltrona di vimini',
            'description'   => 'Descrizione sufficientemente lunga.',
            'category'      => 'informatica',
            'price_ky'      => '10.00',
            'ky_percentage' => 100,
            'stock_mode'    => 'unlimited',
            'delivery_type' => Listing::DELIVERY_TYPE_SERVIZIO,
            'images'        => [UploadedFile::fake()->image('scatto.jpg', 2000, 1500)],
        ])->assertSessionHasNoErrors();

        $listing = Listing::sole();
        $path = $listing->images[0];

        Storage::disk('public')->assertExists($path);
        Storage::disk('public')->assertExists(ImageResizer::pathDerivato($path, ImageResizer::CARD));
        Storage::disk('public')->assertExists(ImageResizer::pathDerivato($path, ImageResizer::MEDIUM));
    }

    // =========================================================================
    // Il comando di recupero
    // =========================================================================

    public function test_il_comando_genera_le_miniature_mancanti(): void
    {
        $listing = $this->prodottoConFoto(2400, 1600, generaMiniature: false);
        $path = $listing->images[0];

        Storage::disk('public')->assertMissing(ImageResizer::pathDerivato($path, ImageResizer::CARD));

        $this->artisan('shop:miniature')->assertSuccessful();

        Storage::disk('public')->assertExists(ImageResizer::pathDerivato($path, ImageResizer::CARD));
        Storage::disk('public')->assertExists(ImageResizer::pathDerivato($path, ImageResizer::MEDIUM));
    }

    public function test_il_comando_in_prova_non_scrive_niente(): void
    {
        $listing = $this->prodottoConFoto(2400, 1600, generaMiniature: false);
        $path = $listing->images[0];

        $this->artisan('shop:miniature --dry-run')->assertSuccessful();

        Storage::disk('public')->assertMissing(ImageResizer::pathDerivato($path, ImageResizer::CARD));
    }

    public function test_il_comando_si_puo_rilanciare_senza_rifare_il_lavoro(): void
    {
        $listing = $this->prodottoConFoto(2400, 1600, generaMiniature: false);
        $path = $listing->images[0];

        $this->artisan('shop:miniature')->assertSuccessful();
        $primaVolta = Storage::disk('public')->lastModified(ImageResizer::pathDerivato($path, ImageResizer::CARD));

        $this->artisan('shop:miniature')->assertSuccessful();

        $this->assertSame(
            $primaVolta,
            Storage::disk('public')->lastModified(ImageResizer::pathDerivato($path, ImageResizer::CARD))
        );
    }

    // =========================================================================
    // Gli indici
    // =========================================================================

    public function test_il_catalogo_ha_gli_indici_che_coprono_l_ordinamento(): void
    {
        // Non e' un test di prestazioni — quelli non si scrivono con phpunit —
        // ma di presenza: se qualcuno un giorno riscrive la migrazione o la
        // salta in produzione, l'ordinamento torna a farsi in memoria e nessuno
        // se ne accorge finche' il catalogo non e' grande.
        $indici = collect(Schema::getIndexes('listings'))->pluck('name')->all();

        $this->assertContains('listings_status_featured_created_index', $indici);
        $this->assertContains('listings_company_status_created_index', $indici);
        $this->assertContains('listings_category_status_featured_created_index', $indici);
    }

    // =========================================================================
    // Impalcatura
    // =========================================================================

    /** Scrive sul disco finto una vera immagine delle dimensioni chieste. */
    private function fotoSulDisco(string $path, int $larghezza, int $altezza, string $formato = 'jpg'): string
    {
        $tela = imagecreatetruecolor($larghezza, $altezza);

        // Un po' di disegno: un'immagine tutta di un colore si comprime a
        // pochissimi byte e renderebbe insensato il confronto sui pesi.
        for ($i = 0; $i < 400; $i++) {
            $colore = imagecolorallocate($tela, ($i * 7) % 255, ($i * 13) % 255, ($i * 29) % 255);
            imagefilledellipse(
                $tela,
                (int) (($i * 37) % $larghezza),
                (int) (($i * 53) % $altezza),
                60,
                60,
                $colore
            );
        }

        $temporaneo = tempnam(sys_get_temp_dir(), 'foto');
        $formato === 'png' ? imagepng($tela, $temporaneo) : imagejpeg($tela, $temporaneo, 92);
        imagedestroy($tela);

        Storage::disk('public')->put($path, file_get_contents($temporaneo));
        @unlink($temporaneo);

        return $path;
    }

    private function prodottoConFoto(int $larghezza, int $altezza, bool $generaMiniature): Listing
    {
        [$company] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 2000, kyPercentage: 100, extra: [
            'title' => 'Prodotto con foto',
        ]);

        $path = $this->fotoSulDisco("listings/{$listing->uuid}/foto.jpg", $larghezza, $altezza);
        $listing->forceFill(['images' => [$path]])->save();

        if ($generaMiniature) {
            app(ImageResizer::class)->generaTutte($path);
        }

        return $listing->fresh();
    }

    private function makeCategoria(string $slug = 'informatica'): \App\Models\ListingCategory
    {
        return \App\Models\ListingCategory::create([
            'parent_id'  => null,
            'slug'       => $slug,
            'name'       => 'Informatica',
            'is_active'  => true,
            'sort_order' => 0,
        ]);
    }
}
