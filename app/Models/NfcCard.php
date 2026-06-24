<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property int $company_id
 * @property int $issued_by
 * @property string|null $serial_number
 * @property string $status
 * @property string|null $pin_hash
 * @property int $pin_attempts
 * @property \Illuminate\Support\Carbon|null $pin_locked_until
 * @property int|null $limit_per_transaction
 * @property int|null $limit_daily
 * @property int|null $limit_monthly
 * @property int $daily_spent
 * @property int $monthly_spent
 * @property \Illuminate\Support\Carbon|null $daily_reset_date
 * @property string|null $monthly_reset_month
 * @property string|null $nfc_payload
 * @property string|null $notes
 * @property \Illuminate\Support\Carbon|null $issued_at
 * @property \Illuminate\Support\Carbon|null $delivered_at
 * @property \Illuminate\Support\Carbon|null $activated_at
 * @property \Illuminate\Support\Carbon|null $blocked_at
 * @property \Illuminate\Support\Carbon|null $revoked_at
 * @property \Illuminate\Support\Carbon|null $last_used_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property string|null $tracking_code
 * @property string|null $shipping_carrier
 * @property \Illuminate\Support\Carbon|null $shipped_at
 * @property int|null $pin_threshold
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\NfcCardAuthSession> $authSessions
 * @property-read int|null $auth_sessions_count
 * @property-read \App\Models\Company $company
 * @property-read \App\Models\User $issuer
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\NfcCardLog> $logs
 * @property-read int|null $logs_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NfcCard newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NfcCard newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NfcCard query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NfcCard whereActivatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NfcCard whereBlockedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NfcCard whereCompanyId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NfcCard whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NfcCard whereDailyResetDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NfcCard whereDailySpent($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NfcCard whereDeliveredAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NfcCard whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NfcCard whereIssuedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NfcCard whereIssuedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NfcCard whereLastUsedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NfcCard whereLimitDaily($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NfcCard whereLimitMonthly($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NfcCard whereLimitPerTransaction($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NfcCard whereMonthlyResetMonth($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NfcCard whereMonthlySpent($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NfcCard whereNfcPayload($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NfcCard whereNotes($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NfcCard wherePinAttempts($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NfcCard wherePinHash($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NfcCard wherePinLockedUntil($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NfcCard wherePinThreshold($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NfcCard whereRevokedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NfcCard whereSerialNumber($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NfcCard whereShippedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NfcCard whereShippingCarrier($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NfcCard whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NfcCard whereTrackingCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NfcCard whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NfcCard whereUuid($value)
 * @mixin \Eloquent
 */
class NfcCard extends Model
{
    protected $fillable = [
        'uuid', 'company_id', 'issued_by', 'serial_number', 'status',
        'pin_hash', 'pin_attempts', 'pin_locked_until',
        'limit_per_transaction', 'pin_threshold', 'limit_daily', 'limit_monthly',
        'daily_spent', 'monthly_spent', 'daily_reset_date', 'monthly_reset_month',
        'nfc_payload', 'notes',
        'tracking_code', 'shipping_carrier', 'shipped_at',
        'issued_at', 'delivered_at', 'activated_at', 'blocked_at', 'revoked_at', 'last_used_at',
    ];

    protected $casts = [
        'limit_per_transaction' => 'integer',
        'pin_threshold'    => 'integer',
        'limit_daily'      => 'integer',
        'limit_monthly'    => 'integer',
        'pin_locked_until' => 'datetime',
        'issued_at'        => 'datetime',
        'delivered_at'     => 'datetime',
        'activated_at'     => 'datetime',
        'blocked_at'       => 'datetime',
        'revoked_at'       => 'datetime',
        'last_used_at'     => 'datetime',
        'shipped_at'       => 'datetime',
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
                // Blocca la card per 1 ora dopo 3 tentativi falliti
                $update['pin_locked_until'] = now()->addHour();
            }

            $this->update($update);

            $this->logs()->create(['event' => 'auth_fail']);

            if ($attempts >= 3) {
                $this->logs()->create(['event' => 'pin_locked']);
            }

            return false;
        }

        $this->update(['pin_attempts' => 0, 'pin_locked_until' => null]);
        $this->logs()->create(['event' => 'auth_ok']);

        return true;
    }

    /**
     * Il PIN è richiesto per questo importo?
     * Soglia null = PIN sempre richiesto (default sicuro).
     * Importi >= soglia → PIN richiesto; sotto soglia → conferma senza PIN.
     *
     * @param int $amount Importo in centesimi KY
     */
    public function requiresPinFor(int $amount): bool
    {
        return $this->pin_threshold === null || $amount >= $this->pin_threshold;
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
            return [false, 'Supera il limite per transazione (' . ky_format($this->limit_per_transaction) . ' KY)'];
        }

        if ($this->limit_daily !== null && ($this->daily_spent + $amount) > $this->limit_daily) {
            $remaining = max(0, $this->limit_daily - $this->daily_spent);
            return [false, 'Supera il limite giornaliero (disponibile: ' . ky_format($remaining) . ' KY)'];
        }

        if ($this->limit_monthly !== null && ($this->monthly_spent + $amount) > $this->limit_monthly) {
            $remaining = max(0, $this->limit_monthly - $this->monthly_spent);
            return [false, 'Supera il limite mensile (disponibile: ' . ky_format($remaining) . ' KY)'];
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

        return route('nfc.card.scan-landing', ['uuid' => $uuid, 'sig' => $sig]);
    }

    /** Verifica la firma HMAC dell'UUID letto dal chip. */
    public static function verifyHmac(string $uuid, string $sig): bool
    {
        $expected = hash_hmac('sha256', $uuid, config('app.nfc_card_secret', config('app.key')));

        if (hash_equals($expected, $sig)) {
            return true;
        }

        // Retrocompatibilità con card già programmate con firma a 16 hex (deprecated).
        // Loggare per monitorare quante card legacy sono ancora in circolazione.
        if (strlen($sig) === 16 && hash_equals(substr($expected, 0, 16), $sig)) {
            \Log::warning('NFC HMAC legacy (16 hex) accettato — riprogrammare la card', ['uuid' => $uuid]);
            return true;
        }

        return false;
    }

    // ─── Numero seriale ──────────────────────────────────────────────────────

    /**
     * Genera un numero seriale univoco nel formato credit-card: XXXX-XXXX-XXXX-XXXX
     *
     * Struttura:
     *   Blocco 1-3  (12 car.)  = caratteri alfanumerici maiuscoli casuali
     *   Blocco 4    (4 car.)   = primi 4 char dell'HMAC-SHA256(blocco1+blocco2+blocco3, secret)
     *                            convertiti in base36 uppercase → garantisce autenticità
     *
     * Esempio: A3F9-K2M8-X7P1-3KQZ
     *
     * Regex di validazione: /^[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$/
     *
     * La verifica (isValidSerial) controlla il blocco 4 con la stessa chiave HMAC
     * usata in generazione, rendendo impossibile creare numeri validi senza la chiave.
     */
    public static function generateSerial(): string
    {
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';

        do {
            // Genera 12 caratteri casuali (blocchi 1-2-3)
            $body = '';
            for ($i = 0; $i < 12; $i++) {
                $body .= $chars[random_int(0, 35)];
            }

            // Blocco 4: HMAC-SHA256 del corpo con la chiave segreta, in base36 uppercase
            $check = static::computeSerialCheck($body);

            $serial = implode('-', [
                substr($body, 0, 4),
                substr($body, 4, 4),
                substr($body, 8, 4),
                $check,
            ]);

        } while (static::where('serial_number', $serial)->exists());

        return $serial;
    }

    /**
     * Valida il formato e l'autenticità di un numero seriale.
     *
     * Controlla:
     *   1. Formato regex 4×4
     *   2. Blocco 4 corrisponde all'HMAC atteso (carta emessa da noi)
     */
    public static function isValidSerial(string $serial): bool
    {
        if (! preg_match('/^([A-Z0-9]{4})-([A-Z0-9]{4})-([A-Z0-9]{4})-([A-Z0-9]{4})$/', $serial, $m)) {
            return false;
        }

        $body     = $m[1] . $m[2] . $m[3];
        $expected = static::computeSerialCheck($body);

        return hash_equals($expected, $m[4]);
    }

    /**
     * Calcola il blocco di verifica (4 char) dal corpo del seriale.
     *
     * Algoritmo:
     *   HMAC-SHA256(body, nfc_card_secret) → hex
     *   Converti i primi 8 nibble in intero → base36 uppercase, zero-padded a 4 char
     *
     * Il risultato è sempre 4 caratteri [A-Z0-9].
     */
    private static function computeSerialCheck(string $body): string
    {
        $secret = config('app.nfc_card_secret', config('app.key'));
        $hmac   = hash_hmac('sha256', $body, $secret);

        // Prendi i primi 8 hex char (32 bit) → intero → base36 uppercase, padded a 4
        $num  = hexdec(substr($hmac, 0, 8)) % (36 ** 4); // 0 … 1.679.615
        $b36  = strtoupper(base_convert((string) $num, 10, 36));

        return str_pad($b36, 4, '0', STR_PAD_LEFT);
    }
}
