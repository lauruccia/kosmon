<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase A-bis (26/08/2026): la rubrica degli indirizzi di spedizione.
 *
 * Fino a ieri il conto aveva UN indirizzo, nelle colonne `accounts.shipping_*`.
 * Chi spedisce a casa e in ufficio doveva riscriverlo ogni volta.
 *
 * Da qui in poi un conto ha una RUBRICA (massimo 10 indirizzi, tetto scelto da
 * Laura), uno dei quali predefinito. Le colonne `accounts.shipping_*` NON
 * spariscono: restano come **copia del predefinito**, cosi'
 * `Account::hasShippingAddress()`, `shipping_address_lines`, i form del profilo
 * e `OrderService` continuano a funzionare senza sapere niente della rubrica.
 * La copia la tiene allineata `ShippingAddressBook`, che e' l'unico posto da
 * cui si scrive.
 *
 * Gli ordini gia' fatti non c'entrano nulla: `orders.shipping_*` e' uno
 * snapshot preso al momento dell'acquisto, quindi cancellare un indirizzo dalla
 * rubrica non tocca nessun ordine passato.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipping_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();

            // L'etichetta e' quella che si legge nella tendina in cassa:
            // "Casa", "Ufficio", "Magazzino". Facoltativa — senza, si mostra
            // la via.
            $table->string('label', 60)->nullable();

            $table->string('recipient_name', 150);
            $table->string('address', 255);
            $table->string('city', 100);
            $table->string('postal_code', 12);
            $table->string('province', 60)->nullable();
            $table->string('phone', 30)->nullable();

            $table->boolean('is_default')->default(false);
            $table->timestamps();

            // "gli indirizzi di questo conto, prima il predefinito, poi i piu'
            // recenti" e' l'unica lettura che facciamo davvero.
            $table->index(['account_id', 'is_default']);
        });

        // Backfill: l'indirizzo che ogni conto ha gia' diventa il primo della
        // sua rubrica, marcato predefinito. Nessuno si accorge di niente.
        // A blocchi, perche' i conti sono migliaia.
        DB::table('accounts')
            ->select([
                'id', 'shipping_recipient_name', 'shipping_address', 'shipping_city',
                'shipping_postal_code', 'shipping_province', 'shipping_phone',
            ])
            ->whereNotNull('shipping_recipient_name')
            ->whereNotNull('shipping_address')
            ->whereNotNull('shipping_city')
            ->whereNotNull('shipping_postal_code')
            ->orderBy('id')
            ->chunk(500, function ($conti) {
                $ora = now();
                $righe = [];

                foreach ($conti as $conto) {
                    // Gli stessi quattro campi che Account::hasShippingAddress()
                    // considera indispensabili: se uno e' una stringa vuota
                    // l'indirizzo non e' utilizzabile e non va in rubrica.
                    if (trim((string) $conto->shipping_recipient_name) === ''
                        || trim((string) $conto->shipping_address) === ''
                        || trim((string) $conto->shipping_city) === ''
                        || trim((string) $conto->shipping_postal_code) === '') {
                        continue;
                    }

                    $righe[] = [
                        'account_id'     => $conto->id,
                        'label'          => null,
                        'recipient_name' => $conto->shipping_recipient_name,
                        'address'        => $conto->shipping_address,
                        'city'           => $conto->shipping_city,
                        'postal_code'    => $conto->shipping_postal_code,
                        'province'       => $conto->shipping_province,
                        'phone'          => $conto->shipping_phone,
                        'is_default'     => true,
                        'created_at'     => $ora,
                        'updated_at'     => $ora,
                    ];
                }

                if ($righe !== []) {
                    DB::table('shipping_addresses')->insert($righe);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipping_addresses');
    }
};
