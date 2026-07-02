<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\MlmBonusService;
use App\Services\MlmRankEngine;
use Illuminate\Console\Command;

/**
 * Job giornaliero di manutenzione MLM, in due passate:
 *
 * PASSATA 1 - Rilevamento "BasiQ": un agente diventa BasiQ se raggiunge 12 punti
 *    attivi entro 30 giorni dalla propria attivazione (mlm_activated_at).
 *    E' lo stato che fara' scattare i bonus di struttura (Fase 4, non
 *    ancora implementata). Chi supera i 30 giorni senza raggiungere 12
 *    punti NON diventera' mai BasiQ (mlm_basiq_at resta null per sempre)
 *    — decisione confermata in MLM_PROPOSAL.md §7.3: puo' comunque
 *    diventare "Basic" come qualifica (vedi passata 2), ma non generera'
 *    mai bonus di struttura per l'upline.
 *
 * PASSATA 2 - Valutazione qualifiche: per ogni agente, MlmRankEngine::promoteIfEligible()
 *    controlla se soddisfa i requisiti di una qualifica piu' alta e, in
 *    caso, lo promuove registrando lo storico (mlm_rank_history).
 *
 * I punti attivi non necessitano di "scadenza" esplicita qui: la finestra
 * di validita' e' gia' gestita a livello di query (vedi User::mlmActivePoints()).
 */
class RecalculateMlmPoints extends Command
{
    protected $signature = 'mlm:recalculate-points';

    protected $description = 'Rileva i nuovi BasiQ e valuta l\'avanzamento di qualifica per tutti gli agenti';

    public function __construct(
        private readonly MlmRankEngine $rankEngine,
        private readonly MlmBonusService $bonusService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $candidates = User::query()
            ->where('mlm_role', 'agente')
            ->whereNull('mlm_basiq_at')
            ->whereNotNull('mlm_activated_at')
            ->where('mlm_activated_at', '>=', now()->subDays(30))
            ->get();

        $newlyBasiq = 0;

        foreach ($candidates as $agent) {
            if ($agent->mlmActivePoints() >= 12) {
                $agent->forceFill([
                    'mlm_basiq_at' => now(),
                    'mlm_basiq_bonus_eligible' => true,
                ])->save();

                AuditLog::create([
                    'actor_user_id'  => null,
                    'event'          => 'mlm.basiq_reached',
                    'auditable_type' => User::class,
                    'auditable_id'   => $agent->id,
                    'context'        => [
                        'active_points'    => $agent->mlmActivePoints(),
                        'mlm_activated_at' => optional($agent->mlm_activated_at)->toDateTimeString(),
                    ],
                ]);

                // Fase 4: genera subito la cascata bonus per l'upline (accredito
                // effettivo il mercoledi', vedi MlmBonusService).
                $this->bonusService->processBasiqEvent($agent);

                $newlyBasiq++;
            }
        }

        $this->info("Verificati {$candidates->count()} candidati, {$newlyBasiq} nuovi BasiQ rilevati.");

        $agents = User::where('mlm_role', 'agente')->get();
        $promoted = 0;

        foreach ($agents as $agent) {
            if ($this->rankEngine->promoteIfEligible($agent)) {
                $promoted++;
            }
        }

        $this->info("Valutati {$agents->count()} agenti, {$promoted} promozioni di qualifica.");

        return self::SUCCESS;
    }
}
