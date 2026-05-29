<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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
