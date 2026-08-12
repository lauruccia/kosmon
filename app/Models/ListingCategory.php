<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Categoria (o sotto-categoria) prodotto per lo shop del circuito.
 *
 * A differenza di Sector (dove solo le "foglie" sono selezionabili e i nodi
 * con figli fanno solo da raggruppamento), qui la categoria di primo livello
 * e' SEMPRE selezionabile da sola: la sotto-categoria e' un dettaglio in piu'
 * facoltativo (richiesta di Laura, 2026-08-12). Struttura fissa a 2 livelli:
 * una sotto-categoria non puo' avere a sua volta sotto-categorie (vedi
 * AdminListingCategoryController).
 *
 * Lo "slug" e' l'identificativo STABILE salvato su listings.category /
 * listings.subcategory: rinominare il "name" dal pannello admin non scollega
 * mai i prodotti gia' assegnati a quella categoria.
 *
 * @property int $id
 * @property int|null $parent_id
 * @property string $slug
 * @property string $name
 * @property bool $is_active
 * @property int $sort_order
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @mixin \Eloquent
 */
class ListingCategory extends Model
{
    protected $fillable = ['parent_id', 'slug', 'name', 'is_active', 'sort_order'];

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

    public function isLeaf(): bool
    {
        return ! $this->children()->exists();
    }

    /**
     * Genera uno slug univoco a partire dal nome (es. "Arte e intrattenimento"
     * -> "arte-e-intrattenimento"), aggiungendo -2, -3... in caso di collisione.
     * Usato SOLO in creazione: una volta assegnato, lo slug non cambia mai piu'
     * (vedi nota di classe).
     */
    public static function makeUniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'categoria';
        $slug = $base;
        $i = 2;
        while (static::where('slug', $slug)->exists()) {
            $slug = "{$base}-{$i}";
            $i++;
        }
        return $slug;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Helper gerarchia a 2 livelli (categoria / sotto-categoria)
    // ─────────────────────────────────────────────────────────────────────

    protected static function allOrdered(): Collection
    {
        return static::orderBy('sort_order')->orderBy('name')->get();
    }

    /**
     * Albero appiattito per la lista admin, con attributi dinamici depth (0 o
     * 1), is_leaf e path_label (stesso schema di Sector::flattenedTree()).
     */
    public static function flattenedTree(): Collection
    {
        $all      = static::allOrdered();
        $byParent = $all->groupBy(fn ($c) => $c->parent_id ?? 0);
        $rows     = collect();

        $walk = function ($parentKey, int $depth, array $trail) use (&$walk, $byParent, &$rows) {
            foreach ($byParent->get($parentKey, collect()) as $node) {
                $node->setAttribute('depth', $depth);
                $node->setAttribute('is_leaf', ! $byParent->has($node->id));
                $node->setAttribute('path_label', implode(' › ', array_merge($trail, [$node->name])));
                $rows->push($node);
                $walk($node->id, $depth + 1, array_merge($trail, [$node->name]));
            }
        };

        $walk(0, 0, []);

        return $rows;
    }

    /**
     * Categorie di primo livello attive, per i menu a tendina del form
     * pubblico/admin.
     *
     * @return Collection<int, ListingCategory>
     */
    public static function activeTopLevelOptions(): Collection
    {
        return static::whereNull('parent_id')
            ->where('is_active', true)
            ->orderBy('sort_order')->orderBy('name')
            ->get(['id', 'slug', 'name']);
    }

    /**
     * Mappa slug-categoria => elenco sotto-categorie attive [['slug'=>..,
     * 'name'=>..], ...], per popolare via JS la select dipendente
     * "Sotto-categoria" senza ricaricare la pagina.
     *
     * @return array<string, array<int, array{slug: string, name: string}>>
     */
    public static function activeSubcategoriesBySlug(): array
    {
        $categories = static::whereNull('parent_id')->get(['id', 'slug']);
        $children   = static::whereNotNull('parent_id')
            ->where('is_active', true)
            ->orderBy('sort_order')->orderBy('name')
            ->get(['parent_id', 'slug', 'name']);

        return $categories->mapWithKeys(function (self $cat) use ($children) {
            $subs = $children->where('parent_id', $cat->id)
                ->map(fn (self $c) => ['slug' => $c->slug, 'name' => $c->name])
                ->values()->all();

            return [$cat->slug => $subs];
        })->all();
    }

    /**
     * Slug delle categorie di primo livello selezionabili: quelle attive, piu'
     * (se passato) lo slug attualmente assegnato al prodotto in modifica anche
     * se nel frattempo e' stato disattivato — altrimenti salvare un prodotto
     * SENZA cambiarne la categoria fallirebbe la validazione (stesso
     * ragionamento di Sector::activeList() per le aziende che usano gia' un
     * settore disattivato).
     *
     * @return array<int, string>
     */
    public static function selectableTopLevelSlugs(?string $keepCurrent = null): array
    {
        $slugs = static::whereNull('parent_id')->where('is_active', true)->pluck('slug')->all();

        if ($keepCurrent !== null && $keepCurrent !== '' && ! in_array($keepCurrent, $slugs, true)) {
            $slugs[] = $keepCurrent;
        }

        return $slugs;
    }

    /**
     * Slug delle sotto-categorie selezionabili per una data categoria (slug
     * del padre): attive + eventuale sotto-categoria gia' assegnata (stesso
     * ragionamento di selectableTopLevelSlugs()).
     *
     * @return array<int, string>
     */
    public static function selectableSubSlugs(string $categorySlug, ?string $keepCurrent = null): array
    {
        $parent = static::whereNull('parent_id')->where('slug', $categorySlug)->first();

        if (! $parent) {
            return ($keepCurrent !== null && $keepCurrent !== '') ? [$keepCurrent] : [];
        }

        $slugs = static::where('parent_id', $parent->id)->where('is_active', true)->pluck('slug')->all();

        if ($keepCurrent !== null && $keepCurrent !== '' && ! in_array($keepCurrent, $slugs, true)) {
            $slugs[] = $keepCurrent;
        }

        return $slugs;
    }

    /**
     * Etichetta leggibile per uno slug di categoria o sotto-categoria, con
     * fallback al valore grezzo se non trovato — una categoria nel frattempo
     * eliminata non deve mai rompere una view, deve solo mostrare il vecchio
     * testo grezzo (stesso ragionamento di Listing::statusLabel()).
     */
    public static function labelFor(?string $slug): ?string
    {
        if ($slug === null || $slug === '') {
            return null;
        }

        return static::where('slug', $slug)->value('name') ?? $slug;
    }

    /**
     * IDs della categoria indicata e di tutte le sue discendenti (usato per
     * evitare cicli quando si sposta una categoria sotto un'altra).
     *
     * @return array<int, int>
     */
    public static function subtreeIds(int $rootId): array
    {
        $byParent = static::allOrdered()->groupBy(fn ($c) => $c->parent_id ?? 0);
        $ids = [];

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
