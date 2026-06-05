<?php

namespace App\Models;

use App\Models\Account;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashbackRule extends Model
{
    protected $fillable = [
        'name',
        'min_amount',
        'percentage',
        'max_cashback',
        'applicable_kinds',
        'is_active',
        'valid_from',
        'valid_until',
        'created_by',
        'target_type',
        'target_user_id',
    ];

    protected $casts = [
        'applicable_kinds' => 'array',
        'is_active'        => 'boolean',
        'valid_from'       => 'date',
        'valid_until'      => 'date',
        'min_amount'       => 'integer',
        'max_cashback'     => 'integer',
        'percentage'       => 'decimal:2',
        'target_type'      => 'string',
        'target_user_id'   => 'integer',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function targetUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }

    public function isCurrentlyActive(): bool
    {
        if (! $this->is_active) {
            return false;
        }
        $today = now()->toDateString();
        if ($this->valid_from && $this->valid_from->toDateString() > $today) {
            return false;
        }
        if ($this->valid_until && $this->valid_until->toDateString() < $today) {
            return false;
        }
        return true;
    }

    /**
     * Verifica se la regola si applica al conto beneficiario specificato.
     */
    public function appliesTo(Account $account): bool
    {
        return match ($this->target_type ?? 'all') {
            'company'       => $account->owner_type === 'company',
            'personal'      => $account->owner_type === 'private',
            'specific_user' => $this->target_user_id !== null
                               && $account->ownerUser?->id === $this->target_user_id,
            default         => true, // 'all'
        };
    }

    /**
     * Calcola l'importo di cashback per un trasferimento dato.
     * Restituisce 0 se la regola non si applica.
     */
    public function calculateCashback(int $amount, string $kind): int
    {
        if (! $this->isCurrentlyActive()) {
            return 0;
        }
        if ($amount < $this->min_amount) {
            return 0;
        }
        if (! in_array($kind, $this->applicable_kinds ?? [], true)
            && ! in_array('*', $this->applicable_kinds ?? [], true)) {
            return 0;
        }

        $cashback = (int) round($amount * ($this->percentage / 100));

        if ($this->max_cashback !== null) {
            $cashback = min($cashback, $this->max_cashback);
        }

        return $cashback;
    }

    public static function targetTypeOptions(): array
    {
        return [
            'all'           => 'Tutti (aziende e privati)',
            'company'       => 'Solo aziende',
            'personal'      => 'Solo privati',
            'specific_user' => 'Utente specifico',
        ];
    }

    public static function kindOptions(): array
    {
        return [
            'portal_payment'        => 'Pagamento diretto',
            'portal_text_request'   => 'Richiesta testo',
            'portal_qr_payment'     => 'QR Incasso',
            'nfc'                   => 'NFC (smartphone-to-smartphone)',
            'nfc_card'              => 'Pagamento Card NFC fisica',
            'portal_installment'    => 'Rata piano rateale',
            'api_payment'           => 'Pagamento API',
            '*'                     => 'Tutti i tipi',
        ];
    }
}
