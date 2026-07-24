<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Metodo di pagamento EUR configurato da un'azienda (o dall'admin per suo
 * conto) per incassare la quota EUR dei prodotti shop con mix KY/EUR.
 *
 * Le credenziali sono quelle dell'account INDIPENDENTE dell'azienda stessa
 * (il proprio Stripe, il proprio PayPal, il proprio IBAN) — Kosmopay le usa
 * solo per generare il pagamento su quel conto, non intermedia mai i soldi.
 *
 * @property int $id
 * @property string $uuid
 * @property int $company_id
 * @property string $provider
 * @property string|null $label
 * @property bool $is_active
 * @property array<string, mixed>|null $credentials
 * @property int|null $created_by_user_id
 * @property int|null $updated_by_user_id
 * @property-read \App\Models\Company $company
 */
class PaymentGateway extends Model
{
    use HasFactory;

    public const PROVIDER_STRIPE = 'stripe';
    public const PROVIDER_PAYPAL = 'paypal';
    public const PROVIDER_BANK_TRANSFER = 'bank_transfer';

    public const PROVIDERS = [
        self::PROVIDER_STRIPE        => 'Stripe',
        self::PROVIDER_PAYPAL        => 'PayPal',
        self::PROVIDER_BANK_TRANSFER => 'Bonifico bancario',
    ];

    /**
     * Campi del form di configurazione per ciascun provider: chiave interna
     * (dentro "credentials"), etichetta, tipo di input, e se è un dato
     * sensibile da non ri-mostrare mai in chiaro dopo il primo salvataggio.
     */
    public const CREDENTIAL_FIELDS = [
        self::PROVIDER_STRIPE => [
            ['key' => 'secret_key', 'label' => 'Chiave segreta Stripe (secret key)', 'type' => 'password', 'sensitive' => true, 'placeholder' => 'sk_live_... oppure sk_test_...'],
        ],
        self::PROVIDER_PAYPAL => [
            ['key' => 'client_id', 'label' => 'Client ID PayPal', 'type' => 'text', 'sensitive' => false, 'placeholder' => ''],
            ['key' => 'client_secret', 'label' => 'Client Secret PayPal', 'type' => 'password', 'sensitive' => true, 'placeholder' => ''],
            ['key' => 'mode', 'label' => 'Ambiente', 'type' => 'select', 'sensitive' => false, 'options' => ['sandbox' => 'Sandbox (prova)', 'live' => 'Live (reale)']],
        ],
        self::PROVIDER_BANK_TRANSFER => [
            ['key' => 'iban', 'label' => 'IBAN', 'type' => 'text', 'sensitive' => false, 'placeholder' => 'IT60 X054 2811 1010 0000 0123 456'],
            ['key' => 'intestatario', 'label' => 'Intestatario conto', 'type' => 'text', 'sensitive' => false, 'placeholder' => ''],
            ['key' => 'banca', 'label' => 'Banca (opzionale)', 'type' => 'text', 'sensitive' => false, 'placeholder' => ''],
            ['key' => 'note', 'label' => 'Note per il pagatore (opzionale)', 'type' => 'text', 'sensitive' => false, 'placeholder' => 'es. causale da indicare'],
        ],
    ];

    protected $fillable = [
        'uuid',
        'company_id',
        'provider',
        'label',
        'is_active',
        'credentials',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    protected $casts = [
        'is_active'   => 'boolean',
        'credentials' => 'encrypted:array',
    ];

    protected static function booted(): void
    {
        static::creating(function (PaymentGateway $gateway): void {
            $gateway->uuid ??= (string) Str::uuid();
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function updatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeForProvider(Builder $query, string $provider): Builder
    {
        return $query->where('provider', $provider);
    }

    public function getProviderLabelAttribute(): string
    {
        return self::PROVIDERS[$this->provider] ?? $this->provider;
    }

    /**
     * true se tutti i campi obbligatori del provider sono valorizzati
     * (i campi bank_transfer "banca"/"note" sono sempre opzionali).
     */
    public function getIsConfiguredAttribute(): bool
    {
        $required = match ($this->provider) {
            self::PROVIDER_STRIPE => ['secret_key'],
            self::PROVIDER_PAYPAL => ['client_id', 'client_secret'],
            self::PROVIDER_BANK_TRANSFER => ['iban', 'intestatario'],
            default => [],
        };

        foreach ($required as $key) {
            if (empty($this->credentials[$key] ?? null)) {
                return false;
            }
        }

        return true;
    }

    public function credential(string $key, mixed $default = null): mixed
    {
        return $this->credentials[$key] ?? $default;
    }
}
