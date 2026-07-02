<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property int $agent_user_id
 * @property string $rank
 * @property \Illuminate\Support\Carbon $achieved_at
 * @property array<string,mixed>|null $evaluation_snapshot
 * @property-read User $agent
 */
class MlmRankHistory extends Model
{
    protected $table = 'mlm_rank_history';

    protected $fillable = [
        'uuid',
        'agent_user_id',
        'rank',
        'achieved_at',
        'evaluation_snapshot',
    ];

    protected $casts = [
        'achieved_at'          => 'datetime',
        'evaluation_snapshot'  => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $history): void {
            $history->uuid ??= (string) Str::uuid();
        });
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_user_id');
    }
}
