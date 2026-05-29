<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Company extends Model
{
    use HasFactory;

    public const KYC_STATUSES = [
        'pending'      => 'In attesa di documenti',
        'under_review' => 'Documenti in revisione',
        'approved'     => 'Verificata',
        'rejected'     => 'Rifiutata',
    ];

    /** Piani in ordine decrescente di costo (indice = priorità di visualizzazione) */
    public const SUBSCRIPTION_PLANS = [
        'ecommerce' => 'Ecommerce',
        'vetrina'   => 'Vetrina',
        'biglietto' => 'Biglietto',
        'anagrafica'=> 'Anagrafica',
    ];

    /** Peso per ordinamento: più basso = mostrato prima */
    public const PLAN_ORDER = [
        'ecommerce'  => 0,
        'vetrina'    => 1,
        'biglietto'  => 2,
        'anagrafica' => 3,
    ];

    protected $fillable = [
        'uuid',
        'broker_user_id',
        'name',
        'sector',
        'slug',
        'description',
        'website',
        'phone',
        'email',
        'vat_number',
        'fiscal_code',
        'status',
        'subscription_plan',
        'kyc_status',
        'kyc_notes',
        'kyc_reviewed_by',
        'kyc_reviewed_at',
        'currency_code',
        'settings',
        'approved_at',
        'suspended_at',
        'payments_paused_at',
        'suspension_reason',
        'tagline',
        'city',
        'linkedin_url',
        'instagram_url',
        'facebook_url',
        'logo_path',
        'banner_path',
    ];

    protected $casts = [
        'settings'       => 'array',
        'approved_at'    => 'datetime',
        'kyc_reviewed_at'=> 'datetime',
        'suspended_at'   => 'datetime',
    ];

    /**
     * URL pubblico del logo (null se non impostato).
     */
    public function getLogoUrlAttribute(): ?string
    {
        return $this->logo_path
            ? \Illuminate\Support\Facades\Storage::disk('public')->url($this->logo_path)
            : null;
    }

    /**
     * URL pubblico del banner (null se non impostato).
     */
    public function getBannerUrlAttribute(): ?string
    {
        return $this->banner_path
            ? \Illuminate\Support\Facades\Storage::disk('public')->url($this->banner_path)
            : null;
    }

    public function isSuspended(): bool
    {
        return $this->suspended_at !== null;
    }

    public function isPaymentsPaused(): bool
    {
        return $this->payments_paused_at !== null;
    }

    public function isActive(): bool
    {
        return $this->status === 'active' && ! $this->isSuspended();
    }

    public function getSubscriptionPlanLabelAttribute(): string
    {
        return self::SUBSCRIPTION_PLANS[$this->subscription_plan] ?? '—';
    }

    public function getPlanOrderAttribute(): int
    {
        return self::PLAN_ORDER[$this->subscription_plan] ?? 99;
    }

    protected static function booted(): void
    {
        static::creating(function (Company $company): void {
            $company->uuid ??= (string) Str::uuid();
            $company->slug ??= Str::slug($company->name . '-' . Str::lower(Str::random(6)));
        });
    }

    public function broker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'broker_user_id');
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }

    public function listings(): HasMany
    {
        return $this->hasMany(Listing::class);
    }

    public function announcements(): HasMany
    {
        return $this->hasMany(Announcement::class);
    }

    public function kycDocuments(): HasMany
    {
        return $this->hasMany(KycDocument::class)->latest();
    }

    public function kycReviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'kyc_reviewed_by');
    }

    // ── Accessors ─────────────────────────────────────────────────────────────

    public function getKycStatusLabelAttribute(): string
    {
        return self::KYC_STATUSES[$this->kyc_status] ?? $this->kyc_status;
    }

    public function getKycIsApprovedAttribute(): bool
    {
        return $this->kyc_status === 'approved';
    }

    public function getKycIsPendingAttribute(): bool
    {
        return in_array($this->kyc_status, ['pending', 'under_review'], true);
    }
}
