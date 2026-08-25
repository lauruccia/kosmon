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
     * Quanti addebiti automatici nella finestra dell'antifurto.
     */
    public function recentChargesCount(): int
    {
        $minutes = (int) config('oauth.mandate.rate_limit.window_minutes', 60);

        return $this->charges()
            ->where('created_at', '>=', now()->subMinutes($minutes))
            ->count();
    }

    public function hasHitRateLimit(): bool
    {
        return $this->recentChargesCount() >= (int) config('oauth.mandate.rate_limit.max_charges', 10);
    }
}
