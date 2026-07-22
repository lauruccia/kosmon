<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Regola configurabile "punti per evento" (2026-07-22, vedi migration
 * create_mlm_point_rules_table): quanti punti matura l'agente diretto e per
 * quanti giorni restano attivi, per ciascun evento del cliente.
 *
 *  - event_type 'registration' (deposit_amount_eur_cents NULL): apertura conto.
 *  - event_type 'deposit': una riga per ogni taglio di ricarica disponibile
 *    (l'admin gestisce i tagli da /admin/mlm-impostazioni). Alla ricarica si
 *    applica la riga col taglio piu' alto <= importo (vedi
 *    MlmPointsService::depositRuleFor()).
 */
class MlmPointRule extends Model
{
    public const EVENT_REGISTRATION = 'registration';
    public const EVENT_DEPOSIT = 'deposit';

    protected $fillable = [
        'event_type',
        'deposit_amount_eur_cents',
        'points',
        'duration_days',
    ];

    protected function casts(): array
    {
        return [
            'deposit_amount_eur_cents' => 'integer',
            'points' => 'float',
            'duration_days' => 'integer',
        ];
    }

    /** La riga di registrazione (al piu' una, vincolo unique). */
    public static function registrationRule(): ?self
    {
        return static::where('event_type', self::EVENT_REGISTRATION)->first();
    }

    /**
     * La regola deposito applicabile a un importo: il taglio piu' alto
     * <= importo. NULL se l'importo e' sotto il taglio minimo configurato.
     */
    public static function depositRuleFor(int $depositEurCents): ?self
    {
        return static::where('event_type', self::EVENT_DEPOSIT)
            ->where('deposit_amount_eur_cents', '<=', $depositEurCents)
            ->orderByDesc('deposit_amount_eur_cents')
            ->first();
    }
}
