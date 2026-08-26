<?php

namespace Tests\Feature;

use App\Models\Listing;
use App\Models\Order;
use App\Models\ShippingAddress;
use App\Services\ShippingAddressBook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsShopScenarios;
use Tests\TestCase;

/**
 * FASE A-bis — la rubrica degli indirizzi (26/08/2026).
 *
 * Le cose che questi test difendono, in ordine di importanza:
 *
 *   1. **L'ordine parte verso l'indirizzo scelto**, non verso il predefinito.
 *      Sbagliare qui vuol dire spedire il pacco a casa di qualcun altro.
 *   2. **Non si tocca la rubrica di un altro**, ne' dal profilo ne' dalla cassa
 *      manovrando l'id.
 *   3. **Il predefinito e' sempre uno solo**, e `accounts.shipping_*` ne resta
 *      la copia fedele — perche' quelle colonne decidono se un prodotto da
 *      spedire si puo' comprare.
 *   4. **In cassa si vedono TUTTI gli indirizzi salvati**, non i primi cinque
 *      come fa Shopify.
 */
class ShippingAddressBookTest extends TestCase
{
    use BuildsShopScenarios;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    /** @return array<string, string> */
    private function datiIndirizzo(array $extra = []): array
    {
        return array_merge([
            'label'          => 'Ufficio',
            'recipient_name' => 'Luisa Bianchi',
            'address'        => 'Corso Italia 42',
            'city'           => 'Torino',
            'postal_code'    => '10121',
            'province'       => 'TO',
            'phone'          => '3339998877',
        ], $extra);
    }

    private function rubrica(): ShippingAddressBook
    {
        return app(ShippingAddressBook::class);
    }

    // =========================================================================
    // 1. Il tetto e il predefinito
    // =========================================================================

    public function test_il_conto_puo_avere_al_massimo_dieci_indirizzi(): void
    {
        [, $account] = $this->makeBuyer(conIndirizzo: false);

        for ($i = 1; $i <= ShippingAddress::MAX_PER_ACCOUNT; $i++) {
            $this->rubrica()->aggiungi($account, $this->datiIndirizzo(['label' => "Sede {$i}"]));
        }

        $this->assertSame(10, ShippingAddress::MAX_PER_ACCOUNT, 'Il tetto scelto con Laura e\' 10.');
        $this->assertSame(10, ShippingAddress::where('account_id', $account->id)->count());

        $this->expectExceptionMessage('Hai già 10 indirizzi salvati');
        $this->rubrica()->aggiungi($account, $this->datiIndirizzo(['label' => 'Uno di troppo']));
    }

    public function test_il_primo_indirizzo_diventa_predefinito_da_solo(): void
    {
        [, $account] = $this->makeBuyer(conIndirizzo: false);

        $this->assertFalse($account->fresh()->hasShippingAddress());

        $indirizzo = $this->rubrica()->aggiungi($account, $this->datiIndirizzo());

        $this->assertTrue($indirizzo->fresh()->is_default, 'Un conto con un indirizzo e nessun predefinito non spedirebbe piu\' niente.');
        $this->assertTrue($account->fresh()->hasShippingAddress());
        $this->assertSame('Corso Italia 42', $account->fresh()->shipping_address);
    }

    public function test_il_predefinito_resta_sempre_uno_solo(): void
    {
        [, $account] = $this->makeBuyer();   // ne ha gia' uno, "Casa"

        $ufficio   = $this->rubrica()->aggiungi($account, $this->datiIndirizzo(), predefinito: true);
        $magazzino = $this->rubrica()->aggiungi($account, $this->datiIndirizzo(['label' => 'Magazzino']), predefinito: true);

        $this->assertSame(3, ShippingAddress::where('account_id', $account->id)->count());
        $this->assertSame(
            1,
            ShippingAddress::where('account_id', $account->id)->where('is_default', true)->count(),
            'Due predefiniti vorrebbero dire due destinazioni possibili per lo stesso ordine.'
        );
        $this->assertTrue($magazzino->fresh()->is_default);
        $this->assertFalse($ufficio->fresh()->is_default);
    }

