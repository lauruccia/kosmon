<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property int $company_id
 * @property int $created_by_user_id
 * @property string $type
 * @property string $title
 * @property string $body
 * @property string $sector
 * @property string|null $contact_info
 * @property string $status
 * @property bool $featured
 * @property \Illuminate\Support\Carbon|null $expires_at
 * @property int $views_count
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Company $company
 * @property-read \App\Models\User $createdByUser
 * @property-read bool $is_expired
 * @property-read string $sector_label
 * @property-read string $type_label
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\AnnouncementReply> $replies
 * @property-read int|null $replies_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\AnnouncementReply> $unreadReplies
 * @property-read int|null $unread_replies_count
 * @method static Builder<static>|Announcement active()
 * @method static Builder<static>|Announcement featured()
 * @method static Builder<static>|Announcement inSector(string $sector)
 * @method static Builder<static>|Announcement newModelQuery()
 * @method static Builder<static>|Announcement newQuery()
 * @method static Builder<static>|Announcement ofType(string $type)
 * @method static Builder<static>|Announcement query()
 * @method static Builder<static>|Announcement whereBody($value)
 * @method static Builder<static>|Announcement whereCompanyId($value)
 * @method static Builder<static>|Announcement whereContactInfo($value)
 * @method static Builder<static>|Announcement whereCreatedAt($value)
 * @method static Builder<static>|Announcement whereCreatedByUserId($value)
 * @method static Builder<static>|Announcement whereExpiresAt($value)
 * @method static Builder<static>|Announcement whereFeatured($value)
 * @method static Builder<static>|Announcement whereId($value)
 * @method static Builder<static>|Announcement whereSector($value)
 * @method static Builder<static>|Announcement whereStatus($value)
 * @method static Builder<static>|Announcement whereTitle($value)
 * @method static Builder<static>|Announcement whereType($value)
 * @method static Builder<static>|Announcement whereUpdatedAt($value)
 * @method static Builder<static>|Announcement whereUuid($value)
 * @method static Builder<static>|Announcement whereViewsCount($value)
 * @mixin \Eloquent
 */
class Announcement extends Model
{
    use HasFactory;

    public const TYPES = [
        'offer'   => 'Offerta',
        'request' => 'Richiesta',
    ];

    // Condivide gli stessi slug-settori delle Listing per coerenza
    public const SECTORS = [
        'alimentari'  => 'Alimentari & Ristorazione',
        'artigianato' => 'Artigianato & Manifattura',
        'consulenza'  => 'Consulenza & Servizi professionali',
        'formazione'  => 'Formazione & Educazione',
        'informatica' => 'Informatica & Tecnologia',
        'logistica'   => 'Logistica & Trasporti',
        'marketing'   => 'Marketing & Comunicazione',
        'salute'      => 'Salute & Benessere',
        'turismo'     => 'Turismo & Hospitality',
        'verde'       => 'Verde & Ambiente',
        'altro'       => 'Altro',
    ];

    public const STATUSES = ['active', 'suspended', 'expired', 'draft'];

    protected $fillable = [
        'uuid',
        'company_id',
        'created_by_user_id',
        'type',
        'title',
        'body',
        'sector',
        'contact_info',
        'status',
        'featured',
        'expires_at',
        'views_count',
    ];

    protected $casts = [
        'featured'    => 'boolean',
        'expires_at'  => 'datetime',
        'views_count' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (Announcement $announcement): void {
            $announcement->uuid ??= (string) Str::uuid();
        });
    }

    // ── Relazioni ─────────────────────────────────────────────────────────────

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(AnnouncementReply::class)->latest();
    }

    public function unreadReplies(): HasMany
    {
        return $this->hasMany(AnnouncementReply::class)->where('is_read', false);
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active')
                     ->where(function ($q) {
                         $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
                     });
    }

    public function scopeFeatured(Builder $query): Builder
    {
        return $query->where('featured', true);
    }

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    public function scopeInSector(Builder $query, string $sector): Builder
    {
        return $query->where('sector', $sector);
    }

    // ── Accessors ─────────────────────────────────────────────────────────────

    public function getSectorLabelAttribute(): string
    {
        return self::SECTORS[$this->sector] ?? $this->sector;
    }

    public function getTypeLabelAttribute(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    public function getIsExpiredAttribute(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
