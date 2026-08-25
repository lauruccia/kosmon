<?php

namespace Tests\Concerns;

use App\Models\Account;
use App\Models\Company;
use App\Models\Listing;
use App\Models\PaymentGateway;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Costruttori di scenari per i test dello shop.
 *
 * Estratti da ShopPurchaseRegressionTest (fase 0a) il 25/08/2026, all'inizio
 * del lavoro su carrello e prodotti variabili: gli stessi acquirenti, venditori
 * e prodotti servono adesso a più file di test, e duplicarli avrebbe voluto
 * dire che una modifica allo schema andava inseguita in due posti.
 *
 * Qui dentro non c'è nessuna asserzione e nessuna regola di business: solo
 * righe di database messe in piedi nel modo in cui l'applicazione le crea.
 */
trait BuildsShopScenarios
{
    /**
     * Acquirente privato con conto proprio e (di default) indirizzo di
     * spedizione completo.
     *
     * @return array{0: User, 1: Account}
     */
    protected function makeBuyer(int $saldo = 100000, bool $conIndirizzo = true): array
    {
        $user = User::create([
            'name'                => 'Mario Rossi',
            'email'               => 'buyer-' . Str::random(8) . '@test.test',
            'password'            => 'secret123',
            'account_holder_type' => 'private',
            'company_id'          => null,
            'role'                => 'private-owner',
            'is_active'           => true,
            'is_super_admin'      => false,
            'email_verified_at'   => now(),
            'contract_signed_at'  => now(),
        ]);

        $account = Account::create([
            'owner_user_id'     => $user->id,
            'owner_type'        => 'private',
            'type'              => 'member',
            'status'            => 'active',
            'available_balance' => $saldo,
        ]);

        if ($conIndirizzo) {
            $account->forceFill([
                'shipping_recipient_name' => 'Mario Rossi',
                'shipping_address'        => 'Via Roma 1',
                'shipping_city'           => 'Milano',
                'shipping_postal_code'    => '20100',
                'shipping_province'       => 'MI',
                'shipping_phone'          => '3331234567',
            ])->save();
        }

        return [$user->fresh(), $account->fresh()];
    }

    /**
     * Azienda venditrice con il suo utente proprietario e il conto business
     * principale (quello su cui incassa gli ordini shop).
     *
     * @return array{0: Company, 1: User, 2: Account}
     */
    protected function makeSeller(int $saldo = 0): array
    {
        $slug = 'seller-' . Str::random(6);

        $company = Company::create([
            'name'          => 'Venditore Test ' . Str::random(4),
            'slug'          => $slug,
            'email'         => $slug . '@test.test',
            'status'        => 'active',
            'kyc_status'    => 'approved',
            'currency_code' => 'KY',
            'sector'        => 'informatica',
            'description'   => 'Azienda di test',
        ]);

        $account = Account::create([
            'company_id'        => $company->id,
            'owner_type'        => 'company',
            'type'              => 'member',
            'status'            => 'active',
            'available_balance' => $saldo,
            'is_system_account' => false,
        ]);

        $user = User::create([
            'name'                => 'Titolare ' . $company->name,
            'email'               => 'owner-' . Str::random(8) . '@test.test',
            'password'            => 'secret123',
            'account_holder_type' => 'company',
            'company_id'          => $company->id,
            'role'                => 'owner',
            'is_active'           => true,
            'is_super_admin'      => false,
            'email_verified_at'   => now(),
            'contract_signed_at'  => now(),
        ]);

        $account->forceFill(['owner_user_id' => $user->id])->save();

        return [$company->fresh(), $user->fresh(), $account->fresh()];
    }

