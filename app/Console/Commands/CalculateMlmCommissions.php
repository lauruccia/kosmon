<?php

namespace App\Console\Commands;

use App\Services\MlmCommissionEngine;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Job mensile: calcola le commissioni dirette e indirette per il mese
 * corrente, sulla base delle righe attive di mlm_commission_base_ledger.
 * Schedulato il 1° di ogni mese alle 02:00 (vedi routes/console.php e
 * MLM_PROPOSAL.md §5). Idempotente: rieseguirlo sullo stesso mese non
 * duplica le righe gia' create (MlmCommissionEngine::runForMonth).
 */
class CalculateMlmCommissions extends Command
{
    protected $signature = 'mlm:calculate-commissions {--month= : Mese da calcolare (YYYY-MM), default il mese corrente}';

    protected $description = 'Calcola le commissioni dirette e indirette MLM per un mese';

    public function __construct(private readonly MlmCommissionEngine $engine)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $monthOption = $this->option('month');

        // Il mese di riferimento e' quello CORRENTE (non il precedente): il ledger
        // smoothing usa valid_from/valid_until come finestra "attiva da subito",
        // quindi il calcolo del 1° del mese cattura correttamente tutti i depositi
        // ancora attivi oggi, inclusi quelli fatti nel mese appena concluso.
        $month = $monthOption
            ? Carbon::createFromFormat('Y-m', $monthOption)->startOfMonth()
            : now()->startOfMonth();

        $this->info("Calcolo commissioni MLM per {$month->format('Y-m')}...");

        $run = $this->engine->runForMonth($month);

        $direct = $run->commissions()->where('type', 'diretta')->count();
        $indirect = $run->commissions()->where('type', 'indiretta')->count();
        $total = $run->commissions()->sum('amount_eur_cents');

        $this->info("Run {$run->status}: {$direct} commissioni dirette, {$indirect} indirette, totale " . number_format($total / 100, 2, ',', '.') . " EUR.");

        return $run->status === 'completed' ? self::SUCCESS : self::FAILURE;
    }
}
