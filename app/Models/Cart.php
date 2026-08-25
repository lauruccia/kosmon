<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Il carrello di un conto.
 *
 * Vive sul conto e non nella sessione: si riempie dal telefono e si svuota dal
 * computer. Un conto ha al massimo un carrello `active` per volta.
 *
 * Qui dentro non ci sono prezzi. Il carrello dice soltanto *cosa* e *quanto*;
 * *a che prezzo* lo si chiede al catalogo ogni volta che serve, così un'offerta
 * che parte o che scade si vede subito anche in un carrello vecchio di due
 * settimane. I prezzi si congelano un attimo dopo, sulle righe dell'ordine.
 *
 * @property int $id
 * @property string $uuid
 * @property int $account_id
 * @property string $status
 * @property \Illuminate\Support\Carbon|null $expires_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, CartItem> $items
 */
class Cart extends Model
{
    use HasFactory;

    public const STATUS_ACTIVE  = 'active';
    public const STATUS_ORDERED = 'ordered';
    public const STATUS_EXPIRED = 'expired';

    /** Un carrello abbandonato scade dopo 30 giorni (decisione di Laura, 23/08). */
    public const GIORNI_DI_VITA = 30;

    protected $fillable = [
        'uuid',
        'account_id',
        'status',
        'expires_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (Cart $cart): void {
            if (! $cart->uuid) {
                $cart->uuid = (string) Str::uuid();
            }
            if (! $cart->expires_at) {
                $cart->expires_at = now()->addDays(self::GIORNI_DI_VITA);
            }
        });
    }

    // ── Relazioni ────────────────────────────────────────────────────────────

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }

    // ── Ricerca ──────────────────────────────────────────────────────────────

    /**
     * Il carrello attivo di questo conto, creandolo se non c'è.
     *
     * Se quello attivo è scaduto viene chiuso e se ne apre uno nuovo: un
     * carrello di due mesi fa, con prezzi e disponibilità cambiati, non è un
     * carrello, è un ricordo.
     */
    public static function attivoPer(Account $account): self
    {
        $cart = self::query()
            ->where('account_id', $account->id)
            ->where('status', self::STATUS_ACTIVE)
            ->latest('id')
            ->first();

        if ($cart && $cart->expires_at && $cart->expires_at->isPast()) {
            $cart->update(['status' => self::STATUS_EXPIRED]);
            $cart = null;
        }

        return $cart ?? self::create(['account_id' => $account->id]);
    }

    /**
     * Quanti pezzi ha nel carrello questo conto, SENZA crearne uno.
     *
     * Serve per il numerino accanto all'icona in cima allo shop: quella pagina
     * la aprono tutti, e usare attivoPer() lascerebbe in giro un carrello vuoto
     * per ogni visitatore che non ne ha mai aperto uno.
     */
    public static function pezziDi(Account $account): int
    {
        return (int) CartItem::query()
            ->whereIn('cart_id', self::query()
                ->select('id')
                ->where('account_id', $account->id)
                ->where('status', self::STATUS_ACTIVE))
            ->sum('quantity');
    }

    // ── Lettura ──────────────────────────────────────────────────────────────

    /** Quanti pezzi ci sono dentro in tutto (il numerino accanto all'icona). */
    public function totalePezzi(): int
    {
        return (int) $this->items->sum('quantity');
    }

    public function isVuoto(): bool
    {
        return $this->items->isEmpty();
    }

    /**
     * Le righe raggruppate per azienda venditrice, con i totali di ogni gruppo.
     *
     * È la forma in cui il carrello viene mostrato E la forma in cui va alla
     * cassa: ogni gruppo diventerà un ordine per conto suo, con il suo
     * movimento KY e la sua eventuale quota in euro.
     *
     * @return Collection<int, array{company: Company, righe: Collection<int, CartItem>, ky: int, eur: int, spedizione_ky: int, spedizione_eur: int}>
     */
    public function perVenditore(): Collection
    {
        return $this->items
            ->filter(fn (CartItem $item) => $item->listing !== null)
            ->groupBy(fn (CartItem $item) => $item->listing->company_id)
            ->map(function (Collection $righe) {
                $ky  = (int) $righe->sum(fn (CartItem $r) => $r->totaleKy());
                $eur = (int) $righe->sum(fn (CartItem $r) => $r->totaleEuro());

                // La spedizione si paga UNA volta per venditore (un ordine, un
                // pacco): si prende la più cara fra i prodotti da spedire.
                $daSpedire = $righe->filter(fn (CartItem $r) => $r->listing->requiresShippingAddress());
                $conSpedizione = $daSpedire->sortByDesc(fn (CartItem $r) => $r->listing->shipping_cost)->first();

                $spedizioneKy  = $conSpedizione ? (int) $conSpedizione->listing->shipping_ky_amount : 0;
                $spedizioneEur = $conSpedizione ? (int) $conSpedizione->listing->shipping_euro_amount : 0;

                return [
                    'company'        => $righe->first()->listing->company,
                    'righe'          => $righe->values(),
                    'ky'             => $ky + $spedizioneKy,
                    'eur'            => $eur + $spedizioneEur,
                    'spedizione_ky'  => $spedizioneKy,
                    'spedizione_eur' => $spedizioneEur,
                    'richiede_indirizzo' => $daSpedire->isNotEmpty(),
                ];
            })
            ->values();
    }

    /**
     * Totale in KY di tutto il carrello, spedizioni comprese.
     *
     * È questo il numero da controllare contro il saldo PRIMA di cominciare a
     * pagare i venditori: se si controllasse gruppo per gruppo si scoprirebbe
     * che i soldi non bastano dopo aver già pagato il primo.
     */
    public function totaleKy(): int
    {
        return (int) $this->perVenditore()->sum('ky');
    }

    public function totaleEuro(): int
    {
        return (int) $this->perVenditore()->sum('eur');
    }
}
