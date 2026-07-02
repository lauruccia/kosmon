<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Dati bancari dell'agente per la liquidazione EUR (PII sensibile, tabella separata da users).
 *
 * @property int $id
 * @property string $uuid
 * @property int $agent_user_id
 * @property string $account_holder_name
 * @property string $iban
 * @property string|null $bic_swift
 * @property string|null $bank_name
 * @property string $verification_status
 * @property int|null $verified_by_user_id
 * @property \Illuminate\Support\Carbon|null $verified_at
 * @property-read User $agent
 */
class MlmPaymentDetail extends Model
{
    protected $table = 'mlm_payment_details';

    protected $fillable = [
        'uuid',
        'agent_user_id',
        'account_holder_name',
        'iban',
        'bic_swift',
        'bank_name',
        'verification_status',
        'verified_by_user_id',
        'verified_at',
    ];

    protected $casts = [
        'verified_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $detail): void {
            $detail->uuid ??= (string) Str::uuid();
        });
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_user_id');
    }
}
