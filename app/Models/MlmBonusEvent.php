<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property int $basiq_user_id
 * @property \Illuminate\Support\Carbon $triggered_at
 * @property array<int,mixed>|null $upline_chain_snapshot
 * @property string $status
 * @property \Illuminate\Support\Carbon|null $processed_at
 * @property-read User $basiqUser
 * @property-read \Illuminate\Database\Eloquent\Collection<int, MlmBonusPayout> $payouts
 */
class MlmBonusEvent extends Model
{
    protected $table = 'mlm_bonus_events';

    protected $fillable = [
        'uuid',
        'basiq_user_id',
        'triggered_at',
        'upline_chain_snapshot',
        'status',
        'processed_at',
    ];

    protected $casts = [
        'triggered_at'           => 'datetime',
        'upline_chain_snapshot'  => 'array',
        'processed_at'           => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $event): void {
            $event->uuid ??= (string) Str::uuid();
        });
    }

    public function basiqUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'basiq_user_id');
    }

    public function payouts(): HasMany
    {
        return $this->hasMany(MlmBonusPayout::class, 'mlm_bonus_event_id');
    }
}