    public function test_cambiare_predefinito_aggiorna_la_copia_sul_conto(): void
    {
        [, $account] = $this->makeBuyer();
        $ufficio = $this->rubrica()->aggiungi($account, $this->datiIndirizzo());

        $this->assertSame('Via Roma 1', $account->fresh()->shipping_address);

        $this->rubrica()->rendiPredefinito($account, $ufficio);

        $this->assertSame('Corso Italia 42', $account->fresh()->shipping_address);
        $this->assertSame('Torino', $account->fresh()->shipping_city);
    }

    public function test_modificare_il_predefinito_riallinea_subito_la_copia_sul_conto(): void
    {
        [, $account] = $this->makeBuyer();
        $casa = $this->rubrica()->predefinito($account);

        $this->rubrica()->modifica($account, $casa, $this->datiIndirizzo([
            'address' => 'Via Verdi 9',
            'city'    => 'Bologna',
        ]));

        $this->assertSame('Via Verdi 9', $account->fresh()->shipping_address);
        $this->assertSame('Bologna', $account->fresh()->shipping_city);
    }

    public function test_eliminare_il_predefinito_promuove_il_piu_recente(): void
    {
        [, $account] = $this->makeBuyer();
        $casa    = $this->rubrica()->predefinito($account);
        $ufficio = $this->rubrica()->aggiungi($account, $this->datiIndirizzo());

        $this->rubrica()->elimina($account, $casa);

        $this->assertTrue($ufficio->fresh()->is_default);
        $this->assertSame('Corso Italia 42', $account->fresh()->shipping_address);
    }

    public function test_eliminare_lultimo_indirizzo_lascia_il_conto_senza_indirizzo(): void
    {
        [, $account] = $this->makeBuyer();
        $casa = $this->rubrica()->predefinito($account);

        $this->rubrica()->elimina($account, $casa);

        $conto = $account->fresh();
        $this->assertFalse($conto->hasShippingAddress(), 'Senza indirizzi non si puo\' spedire da nessuna parte, ed e\' giusto che si veda.');
        $this->assertNull($conto->shipping_address);
    }

    public function test_la_rubrica_di_un_altro_non_si_tocca(): void
    {
        [, $mio]  = $this->makeBuyer();
        [, $suo]  = $this->makeBuyer();
        $suoIndirizzo = $this->rubrica()->predefinito($suo);

        $this->expectExceptionMessage('non appartiene alla tua rubrica');
        $this->rubrica()->elimina($mio, $suoIndirizzo);
    }

    // =========================================================================
    // 2. La pagina rubrica
    // =========================================================================

    public function test_la_pagina_rubrica_mostra_i_propri_indirizzi_e_non_quelli_altrui(): void
    {
        [$io, $mio] = $this->makeBuyer();
        [, $altro]  = $this->makeBuyer();
        $this->rubrica()->aggiungi($altro, $this->datiIndirizzo(['label' => 'Segretissimo', 'address' => 'Vicolo Nascosto 3']));

        $this->actingAs($io)
            ->get(route('portal.shipping-addresses.index'))
            ->assertOk()
            ->assertSee('Via Roma 1')
            ->assertDontSee('Vicolo Nascosto 3');
    }

    public function test_si_aggiunge_un_indirizzo_dalla_pagina_rubrica(): void
    {
        [$user, $account] = $this->makeBuyer();

        $this->actingAs($user)
            ->post(route('portal.shipping-addresses.store'), $this->datiIndirizzo())
            ->assertSessionHas('portal_success');

        $this->assertSame(2, ShippingAddress::where('account_id', $account->id)->count());
        $this->assertSame('Via Roma 1', $account->fresh()->shipping_address, 'Senza spunta non deve diventare il predefinito.');
    }

    public function test_un_indirizzo_senza_destinatario_viene_rifiutato(): void
    {
        [$user, $account] = $this->makeBuyer();

        $this->actingAs($user)
            ->post(route('portal.shipping-addresses.store'), $this->datiIndirizzo(['recipient_name' => '']))
            ->assertSessionHasErrors('recipient_name');

        $this->assertSame(1, ShippingAddress::where('account_id', $account->id)->count());
    }

