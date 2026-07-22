<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Requisiti configurabili per ciascuna qualifica agente (Basic..Manager).
 * Fonte storica dei valori di default: slide "Qualifiche" KNM ufficiale
 * (vedi MlmRankEngine e memoria mlm_qualifiche_retrocessione). Editabili da
 * admin (/admin/mlm-impostazioni) per permettere test rapidi senza toccare
 * il codice — introdotto il 2026-07-13 su richiesta di Laura.
 *
 * @property int $id
 * @property string $rank
 * @property int $min_points
 * @property int $min_clients
 * @property int $min_level1_basic
 * @property int $min_branches_with_key
 * @property int $min_branches_with_senior
 * @property int $min_branches_with_top
 * @property int $min_branches_with_supervisor
 * @property int $min_branches_300pt
 */
class MlmRankRequirement extends Model
{
    protected $table = 'mlm_rank_requirements';

    protected $fillable = [
        'rank',
        'min_points',
        'min_clients',
        'min_level1_basic',
        'min_branches_with_key',
        'min_branches_with_senior',
        'min_branches_with_top',
        'min_branches_with_supervisor',
        'min_branches_300pt',
    ];

    private const CACHE_KEY = 'mlm_rank_requirements';

    /**
     * Tutti i requisiti configurati, indicizzati per rank. Cachati
     * indefinitamente (i dati cambiano solo dal form admin, che invalida
     * esplicitamente la cache al salvataggio) — MlmRankEngine::evaluate()
     * la interroga per ogni agente valutato, anche in massa nel job notturno.
     *
     * @return Collection<string, self>
     */
    public static function allByRank(): Collection
    {
        return Cache::rememberForever(self::CACHE_KEY, fn () => static::query()->get()->keyBy('rank'));
    }

    public static function forgetCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    protected static function booted(): void
    {
        static::saved(fn () => self::forgetCache());
        static::deleted(fn () => self::forgetCache());
    }
}
