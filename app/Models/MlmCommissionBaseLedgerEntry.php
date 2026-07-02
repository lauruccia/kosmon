<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Riga del ledger "importo mensile" commissionabile (smoothing EUR),
 * parallelo a MlmPointLedgerEntry ma per la base delle commissioni.
 *
 * @property int $id
 * @property string $uuid
 * @property int $client_user_id
 * @property int $direct_agent_id
 * @property int|null $source_transfer_id
 * @property int $monthly_amount_eur_cents
 * @property \Illuminate\Support\Carbon $valid_from
 * @property \Illuminate\Support\Carbon $valid_until
 * @property-read User $client
 * @property-read User $directAgent
 */
class MlmCommissionBaseLedgerEntry extends Model
{
    protected $table = 'mlm_commission_base_ledger';

    protected $fillable = [
        'uuid',
        'client_user_id',
        'direct_agent_id',
        'source_transfer_id',
        'monthly_amount_eur_cents',
        'valid_from',
        'valid_until',
    ];

    protected $casts = [
        'valid_from' => 'date',
        'valid_until' => 'date',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $entry): void {
            $entry->uuid ??= (string) Str::uuid();
        });
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_user_id');
    }

    public function directAgent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'direct_agent_id');
    }
}