    public function test_non_si_elimina_un_indirizzo_di_un_altro_conto_dalla_pagina_rubrica(): void
    {
        [$io]      = $this->makeBuyer();
        [, $altro] = $this->makeBuyer();
        $suo = $this->rubrica()->predefinito($altro);

        $this->actingAs($io)
            ->delete(route('portal.shipping-addresses.destroy', $suo))
            ->assertSessionHas('portal_error');

        $this->assertNotNull($suo->fresh(), 'L\'indirizzo dell\'altro conto deve essere ancora li\'.');
    }

    // =========================================================================
    // 3. La scelta in cassa — la parte che muove i pacchi
    // =========================================================================

    private function listingDaSpedire($company): Listing
    {
        return $this->makeListing($company, prezzo: 2000, kyPercentage: 100, extra: [
            'delivery_type' => Listing::DELIVERY_TYPE_SPEDIZIONE,
            'shipping_cost' => 0,
        ]);
    }

    public function test_lordine_parte_verso_lindirizzo_scelto_non_verso_il_predefinito(): void
    {
        [$buyer, $account] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller();
        $ufficio = $this->rubrica()->aggiungi($account, $this->datiIndirizzo());

        $this->actingAs($buyer)->post(route('portal.cart.add', $this->listingDaSpedire($company)));
        $this->actingAs($buyer)->post(route('portal.cart.checkout'), [
            'accetto_condizioni' => '1',
            'indirizzo_scelto'   => (string) $ufficio->id,
        ]);

        $ordine = Order::sole();
        $this->assertSame('Corso Italia 42', $ordine->shipping_address);
        $this->assertSame('Luisa Bianchi', $ordine->shipping_recipient_name);
        $this->assertSame('Via Roma 1', $account->fresh()->shipping_address, 'Scegliere in cassa non cambia il predefinito.');
    }

    public function test_in_cassa_non_si_puo_usare_lindirizzo_di_un_altro(): void
    {
        [$buyer, $account] = $this->makeBuyer(saldo: 100000);
        [, $altro] = $this->makeBuyer();
        [$company] = $this->makeSeller();
        $suo = $this->rubrica()->predefinito($altro);

        $this->actingAs($buyer)->post(route('portal.cart.add', $this->listingDaSpedire($company)));
        $this->actingAs($buyer)
            ->post(route('portal.cart.checkout'), [
                'accetto_condizioni' => '1',
                'indirizzo_scelto'   => (string) $suo->id,
            ])
            ->assertSessionHas('portal_error');

        $this->assertSame(0, Order::count(), 'Nessun ordine deve nascere con l\'indirizzo di un altro.');
        $this->assertSame(100000, $account->fresh()->available_balance);
    }

    public function test_un_nuovo_indirizzo_in_cassa_entra_in_rubrica_e_riceve_lordine(): void
    {
        [$buyer, $account] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller();

        $this->actingAs($buyer)->post(route('portal.cart.add', $this->listingDaSpedire($company)));
        $this->actingAs($buyer)->post(route('portal.cart.checkout'), array_merge(
            ['accetto_condizioni' => '1', 'indirizzo_scelto' => 'nuovo', 'salva_indirizzo' => '1'],
            $this->datiIndirizzo(),
        ));

        $this->assertSame(2, ShippingAddress::where('account_id', $account->id)->count());
        $this->assertSame('Corso Italia 42', Order::sole()->shipping_address);
    }

    public function test_un_nuovo_indirizzo_senza_spunta_vale_solo_per_quellordine(): void
    {
        [$buyer, $account] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller();

        $this->actingAs($buyer)->post(route('portal.cart.add', $this->listingDaSpedire($company)));
        $this->actingAs($buyer)->post(route('portal.cart.checkout'), array_merge(
            ['accetto_condizioni' => '1', 'indirizzo_scelto' => 'nuovo'],   // niente salva_indirizzo
            $this->datiIndirizzo(),
        ));

        $this->assertSame('Corso Italia 42', Order::sole()->shipping_address, 'Il pacco va comunque dove ha chiesto.');
        $this->assertSame(1, ShippingAddress::where('account_id', $account->id)->count(), 'Ma la rubrica non si sporca.');
        $this->assertSame('Via Roma 1', $account->fresh()->shipping_address);
    }

