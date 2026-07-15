<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Coda delle promozioni di grado in attesa dell'erogazione dell'Extra Bonus
 * (MlmAwardService::RANK_AWARDS_EUR_CENTS). Una riga viene creata da
 * MlmRankEngine::syncRank() al momento della promozione (rilevamento
 * notturno) e consumata dal job settimanale `mlm:calculate-weekly-bonuses`
 * (MlmAwardService::processPendingRankAwards), che eroga il premio vero e
 * proprio in mlm_bonus_payouts. Vedi MlmAwardService per il perche' della
 * separazione rilevamento/erogazione.
 *
 * @property int $id
 * @property int $user_id
 * @property string $rank
 * @property \Illuminate\Support\Carbon $detected_at
 * @property \Illuminate\Support\Carbon|null $processed_at
 * @property-read User $user
 */
class MlmPendingRankAward extends Model
{
    protected $table = 'mlm_pending_rank_awards';

    protected $fillable = [
        'user_id',
        'rank',
        'detected_at',
        'processed_at',
    ];

    protected $casts = [
        'detected_at' => 'datetime',
        'processed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
