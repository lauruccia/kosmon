<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property int $announcement_id
 * @property int $user_id
 * @property int $company_id
 * @property string $message
 * @property bool $is_read
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Announcement $announcement
 * @property-read \App\Models\Company $company
 * @property-read \App\Models\User $user
 * @method static Builder<static>|AnnouncementReply newModelQuery()
 * @method static Builder<static>|AnnouncementReply newQuery()
 * @method static Builder<static>|AnnouncementReply query()
 * @method static Builder<static>|AnnouncementReply unread()
 * @method static Builder<static>|AnnouncementReply whereAnnouncementId($value)
 * @method static Builder<static>|AnnouncementReply whereCompanyId($value)
 * @method static Builder<static>|AnnouncementReply whereCreatedAt($value)
 * @method static Builder<static>|AnnouncementReply whereId($value)
 * @method static Builder<static>|AnnouncementReply whereIsRead($value)
 * @method static Builder<static>|AnnouncementReply whereMessage($value)
 * @method static Builder<static>|AnnouncementReply whereUpdatedAt($value)
 * @method static Builder<static>|AnnouncementReply whereUserId($value)
 * @method static Builder<static>|AnnouncementReply whereUuid($value)
 * @mixin \Eloquent
 */
class AnnouncementReply extends Model
{
    protected $fillable = [
        'uuid',
        'announcement_id',
        'user_id',
        'company_id',
        'message',
        'is_read',
    ];

    protected $casts = [
        'is_read' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (AnnouncementReply $reply): void {
            $reply->uuid ??= (string) Str::uuid();
        });
    }

    // ── Relazioni ─────────────────────────────────────────────────────────────

    public function announcement(): BelongsTo
    {
        return $this->belongsTo(Announcement::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeUnread(Builder $query): Builder
    {
        return $query->where('is_read', false);
    }
}
