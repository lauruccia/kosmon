<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property \Illuminate\Support\Carbon $period_month
 * @property string $idempotency_key
 * @property string $status
 * @property \Illuminate\Support\Carbon|null $started_at
 * @property \Illuminate\Support\Carbon|null $completed_at
 * @property string|null $error
 * @property-read \Illuminate\Database\Eloquent\Collection<int, MlmCommission> $commissions
 */
class MlmCommissionRun extends Model
{
    protected $table = 'mlm_commission_runs';

    protected $fillable = [
        'uuid',
        'period_month',
        'idempotency_key',
        'status',
        'started_at',
        'completed_at',
        'error',
    ];

    protected $casts = [
        'period_month' => 'date',
        'started_at'   => 'datetime',
        'completed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $run): void {
            $run->uuid ??= (string) Str::uuid();
        });
    }

    public function commissions(): HasMany
    {
        return $this->hasMany(MlmCommission::class, 'mlm_commission_run_id');
    }
}
