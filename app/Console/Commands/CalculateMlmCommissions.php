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
 *
 * QUALIFICHE AGGIORNATE PRIMA DEL CALCOLO (decisione di Laura, 2026-07-29):
 * "le commissioni vanno calcolate rispetto alla qualifica di fine mese, del
 * giorno in cui viene calcolato — prima del calcolo vanno controllate le
 * qualifiche". I punti attivi sono gia' sempre ricalcolati "al volo" dal
 * ledger (User::mlmActivePoints), ma la QUALIFICA (colonna users.mlm_rank,
 * usata da MlmCommissionEngine per il gating dei livelli indiretti estesi e
 * per il conteggio dei Basic al 1° livello) e' salvata e aggiornata solo dal
 * job notturno `mlm:recalculate-points`. Nello scheduler (routes/console.php)
 * quel job gira alle 03:00, UN'ORA DOPO questo comando (02:00) lo stesso
 * giorno 1 del mese: senza l'invocazione esplicita qui sotto, il calcolo
 * userebbe ancora la qualifica fotografata la notte precedente (giorno
 * 31, ore 03:00), non quella di fine mese. Chiamare `mlm:recalculate-points`
 * subito prima rende il comando corretto indipendentemente dall'orario del
 * cron (in produzione il cron non e' sempre configurato — vedi
 * routes/console.php e i job lanciati a mano dai pulsanti admin).
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
        // Prima di calcolare, ricontrolla punti e qualifiche di tutti gli
        // agenti: cosi' il calcolo commissioni usa sempre lo stato di fatto
        // del giorno di calcolo, non quello (potenzialmente di un giorno
        // prima) fotografato dall'ultima esecuzione notturna. Idempotente e
        // gia' sicuro da rilanciare piu' volte (stesso comando usato dai
        // pulsanti admin "applica subito").
        $this->call('mlm:recalculate-points');

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
