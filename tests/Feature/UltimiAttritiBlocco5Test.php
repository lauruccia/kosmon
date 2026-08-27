<?php

namespace Tests\Feature;

use App\Models\Listing;
use App\Models\Order;
use App\Models\ShippingAddress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsShopScenarios;
use Tests\TestCase;

/**
 * GLI ULTIMI DUE ATTRITI DEL BLOCCO 5 (27/08/2026 — audit 26/08).
 *
 * Due cose piccole, e diverse fra loro solo in apparenza: tutte e due
 * decidevano qualcosa al posto di chi compra.
 *
 *   1. **La barra dei filtri nascondeva il proprio bottone.** Era
 *      `flex-wrap: nowrap` con `overflow-x: auto`: i quattro campi hanno
 *      min-width per 790px, e dopo di loro vengono "Filtra", "Reset" e i
 *      collegamenti a destra. Quando la colonna e' piu' stretta di circa un
 *      metro — tablet in orizzontale, portatile stretto con la sidebar
 *      aperta — la barra scorreva lateralmente senza sembrare scorrevole, e
 *      "Filtra" finiva fuori dal bordo. Si poteva compilare il filtro e non
 *      inviarlo, se non premendo Invio: cosa che nessuno sa.
 *
 *   2. **La cassa si riempiva la rubrica da sola.** "Salvalo nella mia
 *      rubrica" era spuntato sempre. Chi spediva un regalo a un amico si
 *      ritrovava in rubrica il nome e il telefono di quell'amico, e doveva
 *      pure toglierlo a mano perche' i posti sono dieci.
 *
 * SUL SECONDO C'ERA UN SECONDO GUAIO DENTRO AL PRIMO: la casella non
 * spuntata non viene inviata dal browser, e `old('salva_indirizzo', '1')`
 * non sapeva distinguere "l'ho tolta" da "non ho ancora inviato niente". Chi
 * toglieva la spunta e poi sbagliava un campo se la ritrovava rimessa, e a
 * quel punto la toglieva una volta su due. Si cura da solo togliendo quel
 * '1': adesso una spunta al ritorno puo' venire solo da lei.
 *
 * ONESTA' SUL PRIMO TEST: il CSS si guarda per stringhe, come gia' si fa
 * per il JavaScript altrove. E' un controllo debole — non dice che la
 * pagina *sembra* giusta a 800px, quello lo dice solo un occhio — ma dice
 * che nessuno ha rimesso `nowrap`, che e' l'unica cosa che si puo'
 * verificare da qui e anche l'unica che tornerebbe a rompersi da sola.
 */
class UltimiAttritiBlocco5Test extends TestCase
{
    use BuildsShopScenarios;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    // =========================================================================
    // 1. La barra dei filtri
    // =========================================================================

    public function test_la_barra_dei_filtri_va_a_capo_invece_di_nascondere(): void
    {
        $html = $this->catalogo();

        // La REGOLA INTERA, non `flex-wrap: wrap` da solo: quella coppia di
        // parole compare in mezza pagina (le azioni del banner venditore, la
        // striscia in primo piano, la topbar del layout) e il test sarebbe
        // verde anche con la barra tornata a `nowrap`.
        $this->assertStringContainsString(
            '.shop-toolbar { display: flex; gap: 14px; flex-wrap: wrap; align-items: flex-end; }',
            $html,
            'Senza `wrap` il bottone "Filtra" torna a uscire dal bordo su schermi stretti.'
        );

        $this->assertStringNotContainsString(
            '.shop-toolbar { display: flex; gap: 14px; flex-wrap: nowrap;',
            $html,
            'Era questa la riga che nascondeva il bottone.'
        );
    }

    public function test_il_bottone_filtra_e_le_azioni_stanno_nella_barra(): void
    {
        // Regressione della regressione: sistemare l'andare a capo togliendo
        // roba dalla barra sarebbe stato barare.
        $html = $this->catalogo();

        $this->assertStringContainsString('<button type="submit" class="cta">Filtra</button>', $html);
        $this->assertStringContainsString('<div class="shop-toolbar-actions">', $html,
            'Le azioni a destra hanno una classe vera: gli stili in linea non si possono mandare a capo.');
        $this->assertStringContainsString('.shop-toolbar-actions { margin-left: auto;', $html);

        // E quando vanno a capo per conto loro, tornano a sinistra: spinte a
        // destra su una riga tutta loro sarebbero bottoni ammucchiati in
        // fondo a mezzo schermo vuoto.
        $this->assertStringContainsString('.shop-toolbar-actions { margin-left: 0; width: 100%; }', $html);
    }

