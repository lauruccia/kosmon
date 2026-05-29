<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class NfcCard extends Model
{
    protected $fillable = [
        'uuid', 'company_id', 'issued_by', 'serial_number', 'status',
        'pin_hash', 'pin_attempts', 'pin_locked_until',
        'limit_per_transaction', 'limit_daily', 'limit_monthly',
        'daily_spent', 'monthly_spent', 'daily_reset_date', 'monthly_reset_month',
        'nfc_payload', 'notes',
        'issued_at', 'delivered_at', 'activated_at', 'blocked_at', 'revoked_at', 'last_used_at',
    ];

    protected $casts = [
        'pin_locked_until' => 'datetime',
        'issued_at'        => 'datetime',
        'delivered_at'     => 'datetime',
        'activated_at'     => 'datetime',
        'blocked_at'       => 'datetime',
        'revoked_at'       => 'datetime',
        'last_used_at'     => 'datetime',
        'daily_reset_date' => 'date',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $card) {
            if (empty($card->uuid)) {
                $card->uuid = (string) Str::uuid();
            }
        });
    }

    // ─── Relazioni ────────────────────────────────────────────────────────────

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(NfcCardLog::class);
    }

    public function authSessions(): HasMany
    {
        return $this->hasMany(NfcCardAuthSession::class);
    }

    // ─── Stato ───────────────────────────────────────────────────────────────

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isBlocked(): bool
    {
        return $this->status === 'blocked';
    }

    public function isRevoked(): bool
    {
        return $this->status === 'revoked';
    }

    public function isPinLocked(): bool
    {
        return $this->pin_locked_until !== null && $this->pin_locked_until->isFuture();
    }

    // ─── PIN ─────────────────────────────────────────────────────────────────

    public function setPin(string $pin): void
    {
        $this->update([
            'pin_hash'     => Hash::make($pin),
            'pin_attempts' => 0,
            'pin_locked_until' => null,
        ]);
    }

    public function verifyPin(string $pin): bool
    {
        if ($this->isPinLocked()) {
            return false;
        }

        if (! Hash::check($pin, $this->pin_hash)) {
            $attempts = $this->pin_attempts + 1;
            $update   = ['pin_attempts' => $attempts];

            if ($attempts >= 3) {
                $update['pin_locked_until'] = now()->addMinutes(30);
                $update['pin_attempts']     = 0;

                $this->logs()->create(['event' => 'pin_locked']);
            }

            $this->update($update);

            $this->logs()->create(['event' => 'auth_fail']);

            return false;
        }

        $this->update(['pin_attempts' => 0, 'pin_locked_until' => null]);
        $this->logs()->create(['event' => 'auth_ok']);

        return true;
    }

    // ─── Limiti ──────────────────────────────────────────────────────────────

    /**
     * Verifica se l'importo rientra nei limiti della card.
     * Aggiorna anche i contatori giornalieri/mensili se scaduti.
     */
    public function checkLimits(int $amount): array
    {
        $this->refreshSpentCounters();
        $this->refresh();

        if ($this->limit_per_transaction !== null && $amount > $this->limit_per_transaction) {
            return [false, "Supera il limite per transazione ({$this->limit_per_transaction} KY)"];
        }

        if ($this->limit_daily !== null && ($this->daily_spent + $amount) > $this->limit_daily) {
            $remaining = max(0, $this->limit_daily - $this->daily_spent);
            return [false, "Supera il limite giornaliero (disponibile: {$remaining} KY)"];
        }

        if ($this->limit_monthly !== null && ($this->monthly_spent + $amount) > $this->limit_monthly) {
            $remaining = max(0, $this->limit_monthly - $this->monthly_spent);
            return [false, "Supera il limite mensile (disponibile: {$remaining} KY)"];
        }

        return [true, null];
    }

    /** Incrementa i contatori dopo un pagamento riuscito. */
    public function recordSpent(int $amount): void
    {
        $this->refreshSpentCounters();
        $this->increment('daily_spent', $amount);
        $this->increment('monthly_spent', $amount);
        $this->update(['last_used_at' => now()]);
    }

    private function refreshSpentCounters(): void
    {
        $today = now()->toDateString();
        $month = now()->format('Y-m');

        $update = [];

        if ($this->daily_reset_date?->toDateString() !== $today) {
            $update['daily_spent']      = 0;
            $update['daily_reset_date'] = $today;
        }

        if ($this->monthly_reset_month !== $month) {
            $update['monthly_spent']        = 0;
            $update['monthly_reset_month']  = $month;
        }

        if (! empty($update)) {
            $this->update($update);
        }
    }

    // ─── HMAC payload ────────────────────────────────────────────────────────

    /** Genera l'URL da scrivere sul chip NFC. */
    public static function buildPayload(string $uuid): string
    {
        $sig = hash_hmac('sha256', $uuid, config('app.nfc_card_secret', config('app.key')));
        $shortSig = substr($sig, 0, 16);

        return route('nfc.card.scan-landing', ['uuid' => $uuid, 'sig' => $shortSig]);
    }

    /** Verifica la firma HMAC dell'UUID letto dal chip. */
    public static function verifyHmac(string $uuid, string $sig): bool
    {
        $expected = hash_hmac('sha256', $uuid, config('app.nfc_card_secret', config('app.key')));
        $expectedShort = substr($expected, 0, 16);

        return hash_equals($expectedShort, $sig);
    }
}