    public function test_un_nuovo_indirizzo_incompleto_blocca_lacquisto(): void
    {
        [$buyer, $account] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller();

        $this->actingAs($buyer)->post(route('portal.cart.add', $this->listingDaSpedire($company)));
        $this->actingAs($buyer)
            ->post(route('portal.cart.checkout'), [
                'accetto_condizioni' => '1',
                'indirizzo_scelto'   => 'nuovo',
                'city'               => 'Napoli',
            ])
            ->assertSessionHas('portal_error');

        $this->assertSame(0, Order::count());
        $this->assertSame(100000, $account->fresh()->available_balance);
    }

    public function test_rubrica_piena_e_spunta_salva_blocca_senza_addebitare(): void
    {
        [$buyer, $account] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller();

        // makeBuyer ne crea gia' 1: ne aggiungo 9 e la rubrica e' piena.
        for ($i = 1; $i <= ShippingAddress::MAX_PER_ACCOUNT - 1; $i++) {
            $this->rubrica()->aggiungi($account, $this->datiIndirizzo(['label' => "Sede {$i}"]));
        }

        $this->actingAs($buyer)->post(route('portal.cart.add', $this->listingDaSpedire($company)));
        $this->actingAs($buyer)
            ->post(route('portal.cart.checkout'), array_merge(
                ['accetto_condizioni' => '1', 'indirizzo_scelto' => 'nuovo', 'salva_indirizzo' => '1'],
                $this->datiIndirizzo(['label' => 'Uno di troppo']),
            ))
            ->assertSessionHas('portal_error', fn ($e) => str_contains((string) $e, 'indirizzi salvati'));

        $this->assertSame(0, Order::count());
        $this->assertSame(100000, $account->fresh()->available_balance);
        $this->assertSame(ShippingAddress::MAX_PER_ACCOUNT, ShippingAddress::where('account_id', $account->id)->count());
    }

    public function test_la_cassa_mostra_TUTTI_gli_indirizzi_salvati_non_i_primi_cinque(): void
    {
        // E' il test anti-Shopify: loro ne salvano quanti ne vuoi ma in cassa
        // te ne mostrano 5, e chi spedisce a dieci sedi deve riscrivere a mano.
        [$buyer, $account] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller();

        for ($i = 1; $i <= ShippingAddress::MAX_PER_ACCOUNT - 1; $i++) {
            $this->rubrica()->aggiungi($account, $this->datiIndirizzo([
                'label'   => "Sede {$i}",
                'address' => "Via Delle Prove {$i}",
            ]));
        }

        $this->actingAs($buyer)->post(route('portal.cart.add', $this->listingDaSpedire($company)));

        $risposta = $this->actingAs($buyer)->get(route('portal.cart.checkout.form'))->assertOk();

        $risposta->assertSee('Via Roma 1');
        for ($i = 1; $i <= ShippingAddress::MAX_PER_ACCOUNT - 1; $i++) {
            $risposta->assertSee("Via Delle Prove {$i}");
        }
    }

    public function test_eliminare_un_indirizzo_non_tocca_un_ordine_gia_fatto(): void
    {
        [$buyer, $account] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller();
        $ufficio = $this->rubrica()->aggiungi($account, $this->datiIndirizzo());

        $this->actingAs($buyer)->post(route('portal.cart.add', $this->listingDaSpedire($company)));
        $this->actingAs($buyer)->post(route('portal.cart.checkout'), [
            'accetto_condizioni' => '1',
            'indirizzo_scelto'   => (string) $ufficio->id,
        ]);

        $this->rubrica()->elimina($account, $ufficio->fresh());

        $ordine = Order::sole()->fresh();
        $this->assertSame('Corso Italia 42', $ordine->shipping_address, 'orders.shipping_* e\' uno snapshot: nessuna cancellazione lo tocca.');
        $this->assertSame('Luisa Bianchi', $ordine->shipping_recipient_name);
    }
}
