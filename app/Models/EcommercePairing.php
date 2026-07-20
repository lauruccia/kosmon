<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Richiesta di collegamento di un plugin e-commerce (WooCommerce) al conto
 * KMoney tramite il solo numero di conto.
 *
 * Flusso:
 *   1. Il plugin invia numero di conto + URL sito + URL webhook + claim_secret
 *      (POST /api/v1/ecommerce/pairings) → pairing "pending".
 *   2. L'admin approva da /admin/companies/{id}: vengono creati token API
 *      (read+write) e webhook payment_request.paid; le credenziali in chiaro
 *      restano cifrate qui finché il plugin non le ritira.
 *   3. Il plugin verifica lo stato (GET, autenticato col claim_secret): alla
 *      prima verifica dopo l'approvazione riceve le credenziali UNA SOLA
 *      VOLTA (claimed_at valorizzato, campo credenziali azzerato).
 *
 * @property int $id
 * @property string $uuid
 * @property int $company_id
 * @property int $account_id
 * @property string $account_number
 * @property string $site_url
 * @property string $webhook_url
 * @property string $platform
 * @property string $claim_secret_hash
 * @property string $status
 * @property int|null $api_token_id
 * @property int|null $webhook_id
 * @property array<array-key, mixed>|null $credentials
 * @property int|null $approved_by
 * @property \Illuminate\Support\Carbon|null $approved_at
 * @property \Illuminate\Support\Carbon|null $claimed_at
 * @property string|null $created_ip
 */
class EcommercePairing extends Model
{
    public const STATUS_PENDING  = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'uuid', 'company_id', 'account_id', 'account_number',
        'site_url', 'webhook_url', 'platform', 'claim_secret_hash',
        'status', 'api_token_id', 'webhook_id', 'credentials',
        'approved_by', 'approved_at', 'claimed_at', 'created_ip',
    ];

    protected $casts = [
        // Token API e secret webhook in chiaro, cifrati a riposo con APP_KEY,
        // presenti solo tra approvazione e ritiro da parte del plugin.
        'credentials' => 'encrypted:array',
        'approved_at' => 'datetime',
        'claimed_at'  => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $m): void {
            $m->uuid ??= (string) Str::uuid();
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function apiToken(): BelongsTo
    {
        return $this->belongsTo(ApiToken::class);
    }

    public function webhook(): BelongsTo
    {
        return $this->belongsTo(Webhook::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function matchesClaimSecret(string $claimSecret): bool
    {
        return hash_equals($this->claim_secret_hash, hash('sha256', $claimSecret));
    }
}
