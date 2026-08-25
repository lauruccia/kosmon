<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Il permesso che l'utente dà a un'applicazione del circuito (oggi Kosmoshop)
 * di addebitargli KY senza rimandarlo ogni volta su KMoney a confermare.
 *
 * Cosa NON è: un abbonamento, un addebito ricorrente, un accesso al saldo.
 * Il mandato dice una cosa sola — "da questo negozio non può uscire più di N KY
 * in un colpo solo" — e ha tre modi di spegnersi: la scadenza (12 mesi),
 * la sospensione automatica dell'antifurto, la revoca dell'utente.
 */
class PaymentMandate extends Model
{
    protected $fillable = [
        'uuid',
        'user_id',
        'account_id',
        'client_id',
        'max_per_transaction',
        'authorized_sellers',
        'expires_at',
        'suspended_at',
        'revoked_at',
        'reactivated_at',
        'charges_count',
        'last_used_at',
        'created_ip',
    ];

    protected $casts = [
        'authorized_sellers'  => 'array',
        'max_per_transaction' => 'integer',
        'charges_count'       => 'integer',
        'expires_at'          => 'datetime',
        'suspended_at'        => 'datetime',
        'revoked_at'          => 'datetime',
        'reactivated_at'      => 'datetime',
        'last_used_at'        => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (PaymentMandate $mandate): void {
            $mandate->uuid ??= (string) Str::uuid();
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function charges(): HasMany
    {
        return $this->hasMany(PaymentMandateCharge::class);
    }

    // =========================================================================
    // Stato
    // =========================================================================

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isSuspended(): bool
    {
        return $this->suspended_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /**
     * Vivo = può autorizzare un addebito immediato adesso.
     */
    public function isActive(): bool
    {
        return ! $this->isRevoked() && ! $this->isSuspended() && ! $this->isExpired();
    }

    /**
     * Etichetta leggibile dello stato, per la pagina "App collegate".
     */
    public function statusLabel(): string
    {
        return match (true) {
            $this->isRevoked()   => 'Revocata',
            $this->isSuspended() => 'Sospesa per attività insolita',
            $this->isExpired()   => 'Scaduta',
            default              => 'Attiva',
        };
    }

    // =========================================================================
    // Venditori autorizzati
    // =========================================================================

    public function allowsSeller(string $accountNumber): bool
    {
        return in_array($accountNumber, (array) $this->authorized_sellers, true);
    }

    /**
     * Aggiunge un venditore alla lista. Non viene mai chiamato da solo dal
     * flusso di addebito: ci si arriva unicamente da una conferma esplicita
     * dell'utente, perché è la protezione che sostituisce il plafond.
     */
    public function authorizeSeller(string $accountNumber): void
    {
        $sellers = (array) $this->authorized_sellers;

        if (! in_array($accountNumber, $sellers, true)) {
            $sellers[] = $accountNumber;
            $this->forceFill(['authorized_sellers' => array_values($sellers)])->save();
        }
    }

    // =========================================================================
    // Antifurto
    // =========================================================================

    /**
     * Quanti addebiti AUTOMATICI nella finestra dell'antifurto.
     *
     * Due esclusioni, e nessuna delle due è un dettaglio:
     *
     * - **gli acquisti confermati a mano non contano.** L'antifurto esiste
     *   perché dieci addebiti in un'ora *senza che l'utente li veda* non sono
     *   un comportamento umano. Un acquisto che l'utente ha confermato lui, con
     *   la sua password, lo ha visto per definizione: contarlo vorrebbe dire
     *   far scattare un allarme antifurto per colpa del proprietario.
     * - **la finestra riparte dalla riattivazione.** Altrimenti riattivare non
     *   servirebbe a niente: i dieci addebiti sarebbero ancora nell'ora appena
     *   passata e il primo acquisto dopo farebbe scattare tutto da capo.
     */
    public function recentChargesCount(): int
    {
        $minutes = (int) config('oauth.mandate.rate_limit.window_minutes', 60);

        $query = $this->charges()
            ->where('created_at', '>=', now()->subMinutes($minutes))
            ->whereDoesntHave('mandatePaymentRequest');

        if ($this->reactivated_at !== null) {
            // Strettamente DOPO, non "da": gli addebiti che hanno fatto
            // scattare l'allarme sono quelli fino a quel momento, e l'utente ha
            // appena detto che era lui. Contarli ancora vorrebbe dire
            // ri-sospendere il mandato al primo acquisto successivo.
            $query->where('created_at', '>', $this->reactivated_at);
        }

        return $query->count();
    }

    public function hasHitRateLimit(): bool
    {
        return $this->recentChargesCount() >= (int) config('oauth.mandate.rate_limit.max_charges', 10);
    }
}
