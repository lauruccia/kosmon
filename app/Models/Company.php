<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property string $slug
 * @property string|null $email
 * @property string|null $vat_number
 * @property string|null $fiscal_code
 * @property string $status
 * @property string $kyc_status
 * @property string $currency_code
 * @property array<array-key, mixed>|null $settings
 * @property \Illuminate\Support\Carbon|null $approved_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property string|null $sector
 * @property string|null $kyc_notes
 * @property int|null $kyc_reviewed_by
 * @property \Illuminate\Support\Carbon|null $kyc_reviewed_at
 * @property string|null $description
 * @property string|null $website
 * @property string|null $phone
 * @property int|null $broker_user_id
 * @property \Illuminate\Support\Carbon|null $suspended_at
 * @property string|null $suspension_reason
 * @property string|null $subscription_plan
 * @property string|null $tagline
 * @property string|null $city
 * @property string|null $linkedin_url
 * @property string|null $instagram_url
 * @property string|null $facebook_url
 * @property string|null $logo_path
 * @property string|null $banner_path
 * @property string|null $payments_paused_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Account> $accounts
 * @property-read int|null $accounts_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Announcement> $announcements
 * @property-read int|null $announcements_count
 * @property-read \App\Models\User|null $broker
 * @property-read string|null $banner_url
 * @property-read bool $kyc_is_approved
 * @property-read bool $kyc_is_pending
 * @property-read string $kyc_status_label
 * @property-read string|null $logo_url
 * @property-read int $plan_order
 * @property-read string $subscription_plan_label
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\KycDocument> $kycDocuments
 * @property-read int|null $kyc_documents_count
 * @property-read \App\Models\User|null $kycReviewedBy
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Listing> $listings
 * @property-read int|null $listings_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\User> $users
 * @property-read int|null $users_count
 * @method static \Database\Factories\CompanyFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Company newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Company newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Company query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Company whereApprovedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Company whereBannerPath($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Company whereBrokerUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Company whereCity($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Company whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Company whereCurrencyCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Company whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Company whereEmail($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Company whereFacebookUrl($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Company whereFiscalCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Company whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Company whereInstagramUrl($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Company whereKycNotes($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Company whereKycReviewedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Company whereKycReviewedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Company whereKycStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Company whereLinkedinUrl($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Company whereLogoPath($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Company whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Company wherePaymentsPausedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Company wherePhone($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Company whereSector($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Company whereSettings($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Company whereSlug($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Company whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Company whereSubscriptionPlan($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Company whereSuspendedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Company whereSuspensionReason($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Company whereTagline($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Company whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Company whereUuid($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Company whereVatNumber($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Company whereWebsite($value)
 * @mixin \Eloquent
 */
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
