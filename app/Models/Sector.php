<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $name
 * @property bool $is_active
 * @property int $sort_order
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Sector newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Sector newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Sector query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Sector whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Sector whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Sector whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Sector whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Sector whereSortOrder($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Sector whereUpdatedAt($value)
 * @mixin \Eloquent
 */
class Sector extends Model
{
    protected $fillable = ['name', 'is_active', 'sort_order'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Attivi, ordinati per sort_order poi nome.
     */
    public static function activeList(): \Illuminate\Support\Collection
    {
        return static::where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->pluck('name');
    }
}
