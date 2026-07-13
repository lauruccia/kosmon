<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Riga del ledger punti cliente (PC) di un agente.
 *
 * @property int $id
 * @property string $uuid
 * @property int $agent_user_id
 * @property int $client_user_id
 * @property string $source_type
 * @property int|null $source_transfer_id
 * @property int $points
 * @property \Illuminate\Support\Carbon $valid_from
 * @property \Illuminate\Support\Carbon $valid_until
 * @property-read User $agent
 * @property-read User $client
 */
class MlmPointLedgerEntry extends Model
{
    protected $table = 'mlm_point_ledger';

    protected $fillable = [
        'uuid',
        'agent_user_id',
        'client_user_id',
        'source_type',
        'source_transfer_id',
        'points',
        'valid_from',
        'valid_until',
    ];

    protected $casts = [
        // Datetime (non solo date) dal 2026-07-13: permette all'admin di
        // impostare una scadenza punti in MINUTI (vedi SystemSetting::mlmSettings()
        // e MlmPointsService) per verificare rapidamente il calcolo qualifiche
        // in test, invece di aspettare mesi. Vedi migration
        // 2026_07_13_210200_convert_mlm_point_ledger_to_datetime.
        'valid_from'  => 'datetime',
        'valid_until' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $entry): void {
            $entry->uuid ??= (string) Str::uuid();
        });
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_user_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_user_id');
    }
}
