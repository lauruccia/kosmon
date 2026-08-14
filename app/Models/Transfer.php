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
 * @property string $reference
 * @property int|null $initiated_by
 * @property int $from_account_id
 * @property int $to_account_id
 * @property int $amount
 * @property string $currency_code
 * @property string $status
 * @property string $kind
 * @property string $idempotency_key
 * @property string|null $description
 * @property \Illuminate\Support\Carbon|null $booked_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property int|null $reversed_transfer_id
 * @property \Illuminate\Support\Carbon|null $refunded_at
 * @property string|null $admin_action
 * @property int|null $related_transfer_id
 * @property int|null $confirmed_by
 * @property-read \App\Models\User|null $confirmer
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Transfer> $feeTransfers
 * @property-read int|null $fee_transfers_count
 * @property-read \App\Models\Account $fromAccount
 * @property-read \App\Models\User|null $initiator
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\LedgerEntry> $ledgerEntries
 * @property-read int|null $ledger_entries_count
 * @property-read Transfer|null $relatedTransfer
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Transfer> $reversalChildren
 * @property-read int|null $reversal_children_count
 * @property-read Transfer|null $reversedTransfer
 * @property-read \App\Models\Account $toAccount
 * @method static \Database\Factories\TransferFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Transfer newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Transfer newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Transfer query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Transfer whereAdminAction($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Transfer whereAmount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Transfer whereBookedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Transfer whereConfirmedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Transfer whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Transfer whereCurrencyCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Transfer whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Transfer whereFromAccountId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Transfer whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Transfer whereIdempotencyKey($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Transfer whereInitiatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Transfer whereKind($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Transfer whereReference($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Transfer whereRefundedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Transfer whereRelatedTransferId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Transfer whereReversedTransferId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Transfer whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Transfer whereToAccountId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Transfer whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Transfer whereUuid($value)
 * @mixin \Eloquent
 */
class Transfer extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'reference',
        'initiated_by',
        'confirmed_by',
        'from_account_id',
        'to_account_id',
        'amount',
        'currency_code',
        'status',
        'kind',
        'idempotency_key',
        'reversed_transfer_id',
        'related_transfer_id',
        'listing_id',
        'quantity',
        'refunded_at',
        'admin_action',
        'description',
        'booked_at',
        // Snapshot indirizzo di spedizione (solo kind=portal_marketplace_order
        // con prodotto di tipo "spedizione") — vedi Listing::requiresShippingAddress()
        // e Account::hasShippingAddress(). Pass-through come listing_id/quantity.
        'shipping_recipient_name',
        'shipping_address',
        'shipping_city',
        'shipping_postal_code',
        'shipping_province',
        'shipping_phone',
        'shipping_ky_amount',
    ];

    protected $casts = [
        'booked_at' => 'datetime',
        'refunded_at' => 'datetime',
        'quantity' => 'integer',
        'shipping_ky_amount' => 'integer',
    ];

    /**
     * Marker (admin_action) dei transfer tecnici di "apertura ledger" generati dal
     * backfill di integrità del 2026-06-17 (reference TRX-OPEN-*, kind ky_emission).
     * Non sono movimenti reali: allineano il ledger al saldo importato usando il
     * conto sistema (KNM) come contropartita. Vanno nascosti ai clienti.
     * Vedi _dev-tools/fix_ledger_apertura_prod.sql.
     */
    public const LEDGER_OPENING_ACTION = 'fix_ledger_apertura_20260617';

    /**
     * Valore sentinella usato SOLO nel filtro "Tipo movimento" del backoffice admin
     * (select `kind`) per selezionare la vista dedicata alle correzioni tecniche di
     * apertura ledger. Non corrisponde a un valore reale della colonna `kind`.
     */
    public const LEDGER_OPENING_FILTER = '__ledger_opening__';

    /**
     * Marker (admin_action) della coppia accredito + storno di un bonus MLM annullato
     * (2026-08-14, richiesta di Laura). Quando un bonus generato per errore viene
     * annullato, MlmWalletService::reverseBonusPayout() riporta il KY alla Cassa
     * Circuito e marca con questo valore SIA il movimento di storno SIA l'accredito
     * originale: le due righe si annullano a vicenda, quindi mostrarle significa solo
     * far ricomparire nelle liste un bonus che non esiste piu'.
     *
     * Le righe restano nel database e continuano a comporre i saldi dei conti: il
     * circuito resta chiuso, cambia solo la loro visibilita' nelle liste.
     */
    public const MLM_BONUS_REVERSAL_ACTION = 'mlm_bonus_reversal';

    /**
     * Tutti i marker admin_action che identificano una scrittura TECNICA, cioe' non un
     * movimento reale del circuito: sono esclusi da ogni lista/KPI, sia lato cliente
     * sia nel backoffice (vedi scopeExcludeTechnicalCorrections()).
     *
     * @var list<string>
     */
    public const TECHNICAL_ACTIONS = [
        self::LEDGER_OPENING_ACTION,
        self::MLM_BONUS_REVERSAL_ACTION,
    ];

    /**
     * Esclude le scritture tecniche (apertura ledger 17/06/2026 e storni di bonus MLM
     * annullati) dalle viste rivolte al cliente E, dal 2026-07-02, anche dalle
     * liste/KPI generali del backoffice admin. Nel backoffice restano consultabili con
     * la spunta "Mostra movimenti tecnici" di /admin/movimenti (e, per le sole
     * correzioni di apertura, con il filtro dedicato Transfer::LEDGER_OPENING_FILTER).
     *
     * ATTENZIONE: e' un filtro di VISUALIZZAZIONE. I saldi dei conti non si calcolano
     * da qui (colonna accounts.balance aggiornata da TransferBookingService), quindi
     * nascondere una riga non sbilancia mai il circuito.
     */
    public function scopeExcludeTechnicalCorrections(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where(function (\Illuminate\Database\Eloquent\Builder $q): void {
            $q->whereNull('admin_action')
              ->orWhereNotIn('admin_action', self::TECHNICAL_ACTIONS);
        });
    }

    /**
     * @deprecated 2026-08-14 Alias storico di scopeExcludeTechnicalCorrections(),
     *   mantenuto perche' usato in decine di query: da quella data esclude tutte le
     *   scritture tecniche, non solo l'apertura ledger.
     */
    public function scopeExcludeLedgerCorrections(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $this->scopeExcludeTechnicalCorrections($query);
    }

    /** True se questo movimento e' una scrittura tecnica nascosta dalle liste. */
    public function isTechnicalCorrection(): bool
    {
        return in_array($this->admin_action, self::TECHNICAL_ACTIONS, true);
    }

    protected static function booted(): void
    {
        static::creating(function (Transfer $transfer): void {
            $transfer->uuid ??= (string) Str::uuid();
            $transfer->reference ??= 'TRX-' . Str::upper(Str::random(12));
            $transfer->idempotency_key ??= (string) Str::uuid();
        });
    }

    public function initiator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }

    /** Utente che ha confermato una richiesta di pagamento pending. */
    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function fromAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'from_account_id');
    }

    public function toAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'to_account_id');
    }

    public function reversedTransfer(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversed_transfer_id');
    }

    public function reversalChildren(): HasMany
    {
        return $this->hasMany(self::class, 'reversed_transfer_id');
    }

    /**
     * Trasferimento padre che ha generato questa commissione (kind = portal_fee).
     */
    public function relatedTransfer(): BelongsTo
    {
        return $this->belongsTo(self::class, 'related_transfer_id');
    }

    /**
     * Commissioni generate da questo trasferimento.
     */
    public function feeTransfers(): HasMany
    {
        return $this->hasMany(self::class, 'related_transfer_id');
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    /**
     * Prodotto shop acquistato (solo per kind = portal_marketplace_order).
     */
    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    /**
     * Pagamento EUR (quota non-KY) collegato a questo ordine shop, se il
     * prodotto aveva un mix KY/EUR < 100% KY. Solo per
     * kind = portal_marketplace_order — vedi MarketplaceOrderPayment.
     */
    public function marketplaceOrderPayment(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(MarketplaceOrderPayment::class);
    }

    /**
     * "A cosa è dovuto" questo movimento, per i kind che derivano da un altro
     * evento del sistema (commissione di transazione, cashback, accredito o
     * prelievo del cassetto kmoney MLM) — richiesta di Laura del 2026-08-10:
     * dal registro movimenti (admin) e dal dettaglio movimento (portale) si
     * deve poter risalire esattamente all'origine di una commissione/cashback.
     *
     * Volutamente NON chiamato nelle liste (transfers.blade.php,
     * movements.blade.php): fa query mirate sul singolo movimento, va
     * invocato solo nelle pagine di dettaglio di UN transfer per evitare N+1
     * su elenchi con molte righe.
     *
     * @return array{title:string, lines:array<int,array{label:string,value:string}>, admin_route:string|null, admin_route_params:array<string,int>}|null
     */
    public function originSummary(): ?array
    {
        return match ($this->kind) {
            'portal_fee' => $this->originSummaryForFee(),
            'portal_cashback' => $this->originSummaryForCashback(),
            'mlm_wallet_credit' => $this->originSummaryForWalletCredit(),
            'mlm_wallet_withdrawal' => $this->originSummaryForWalletWithdrawal(),
            default => null,
        };
    }

    private function originSummaryForFee(): ?array
    {
        $parent = $this->relatedTransfer;
        if (! $parent) {
            return null;
        }

        return [
            'title' => 'Commissione di transazione',
            'lines' => [
                ['label' => 'Movimento originario', 'value' => $parent->reference],
                ['label' => 'Causale movimento originario', 'value' => $parent->description ?? '—'],
                ['label' => 'Importo movimento originario', 'value' => ky_format($parent->amount) . ' KY'],
            ],
            'admin_route' => null,
            'admin_route_params' => [],
        ];
    }

    /**
     * Il movimento cashback non ha un related_transfer_id: il collegamento al
     * pagamento che lo ha generato è nell'idempotency_key, sempre
     * 'cashback_{uuid del transfer originario}' — vedi CashbackService::applyIfEligible().
     */
    private function originSummaryForCashback(): ?array
    {
        if (! Str::startsWith((string) $this->idempotency_key, 'cashback_')) {
            return null;
        }

        $origin = self::where('uuid', Str::after($this->idempotency_key, 'cashback_'))->first();
        if (! $origin) {
            return null;
        }

        return [
            'title' => 'Cashback',
            'lines' => [
                ['label' => 'Movimento che ha generato il cashback', 'value' => $origin->reference],
                ['label' => 'Causale movimento originario', 'value' => $origin->description ?? '—'],
                ['label' => 'Importo movimento originario', 'value' => ky_format($origin->amount) . ' KY'],
                ['label' => 'Data movimento originario', 'value' => optional($origin->booked_at ?? $origin->created_at)->format('d/m/Y H:i') ?? '—'],
            ],
            'admin_route' => null,
            'admin_route_params' => [],
        ];
    }

    /**
     * Accredito nel cassetto kmoney (compenso diretto/indiretto/esteso o
     * bonus) — risale alla riga mlm_wallet_ledger_entries collegata a questo
     * transfer e da lì alla commissione o al bonus di origine.
     */
    private function originSummaryForWalletCredit(): ?array
    {
        $entry = MlmWalletLedgerEntry::where('transfer_id', $this->id)->first();
        if (! $entry) {
            return null;
        }

        if ($entry->source_type === 'commission') {
            $commission = MlmCommission::with(['sourceClient', 'sourceAgent'])->find($entry->source_id);
            if (! $commission) {
                return null;
            }

            return [
                'title' => 'Compenso ' . $commission->type,
                'lines' => array_values(array_filter([
                    ['label' => 'Tipo compenso', 'value' => ucfirst((string) $commission->type)],
                    ['label' => 'Cliente sorgente (acquisto che ha generato il compenso)', 'value' => $commission->sourceClient->name ?? '—'],
                    $commission->sourceAgent ? ['label' => 'Agente sorgente (livello indiretto)', 'value' => $commission->sourceAgent->name] : null,
                    ['label' => 'Livello', 'value' => $commission->level !== null ? (string) $commission->level : '—'],
                    ['label' => 'Base di calcolo (Prov K)', 'value' => number_format($commission->base_amount_eur_cents / 100, 2, ',', '.') . ' €'],
                    ['label' => 'Percentuale applicata', 'value' => number_format((float) $commission->percentage, 2, ',', '.') . '%'],
                ])),
                'admin_route' => $commission->mlm_payout_id ? 'admin.mlm.payouts.show' : null,
                'admin_route_params' => $commission->mlm_payout_id ? ['mlmPayout' => $commission->mlm_payout_id] : [],
            ];
        }

        if ($entry->source_type === 'bonus_payout') {
            $bonus = MlmBonusPayout::find($entry->source_id);
            if (! $bonus) {
                return null;
            }

            $bonusLabel = $bonus->kind === 'diretto'
                ? 'Bonus diretto'
                : ($bonus->kind === 'extra' ? 'Extra Bonus ' . ucfirst((string) $bonus->rank_at_time) : ucfirst((string) $bonus->rank_at_time));

            return [
                'title' => 'Bonus ' . $bonus->kind,
                'lines' => array_values(array_filter([
                    ['label' => 'Tipo bonus', 'value' => $bonusLabel],
                    ['label' => 'Settimana di riferimento', 'value' => $bonus->week_ending->format('d/m/Y')],
                    $bonus->rank_at_time ? ['label' => 'Qualifica al momento', 'value' => ucfirst((string) $bonus->rank_at_time)] : null,
                ])),
                'admin_route' => $bonus->mlm_payout_id ? 'admin.mlm.payouts.show' : null,
                'admin_route_params' => $bonus->mlm_payout_id ? ['mlmPayout' => $bonus->mlm_payout_id] : [],
            ];
        }

        return null;
    }

    /**
     * Riserva/rilascio del cassetto kmoney per una liquidazione EUR — l'id
     * della liquidazione è sempre nella descrizione testuale
     * ("...liquidazione #{id}...", vedi MlmPayoutService::reserveWalletForPayout()
     * e releaseWalletReservationForPayout()), non c'è altro collegamento
     * strutturato in questo caso.
     */
    private function originSummaryForWalletWithdrawal(): ?array
    {
        if (! preg_match('/liquidazione #(\d+)/u', (string) $this->description, $matches)) {
            return null;
        }

        $payout = MlmPayout::with('agent')->find((int) $matches[1]);
        if (! $payout) {
            return null;
        }

        return [
            'title' => 'Movimento cassetto per liquidazione EUR',
            'lines' => [
                ['label' => 'Liquidazione', 'value' => '#' . $payout->id],
                ['label' => 'Agente', 'value' => $payout->agent->name ?? '—'],
                ['label' => 'Periodo', 'value' => $payout->period_from->format('m/Y')],
                ['label' => 'Stato liquidazione', 'value' => ucfirst((string) $payout->status)],
                ['label' => 'Totale liquidazione', 'value' => number_format($payout->total_eur_cents / 100, 2, ',', '.') . ' €'],
            ],
            'admin_route' => 'admin.mlm.payouts.show',
            'admin_route_params' => ['mlmPayout' => $payout->id],
        ];
    }
}
