<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

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
