<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Regola configurabile "punti per evento" (2026-07-22, vedi migration
 * create_mlm_point_rules_table): quanti punti matura l'agente diretto e per
 * quanti giorni restano attivi.
 *
 * Oggi contiene la sola riga 'registration' (apertura conto), editabile in
 * /admin/mlm-impostazioni. I punti delle RICARICHE stanno direttamente
 * sulle KY Card (KyCard::mlm_points / mlm_points_duration_days, gestite in
 * /admin/ky-cards): i tagli di ricarica sono le card reali.
 */
class MlmPointRule extends Model
{
    public const EVENT_REGISTRATION = 'registration';

    protected $fillable = [
        'event_type',
        'points',
        'duration_days',
    ];

    protected function casts(): array
    {
        return [
            'points' => 'float',
            'duration_days' => 'integer',
        ];
    }

    /** La riga di registrazione (al piu' una, vincolo unique). */
    public static function registrationRule(): ?self
    {
        return static::where('event_type', self::EVENT_REGISTRATION)->first();
    }
}
