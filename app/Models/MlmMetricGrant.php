<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * "Punti/agenti omaggio" assegnati manualmente da un admin a un agente
 * (vedi migration 2026_07_14_090000_create_mlm_metric_grants_table, estesa
 * il 2026-07-15 dalla migration 2026_07_15_120000 alle 5 metriche di
 * struttura). Non scadono mai: si sommano ai valori reali calcolati da
 * MlmRankEngine::evaluate() ed User::mlmActivePoints() finche' non vengono
 * revocati esplicitamente. Sono contatori astratti: NON creano agenti o nodi
 * veri nell'albero e non generano mai bonus di struttura (quelli restano
 * legati solo alla downline reale). ECCEZIONE dal 2026-07-22 pomeriggio bis:
 * i grant della metrica 'points' (assegnati a un membro di un ramo) SONO
 * sommati ai punti reali del ramo nella vista Albero e nelle tabelle
 * "Colonne / rami" (vedi MlmTreeService::branchSummaries()/subtree()),
 * perche' contano per il requisito "colonne da 300 punti" — l'agente deve
 * poter vedere lo stesso totale che decide la qualifica. Le altre metriche
 * (clients_count, level1_basic_count, branches_with_*, branches_300pt)
 * restano contatori puri: mai mostrate nell'albero.
 *
 * @property int $id
 * @property string $uuid
 * @property int $agent_user_id
 * @property string $metric vedi self::METRICS per i valori ammessi
 * @property int $amount
 * @property string|null $reason
 * @property int|null $granted_by_admin_id
 * @property \Illuminate\Support\Carbon|null $revoked_at
 * @property int|null $revoked_by_admin_id
 * @property-read User $agent
 * @property-read User|null $grantedBy
 * @property-read User|null $revokedBy
 */
class MlmMetricGrant extends Model
{
    /**
     * Metriche regalabili => etichetta leggibile. Unica fonte usata dal
     * form di assegnazione (select), dalla validazione del controller e
     * dalla tabella "storico regali" — cosi' aggiungere una metrica in
     * futuro richiede di toccare solo questo array.
     */
    /**
     * Etichette e ORDINE del menu regali (diciture riviste da Laura il
     * 22/07: "Clienti" subito sotto i punti, "Agenti Key/Senior/Top/
     * SuperVisor" al posto di "Colonne con..."). Le chiavi restano le
     * metriche interne di MlmRankEngine: un "Agente Key" regalato conta
     * come una colonna con almeno un Key+, ecc.
     */
    public const METRICS = [
        'points' => 'Punti cliente',
        'clients_count' => 'Clienti',
        'level1_basic_count' => 'Agenti Basic (1° livello)',
        'branches_with_key' => 'Agenti Key',
        'branches_with_senior' => 'Agenti Senior',
        'branches_with_top' => 'Agenti Top',
        'branches_with_supervisor' => 'Agenti SuperVisor',
        'branches_300pt' => 'Colonne da 300 punti',
    ];

    public static function metricLabel(string $metric): string
    {
        return self::METRICS[$metric] ?? $metric;
    }

    protected $table = 'mlm_metric_grants';

    protected $fillable = [
        'uuid',
        'agent_user_id',
        'metric',
        'amount',
        'reason',
        'granted_by_admin_id',
        'revoked_at',
        'revoked_by_admin_id',
    ];

    protected $casts = [
        'revoked_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $grant): void {
            $grant->uuid ??= (string) Str::uuid();
        });
    }

    public function scopeActive($query)
    {
        return $query->whereNull('revoked_at');
    }

    /** Somma dei grant ATTIVI (non revocati) di una certa metrica per un agente. */
    public static function activeSumFor(int $agentUserId, string $metric): int
    {
        return (int) static::query()
            ->where('agent_user_id', $agentUserId)
            ->where('metric', $metric)
            ->active()
            ->sum('amount');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_user_id');
    }

    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by_admin_id');
    }

    public function revokedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by_admin_id');
    }
}
