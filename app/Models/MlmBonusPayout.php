<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property int $mlm_bonus_event_id
 * @property int $beneficiary_user_id
 * @property string|null $rank_at_time
 * @property string $kind
 * @property int $amount_eur_cents
 * @property \Illuminate\Support\Carbon $week_ending
 * @property string $status
 * @property string $idempotency_key
 * @property-read User $beneficiary
 * @property-read MlmBonusEvent $event
 */
class MlmBonusPayout extends Model
{
    protected $table = 'mlm_bonus_payouts';

    protected $fillable = [
        'uuid',
        'mlm_bonus_event_id',
        'beneficiary_user_id',
        'rank_at_time',
        'kind',
        'amount_eur_cents',
        'week_ending',
        'status',
        'mlm_payout_id',
        'idempotency_key',
    ];

    protected $casts = [
        'week_ending' => 'date',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $payout): void {
            $payout->uuid ??= (string) Str::uuid();
        });
    }

    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(User::class, 'beneficiary_user_id');
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(MlmBonusEvent::class, 'mlm_bonus_event_id');
    }

    public function payout(): BelongsTo
    {
        return $this->belongsTo(MlmPayout::class, 'mlm_payout_id');
    }
}