    public function test_i_filtri_funzionano_ancora(): void
    {
        // La barra e' un form: cambiando il modo in cui si dispone non deve
        // smettere di filtrare.
        [$buyer] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller();
        $this->makeListing($company, prezzo: 2000, kyPercentage: 100, extra: ['title' => 'Chitarra classica']);
        $this->makeListing($company, prezzo: 2000, kyPercentage: 100, extra: ['title' => 'Tostapane']);

        $this->actingAs($buyer)->get(route('portal.shop', ['q' => 'Chitarra']))
            ->assertOk()
            ->assertSee('Chitarra classica')
            ->assertDontSee('Tostapane');
    }

    // =========================================================================
    // 2. La rubrica la riempie chi compra
    // =========================================================================

    public function test_la_spunta_parte_libera(): void
    {
        // E' il caso del regalo: uno che la rubrica ce l'ha — e alla cassa di
        // un prodotto da spedire ce l'ha per forza, vedi sotto — e questa
        // volta spedisce altrove.
        $html = $this->cassaConRubricaDa(1);

        $this->assertFalse($this->spuntaMessa($html),
            'Non deve trovarsi in rubrica il nome e il telefono di un altro senza averlo deciso.');
        $this->assertStringContainsString('Salvalo nella mia rubrica', $html,
            'La casella resta: cambia il valore di partenza, non la possibilita\' di salvare.');
    }

    public function test_la_spunta_parte_libera_anche_con_la_rubrica_piena(): void
    {
        // Nessun caso limite nascosto fra "uno" e "tanti".
        $html = $this->cassaConRubricaDa(4);

        $this->assertFalse($this->spuntaMessa($html));
    }

    public function test_alla_cassa_di_un_prodotto_da_spedire_la_rubrica_non_e_mai_vuota(): void
    {
        // E' il fatto che ha fatto scartare il "primo indirizzo spuntato per
        // cortesia": sarebbe stato un ramo che non si esegue mai.
        //
        // La catena: il blocco della rubrica compare solo se `$serveIndirizzo`;
        // `motivoPerCuiNonSiPuoPagare()` rimanda al carrello chi non ha
        // `hasShippingAddress()`; e chi svuota la rubrica si vede svuotare
        // anche le colonne del conto (ShippingAddressBook::elimina). Se un
        // giorno questa catena si spezza, il ramo mancante torna a servire —
        // e questo test cade per dirlo.
        [$buyer] = $this->makeBuyer(saldo: 100000, conIndirizzo: false);
        [$company] = $this->makeSeller();
        $this->actingAs($buyer)->post(route('portal.cart.add', $this->daSpedire($company)));

        $this->actingAs($buyer)->get(route('portal.cart.checkout.form'))
            ->assertRedirect(route('portal.cart'))
            ->assertSessionHas('portal_error', fn ($m) => str_contains($m, 'indirizzo di spedizione'));
    }

    public function test_la_spunta_messa_sopravvive_a_un_errore_di_validazione(): void
    {
        // IL TEST CHE CONTA, ed e' il rovescio esatto del guaio vecchio.
        // Adesso che il valore di partenza e' "libera", una spunta che si
        // ritrova al ritorno puo' venire solo da `old()`, cioe' da lei. Con
        // il vecchio `old('salva_indirizzo', '1')` era il contrario: la
        // spunta al ritorno c'era comunque, sia che l'avesse messa lei sia
        // che l'avesse appena tolta.
        [$buyer, $account] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller();
        $this->actingAs($buyer)->post(route('portal.cart.add', $this->daSpedire($company)));

        $this->actingAs($buyer)
            ->from(route('portal.cart.checkout.form'))
            ->post(route('portal.cart.checkout'), $this->datiCassa([
                'salva_indirizzo'    => '1',
                'accetto_condizioni' => '',   // <- e' questo che fa fallire
            ]))
            ->assertRedirect(route('portal.cart.checkout.form'));

        $html = $this->actingAs($buyer)->get(route('portal.cart.checkout.form'))->assertOk()->getContent();

        $this->assertTrue($this->spuntaMessa($html),
            'Chi ha chiesto di salvare non deve doverlo chiedere due volte.');
        $this->assertSame(1, ShippingAddress::where('account_id', $account->id)->count(),
            'L\'ordine non e\' passato: in rubrica non deve essere finito niente.');
    }

