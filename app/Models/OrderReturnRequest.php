<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * La richiesta di reso: il compratore chiede, il venditore risponde.
 *
 * Giro 2 della fase B (27/08/2026). E' l'unica cosa che il COMPRATORE puo'
 * fare su un ordine gia' partito, ed e' deliberatamente una richiesta e non
 * un'azione: i soldi si muovono solo quando il venditore accetta. Chi compra
 * apre una pratica, non preleva dal conto di chi vende.
 *
 * Decisione di Laura (27/08): il reso riguarda l'ordine INTERO. Il reso di
 * una riga sola vorrebbe dire rimborsi parziali, scorte da rimettere pezzo
 * per pezzo e quota in euro da ricalcolare: si potra' aggiungere dopo senza
 * disfare niente di questo.
 *
 * @property int $id
 * @property string $uuid
 * @property int $order_id
 * @property int|null $requested_by_user_id
 * @property string $status
 * @property string $reason
 * @property int|null $decided_by_user_id
 * @property \Illuminate\Support\Carbon|null $decided_at
 * @property bool $decided_by_admin
 * @property string|null $decision_note
 * @property-read Order $order
 */
class OrderReturnRequest extends Model
{
    use HasFactory;

    /** Il venditore non ha ancora risposto. */
    public const STATUS_PENDING = 'pending';

    /** Accettata: i KY sono tornati indietro e la merce e' rientrata a magazzino. */
    public const STATUS_ACCEPTED = 'accepted';

    /** Rifiutata, con il perche' scritto in `decision_note`. */
    public const STATUS_REJECTED = 'rejected';

    public const STATUSES = [
        self::STATUS_PENDING  => 'In attesa di risposta',
        self::STATUS_ACCEPTED => 'Accettata',
        self::STATUS_REJECTED => 'Rifiutata',
    ];

    protected $fillable = [
        'uuid',
        'order_id',
        'requested_by_user_id',
        'status',
        'reason',
        'decided_by_user_id',
        'decided_at',
        'decided_by_admin',
        'decision_note',
    ];

    protected $casts = [
        'decided_at'       => 'datetime',
        'decided_by_admin' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (OrderReturnRequest $richiesta): void {
            $richiesta->uuid ??= (string) Str::uuid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUSES[$this->status] ?? ucfirst((string) $this->status);
    }
}