    protected function makeGateway(Company $company): PaymentGateway
    {
        return PaymentGateway::create([
            'company_id'  => $company->id,
            'provider'    => PaymentGateway::PROVIDER_BANK_TRANSFER,
            'is_active'   => true,
            'credentials' => [
                'iban'         => 'IT60X0542811101000000123456',
                'intestatario' => $company->name,
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $extra
     */
    protected function makeListing(Company $company, int $prezzo, int $kyPercentage, array $extra = []): Listing
    {
        return Listing::create(array_merge([
            'company_id'         => $company->id,
            'created_by_user_id' => $company->users()->value('id') ?? User::query()->where('company_id', $company->id)->value('id'),
            'title'              => 'Prodotto di prova ' . Str::random(4),
            'description'        => 'Descrizione del prodotto di prova.',
            'category'           => 'informatica',
            'price_ky'           => $prezzo,
            'ky_percentage'      => $kyPercentage,
            'status'             => 'active',
            'delivery_type'      => Listing::DELIVERY_TYPE_SERVIZIO,
        ], $extra));
    }

    /**
     * Un attributo con i suoi valori, come li creerebbe l'admin.
     * makeAttributo('Taglia', ['S', 'M', 'L']) -> ['S' => Value, 'M' => ..., ...]
     *
     * @param  array<int, string>  $valori
     * @return array<string, \App\Models\ListingAttributeValue>
     */
    protected function makeAttributo(string $nome, array $valori): array
    {
        $attributo = \App\Models\ListingAttribute::create(['name' => $nome]);

        $creati = [];
        foreach (array_values($valori) as $i => $valore) {
            $creati[$valore] = \App\Models\ListingAttributeValue::create([
                'listing_attribute_id' => $attributo->id,
                'value'                => $valore,
                'sort_order'           => $i,
            ]);
        }

        return $creati;
    }

    /**
     * Una combinazione acquistabile di un prodotto, con il suo delta di prezzo
     * e le sue scorte. Marca automaticamente il prodotto come variabile.
     *
     * @param  array<int, \App\Models\ListingAttributeValue>  $valori
     */
    protected function makeVariante(
        Listing $listing,
        array $valori,
        int $deltaKy = 0,
        ?int $scorte = null,
    ): \App\Models\ListingVariant {
        $variante = \App\Models\ListingVariant::create([
            'listing_id'     => $listing->id,
            'price_delta_ky' => $deltaKy,
            'stock_quantity' => $scorte,
        ]);

        $variante->values()->sync(collect($valori)->pluck('id')->all());

        if (! $listing->has_variants) {
            $listing->forceFill(['has_variants' => true])->save();
        }

        return $variante->fresh(['values.attribute', 'listing']);
    }

    /**
     * Da' all'utente il permesso `marketplace.buy`, quello che il menu laterale
     * richiede per mostrare le voci dello shop (User::canAccessMarketplace()).
     *
     * Serve solo ai test che guardano il MENU: le rotte dello shop funzionano
     * anche senza, ed e' per questo che tutti gli altri test non ne hanno
     * bisogno.
     */
    protected function abilitaMarketplace(User $user): void
    {
        $permesso = \App\Models\Permission::firstOrCreate(
            ['slug' => 'marketplace.buy'],
            ['name' => 'Buy in marketplace']
        );

        $ruolo = \App\Models\Role::firstOrCreate(
            ['slug' => 'compratore-test'],
            ['name' => 'Compratore', 'scope' => 'portal']
        );

        $ruolo->permissions()->syncWithoutDetaching([$permesso->id]);
        $user->roles()->syncWithoutDetaching([$ruolo->id]);
        $user->load('roles.permissions');
    }

    /**
     * Somma algebrica di TUTTI i saldi del circuito, conto sistema incluso.
     *
     * Un movimento sposta denaro: non ne crea e non ne distrugge. Qualunque
     * cosa faccia l'acquisto, questa somma deve valere prima e dopo lo stesso
     * identico numero — è l'invariante del circuito chiuso, verificata in
     * produzione ogni notte da `accounting:verify-integrity`.
     */
    protected function sommaSaldiCircuito(): int
    {
        return (int) Account::query()->sum('available_balance');
    }
}