    // =========================================================================
    // Il server: quello che decide davvero
    // =========================================================================

    public function test_senza_spunta_l_indirizzo_non_finisce_in_rubrica(): void
    {
        [$buyer, $account] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller();
        $this->actingAs($buyer)->post(route('portal.cart.add', $this->daSpedire($company)));

        $this->actingAs($buyer)->post(route('portal.cart.checkout'), $this->datiCassa([
            'salva_indirizzo' => '0',
        ]));

        $this->assertSame(1, Order::query()->count(), 'L\'ordine si fa lo stesso.');
        $this->assertSame('Via Del Regalo 9', Order::sole()->shipping_address);
        $this->assertSame(1, ShippingAddress::where('account_id', $account->id)->count(),
            'In rubrica deve esserci ancora solo quello di prima.');
        $this->assertSame('Via Roma 1',
            ShippingAddress::where('account_id', $account->id)->sole()->address);
    }

    public function test_con_la_spunta_l_indirizzo_finisce_in_rubrica(): void
    {
        // Regressione che vale piu' della modifica: togliere la spunta di
        // default non deve togliere il salvataggio a chi lo vuole.
        [$buyer, $account] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller();
        $this->actingAs($buyer)->post(route('portal.cart.add', $this->daSpedire($company)));

        $this->actingAs($buyer)->post(route('portal.cart.checkout'), $this->datiCassa([
            'salva_indirizzo' => '1',
        ]));

        $this->assertSame(1, Order::query()->count());
        $this->assertSame(2, ShippingAddress::where('account_id', $account->id)->count());
        $this->assertTrue(ShippingAddress::where('account_id', $account->id)
            ->where('address', 'Via Del Regalo 9')->exists());
    }

    // =========================================================================
    // Impalcatura
    // =========================================================================

    /** Il catalogo visto da un compratore qualsiasi, con dentro un prodotto. */
    private function catalogo(): string
    {
        [$buyer] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller();
        $this->makeListing($company, prezzo: 2000, kyPercentage: 100);

        return $this->actingAs($buyer)->get(route('portal.shop'))->assertOk()->getContent();
    }

    /** La casella "salvalo in rubrica" e' spuntata? */
    private function spuntaMessa(string $html): bool
    {
        $trovato = preg_match(
            '/<input type="checkbox" name="salva_indirizzo"[^>]*>/',
            $html,
            $riscontro
        );

        $this->assertSame(1, $trovato, 'La casella "salvalo in rubrica" deve esserci.');

        return str_contains($riscontro[0], 'checked');
    }

    /** La pagina di cassa, con un prodotto da spedire e N indirizzi salvati. */
    private function cassaConRubricaDa(int $quanti): string
    {
        [$buyer, $account] = $this->makeBuyer(saldo: 100000, conIndirizzo: $quanti > 0);
        [$company] = $this->makeSeller();

        for ($i = 1; $i < $quanti; $i++) {
            ShippingAddress::create([
                'account_id'     => $account->id,
                'label'          => "Sede {$i}",
                'recipient_name' => 'Mario Rossi',
                'address'        => "Via Delle Prove {$i}",
                'city'           => 'Milano',
                'postal_code'    => '20100',
                'province'       => 'MI',
                'phone'          => '3331234567',
                'is_default'     => false,
            ]);
        }

        $this->actingAs($buyer)->post(route('portal.cart.add', $this->daSpedire($company)));

        return $this->actingAs($buyer)->get(route('portal.cart.checkout.form'))->assertOk()->getContent();
    }

    private function daSpedire($company): Listing
    {
        return $this->makeListing($company, prezzo: 2000, kyPercentage: 100, extra: [
            'delivery_type' => Listing::DELIVERY_TYPE_SPEDIZIONE,
            'shipping_cost' => 0,
        ]);
    }

    /** @return array<string, string> */
    private function datiCassa(array $extra = []): array
    {
        return array_merge([
            'accetto_condizioni' => '1',
            'indirizzo_scelto'   => 'nuovo',
            'recipient_name'     => 'Giulia Ferri',
            'address'            => 'Via Del Regalo 9',
            'city'               => 'Bologna',
            'postal_code'        => '40100',
            'province'           => 'BO',
            'phone'              => '3387776655',
        ], $extra);
    }
}
