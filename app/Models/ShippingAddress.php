<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un indirizzo della rubrica di spedizione di un conto (fase A-bis, 26/08/2026).
 *
 * Il tetto e' 10 per conto: non e' un limite tecnico, e' il punto oltre il
 * quale la tendina in cassa smette di essere leggibile. A differenza di
 * Shopify — che ne salva quanti ne vuoi ma in cassa te ne mostra solo 5 —
 * qui **tutti** gli indirizzi salvati sono scegliibili al momento di pagare:
 * un indirizzo che non si puo' usare non serve a niente.
 *
 * Chi scrive qui dentro e' `ShippingAddressBook`, mai i controller
 * direttamente: e' quel servizio a garantire che il predefinito sia uno solo e
 * che la copia su `accounts.shipping_*` resti allineata.
 *
 * @property int         $id
 * @property int         $account_id
 * @property string|null $label
 * @property string      $recipient_name
 * @property string      $address
 * @property string      $city
 * @property string      $postal_code
 * @property string|null $province
 * @property string|null $phone
 * @property bool        $is_default
 */
class ShippingAddress extends Model
{
    use HasFactory;

    /** Quanti indirizzi puo' tenere in rubrica un conto. */
    public const MAX_PER_ACCOUNT = 10;

    protected $fillable = [
        'account_id',
        'label',
        'recipient_name',
        'address',
        'city',
        'postal_code',
        'province',
        'phone',
        'is_default',
    ];

    protected $casts = [
        'is_default' => 'boolean',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** Prima il predefinito, poi i piu' recenti. */
    public function scopeInOrdineDiRubrica(Builder $query): Builder
    {
        return $query->orderByDesc('is_default')->orderByDesc('id');
    }

    /**
     * I campi nel formato che si usa su `accounts.shipping_*` e su
     * `orders.shipping_*` — cioe' con il prefisso. Un posto solo dove sta
     * scritta la corrispondenza fra i due nomi.
     *
     * @return array<string, string|null>
     */
    public function comeCampiShipping(): array
    {
        return [
            'shipping_recipient_name' => $this->recipient_name,
            'shipping_address'        => $this->address,
            'shipping_city'           => $this->city,
            'shipping_postal_code'    => $this->postal_code,
            'shipping_province'       => $this->province,
            'shipping_phone'          => $this->phone,
        ];
    }

    /** Come si legge nella tendina: "Casa — Via Roma 1, Milano". */
    public function getEtichettaCompletaAttribute(): string
    {
        $luogo = $this->address . ', ' . $this->city;

        return filled($this->label) ? $this->label . ' — ' . $luogo : $luogo;
    }

    /**
     * Le righe da stampare, come `Account::shipping_address_lines`.
     *
     * @return array<int, string>
     */
    public function getRigheAttribute(): array
    {
        $citta = trim($this->postal_code . ' ' . $this->city
            . ($this->province ? ' (' . $this->province . ')' : ''));

        $righe = [$this->recipient_name, $this->address, $citta];

        if ($this->phone) {
            $righe[] = 'Tel. ' . $this->phone;
        }

        return $righe;
    }
}
