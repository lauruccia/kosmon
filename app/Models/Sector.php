<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * @property int $id
 * @property int|null $parent_id
 * @property string $name
 * @property bool $is_active
 * @property int $sort_order
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @mixin \Eloquent
 */
class Sector extends Model
{
    protected $fillable = ['name', 'is_active', 'sort_order', 'parent_id'];

    protected $casts = [
        'is_active' => 'boolean',
        'parent_id' => 'integer',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')
            ->orderBy('sort_order')
            ->orderBy('name');
    }

    /**
     * Una foglia è un settore senza sotto-settori: è selezionabile dalle aziende.
     */
    public function isLeaf(): bool
    {
        return ! $this->children()->exists();
    }

    // ───────────────────────────────────────────────────────────────────────
    // Helper gerarchia (caricano l'intera tabella una sola volta — pochi record)
    // ───────────────────────────────────────────────────────────────────────

    /**
     * Tutti i settori ordinati, indicizzati per id.
     */
    protected static function allOrdered(): Collection
    {
        return static::orderBy('sort_order')->orderBy('name')->get();
    }

    /**
     * Albero appiattito in ordine gerarchico. Ogni Sector riceve gli attributi
     * dinamici: depth (0 = radice), is_leaf (bool), path_label (percorso "A › B › C").
     *
     * @return Collection<int, Sector>
     */
    public static function flattenedTree(): Collection
    {
        $all       = static::allOrdered();
        $byParent  = $all->groupBy(fn ($s) => $s->parent_id ?? 0);
        $parentIds = $all->pluck('parent_id')->filter()->unique()->all();

        $rows = collect();

        $walk = function ($parentKey, int $depth, array $trail) use (&$walk, $byParent, $parentIds, &$rows) {
            foreach ($byParent->get($parentKey, collect()) as $node) {
                $node->setAttribute('depth', $depth);
                $node->setAttribute('is_leaf', ! in_array($node->id, $parentIds, true));
                $node->setAttribute('path_label', implode(' › ', array_merge($trail, [$node->name])));
                $rows->push($node);
                $walk($node->id, $depth + 1, array_merge($trail, [$node->name]));
            }
        };

        $walk(0, 0, []);

        return $rows;
    }

    /**
     * Foglie attive selezionabili, ciascuna con percorso completo come etichetta.
     *
     * @return array<int, array{name: string, label: string}>
     */
    public static function selectableOptions(): array
    {
        return static::flattenedTree()
            ->filter(fn ($s) => $s->is_active && $s->is_leaf)
            ->map(fn ($s) => ['name' => $s->name, 'label' => $s->path_label])
            ->values()
            ->all();
    }

    /**
     * Nomi dei settori selezionabili (foglie attive). Usato per la validazione.
     */
    public static function activeList(): Collection
    {
        return collect(static::selectableOptions())->pluck('name')->values();
    }

    /**
     * IDs dei settori che possono fare da padre (esclude un eventuale sottoalbero
     * da escludere, per evitare cicli in fase di modifica).
     *
     * @return Collection<int, Sector>  flattened tree, utile per le <select> padre
     */
    public static function parentCandidates(?int $excludeSubtreeRootId = null): Collection
    {
        $tree = static::flattenedTree();

        if ($excludeSubtreeRootId === null) {
            return $tree;
        }

        $excluded = static::subtreeIds($excludeSubtreeRootId);

        return $tree->reject(fn ($s) => in_array($s->id, $excluded, true))->values();
    }

    /**
     * IDs del settore indicato e di tutti i suoi discendenti.
     *
     * @return array<int, int>
     */
    public static function subtreeIds(int $rootId): array
    {
        $byParent = static::allOrdered()->groupBy(fn ($s) => $s->parent_id ?? 0);
        $ids      = [];

        $collect = function ($id) use (&$collect, $byParent, &$ids) {
            $ids[] = $id;
            foreach ($byParent->get($id, collect()) as $child) {
                $collect($child->id);
            }
        };

        $collect($rootId);

        return $ids;
    }
}
