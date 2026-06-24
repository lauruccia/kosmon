<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property int $company_id
 * @property int $uploaded_by_user_id
 * @property string $type
 * @property string $file_path
 * @property string $original_name
 * @property string|null $mime_type
 * @property int|null $file_size
 * @property string $status
 * @property string|null $admin_notes
 * @property int|null $reviewed_by_user_id
 * @property \Illuminate\Support\Carbon|null $reviewed_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Company $company
 * @property-read string $download_url
 * @property-read string $file_size_human
 * @property-read string $status_label
 * @property-read string $type_label
 * @property-read \App\Models\User|null $reviewedByUser
 * @property-read \App\Models\User $uploadedByUser
 * @method static Builder<static>|KycDocument accepted()
 * @method static Builder<static>|KycDocument newModelQuery()
 * @method static Builder<static>|KycDocument newQuery()
 * @method static Builder<static>|KycDocument pending()
 * @method static Builder<static>|KycDocument query()
 * @method static Builder<static>|KycDocument whereAdminNotes($value)
 * @method static Builder<static>|KycDocument whereCompanyId($value)
 * @method static Builder<static>|KycDocument whereCreatedAt($value)
 * @method static Builder<static>|KycDocument whereFilePath($value)
 * @method static Builder<static>|KycDocument whereFileSize($value)
 * @method static Builder<static>|KycDocument whereId($value)
 * @method static Builder<static>|KycDocument whereMimeType($value)
 * @method static Builder<static>|KycDocument whereOriginalName($value)
 * @method static Builder<static>|KycDocument whereReviewedAt($value)
 * @method static Builder<static>|KycDocument whereReviewedByUserId($value)
 * @method static Builder<static>|KycDocument whereStatus($value)
 * @method static Builder<static>|KycDocument whereType($value)
 * @method static Builder<static>|KycDocument whereUpdatedAt($value)
 * @method static Builder<static>|KycDocument whereUploadedByUserId($value)
 * @method static Builder<static>|KycDocument whereUuid($value)
 * @mixin \Eloquent
 */
class KycDocument extends Model
{
    public const TYPES = [
        'visura_camerale'   => 'Visura camerale',
        'documento_identita'=> 'Documento identità (legale rappresentante)',
        'statuto'           => 'Statuto societario',
        'altro'             => 'Altro documento',
    ];

    public const STATUSES = [
        'pending'  => 'In attesa di revisione',
        'accepted' => 'Accettato',
        'rejected' => 'Rifiutato',
    ];

    protected $fillable = [
        'uuid',
        'company_id',
        'uploaded_by_user_id',
        'type',
        'file_path',
        'original_name',
        'mime_type',
        'file_size',
        'status',
        'admin_notes',
        'reviewed_by_user_id',
        'reviewed_at',
    ];

    protected $casts = [
        'reviewed_at' => 'datetime',
        'file_size'   => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (KycDocument $doc): void {
            $doc->uuid ??= (string) Str::uuid();
        });
    }

    // ── Relazioni ─────────────────────────────────────────────────────────────

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function uploadedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    public function reviewedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    public function scopeAccepted(Builder $query): Builder
    {
        return $query->where('status', 'accepted');
    }

    // ── Accessors ─────────────────────────────────────────────────────────────

    public function getTypeLabelAttribute(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function getFileSizeHumanAttribute(): string
    {
        $bytes = $this->file_size ?? 0;
        if ($bytes < 1024) return $bytes . ' B';
        if ($bytes < 1048576) return round($bytes / 1024, 1) . ' KB';
        return round($bytes / 1048576, 1) . ' MB';
    }

    public function getDownloadUrlAttribute(): string
    {
        return route('portal.kyc.download', $this);
    }

    /**
     * Elimina il file fisico dal disco privato.
     */
    public function deleteFile(): void
    {
        Storage::disk('private')->delete($this->file_path);
    }
}
