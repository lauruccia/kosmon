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
 * @property int|null $plan_id
 * @property int|null $accepted_ky_percentage
 * @property string|null $tagline
 * @property string|null $city
 * @property string|null $address
 * @property numeric|null $latitude
 * @property numeric|null $longitude
 * @property \Illuminate\Support\Carbon|null $geocoded_at
 * @property string|null $linkedin_url
 * @property string|null $instagram_url
 * @property string|null $facebook_url
 * @property string|null $logo_path
 * @property string|null $banner_path
 * @property string|null $payments_paused_at
 * @property-read \App\Models\Plan|null $plan
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
 * @property-read string $plan_card_style
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

    /** Percentuali Kmoney dichiarabili dall'azienda nel profilo (mix KY/EUR) */
    public const ACCEPTED_KY_PERCENTAGES = [0, 25, 50, 75, 100];

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
        'plan_id',
        'accepted_ky_percentage',
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
        'address',
        'latitude',
        'longitude',
        'geocoded_at',
        'linkedin_url',
        'instagram_url',
        'facebook_url',
        'logo_path',
        'banner_path',
    ];

    protected $casts = [
        'settings'       => 'array',
        'accepted_ky_percentage' => 'integer',
        'approved_at'    => 'datetime',
        'kyc_reviewed_at'=> 'datetime',
        'suspended_at'   => 'datetime',
        'latitude'       => 'decimal:7',
        'longitude'      => 'decimal:7',
        'geocoded_at'    => 'datetime',
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

    /**
     * Vero se l'azienda e' visibile/presente nella directory pubblica del
     * circuito (portal/companies + scheda azienda). Incapsula la condizione
     * usata finora duplicata in PortalController::companies()/showCompany();
     * usata anche per il gate del pulsante "Pubblica prodotto" nello shop
     * (2026-07-29: tutte le aziende in directory possono inserire prodotti,
     * a prescindere dal piano).
     *
     * NB: non controlla suspended_at (bug noto, segnalato separatamente) —
     * stessa definizione usata finora, per non cambiare comportamento qui.
     */
    public function isInDirectory(): bool
    {
        return $this->status === 'active' && $this->kyc_status === 'approved';
    }

    /**
     * Ha coordinate geografiche valide (indirizzo geocodificato con successo)
     * e puo' quindi comparire come pin sulla mappa della directory.
     */
    public function hasCoordinates(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function planPayments(): HasMany
    {
        return $this->hasMany(PlanPayment::class);
    }

    public function getSubscriptionPlanLabelAttribute(): string
    {
        return $this->plan?->name ?? '—';
    }

    /** Peso per ordinamento directory: piu' basso = mostrato prima. */
    public function getPlanOrderAttribute(): int
    {
        return $this->plan?->display_order ?? 99;
    }

    /** Stile card da usare nella directory (rich | compact | simple). */
    public function getPlanCardStyleAttribute(): string
    {
        return $this->plan?->card_style ?? 'simple';
    }

    /**
     * Il piano deve avere la caratteristica "vendita prodotti" abilitata
     * (di default solo il piano Ecommerce, ma l'admin puo' abilitarla su
     * qualunque altro piano da /admin/piani).
     */
    public function hasEcommercePlan(): bool
    {
        return (bool) ($this->plan?->can_sell_products ?? false);
    }

    /**
     * Piani attivi a cui l'azienda puo' fare self-service upgrade pagando la
     * differenza (solo piani con canone piu' alto di quello attuale: i
     * downgrade restano gestiti dall'admin per evitare rimborsi automatici).
     */
    public function availableUpgrades(): \Illuminate\Support\Collection
    {
        $currentPrice = $this->plan?->price_cents ?? 0;

        return Plan::query()
            ->where('is_active', true)
            ->where('price_cents', '>', $currentPrice)
            ->orderBy('display_order')
            ->get();
    }

    /** Differenza da pagare (centesimi) per passare al piano indicato. */
    public function upgradePriceDifference(Plan $targetPlan): int
    {
        return max(0, $targetPlan->price_cents - ($this->plan?->price_cents ?? 0));
    }

    // ── Accettazione Kmoney (badge directory) ────────────────────────────────

    /**
     * Conto business principale dell'azienda (non di sistema, non sottoconto).
     */
    public function primaryBusinessAccount(): ?Account
    {
        return $this->accounts()
            ->where('is_system_account', false)
            ->where('owner_type', 'company')
            ->whereNull('parent_account_id')
            ->first();
    }

    /**
     * Migliore percentuale KY (25-100) tra i prodotti attivi dello shop.
     * I prodotti allo 0% non contano: non dicono nulla sull'accettazione Kmoney.
     */
    public function bestListingKyPercentage(): ?int
    {
        $max = $this->listings()
            ->where('status', 'active')
            ->where(function ($q): void {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->where('ky_percentage', '>=', 25)
            ->max('ky_percentage');

        return $max !== null ? (int) $max : null;
    }

    /**
     * Percentuale Kmoney mostrata sulla card della directory (versione lazy:
     * carica da DB conto e prodotti; per la directory usare
     * computeEffectiveKyPercentage() con i dati gia' caricati).
     */
    public function effectiveAcceptedKyPercentage(): ?int
    {
        return $this->computeEffectiveKyPercentage(
            $this->primaryBusinessAccount(),
            $this->bestListingKyPercentage()
        );
    }

    /**
     * Percentuale Kmoney effettiva mostrata sulla card della directory.
     *
     * - Conto sottozero    => 100 (obbligo circuito, non modificabile)
     * - Altrimenti         => la migliore tra la % dichiarata nel profilo e la
     *                         migliore % (25-100) dei prodotti attivi caricati
     * - Nessuna delle due  => null (nessun badge)
     */
    public function computeEffectiveKyPercentage(?Account $account, ?int $bestListingPct): ?int
    {
        if ($account !== null && $account->isInDebit()) {
            return 100;
        }

        $candidates = array_filter(
            [$this->accepted_ky_percentage, $bestListingPct],
            static fn ($v) => $v !== null
        );

        return $candidates === [] ? null : (int) max($candidates);
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

    /**
     * Metodi di pagamento EUR configurati da questa azienda (stripe/paypal/
     * bank_transfer) per incassare la quota EUR dei prodotti shop con mix
     * KY/EUR — vedi PaymentGateway.
     */
    public function paymentGateways(): HasMany
    {
        return $this->hasMany(PaymentGateway::class);
    }

    public function activePaymentGateways(): HasMany
    {
        return $this->paymentGateways()->active();
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
