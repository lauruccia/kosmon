<?php

namespace App\Console\Commands;

use App\Models\MlmPointLedgerEntry;
use App\Models\User;
use App\Services\MlmCommissionEngine;
use App\Services\MlmRankEngine;
use App\Services\MlmTreeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Simulatore manuale di livelli/qualifiche MLM, per uso locale.
 *
 * Costruisce un piccolo albero di agenti "finti" con i punti che SCEGLI TU
 * (non presi dal database reale) e mostra subito il grado risultante di
 * ciascuno, usando la STESSA logica di MlmRankEngine::evaluate() e
 * MlmCommissionEngine usate in produzione (nessuna logica duplicata).
 *
 * Tutto avviene dentro una transazione DB che viene SEMPRE annullata
 * (rollback) alla fine, con successo o con errore: non scrive nulla di
 * permanente, ne' sul database di sviluppo ne' tantomeno su quello di
 * produzione. E' sicuro da lanciare quante volte vuoi.
 *
 * USO:
 *   php artisan mlm:simula
 *       -> usa lo scenario di esempio in _dev-tools/mlm_simulator/scenario_esempio.php
 *
 *   php artisan mlm:simula _dev-tools/mlm_simulator/mio_scenario.php
 *       -> usa un file scenario a tua scelta (copia l'esempio e modifica i numeri)
 *
 * Il file di scenario e' un normale file PHP che restituisce un array di
 * agenti: vedi _dev-tools/mlm_simulator/scenario_esempio.php per il formato
 * commentato e qualche esempio pronto (uno che arriva a Senior, uno a Top).
 */
class MlmSimulateLevels extends Command
{
    protected $signature = 'mlm:simula {file? : percorso al file di scenario (default: scenario di esempio)}';

    protected $description = 'Simula un albero di agenti con punti scelti a mano e mostra il grado risultante di ciascuno (nessuna scrittura permanente, rollback automatico)';

    public function handle(MlmCommissionEngine $commissionEngine): int
    {
        $path = $this->argument('file') ?? base_path('_dev-tools/mlm_simulator/scenario_esempio.php');

        if (! is_file($path)) {
            $this->error("File di scenario non trovato: {$path}");

            return self::FAILURE;
        }

        $scenario = require $path;

        if (! is_array($scenario) || empty($scenario)) {
            $this->error('Il file di scenario deve restituire un array non vuoto di agenti. Vedi scenario_esempio.php.');

            return self::FAILURE;
        }

        $this->info("Scenario caricato da: {$path}");
        $this->newLine();

        DB::beginTransaction();

        try {
            $this->runScenario($scenario, $commissionEngine);
        } catch (\Throwable $e) {
            $this->error('Errore durante la simulazione: ' . $e->getMessage());

            return self::FAILURE;
        } finally {
            DB::rollBack();
            $this->newLine();
            $this->comment('Nessuna modifica e stata salvata: rollback automatico a fine simulazione.');
        }

        return self::SUCCESS;
    }

    /**
     * @param array<int, array{nome: string, sponsor?: ?string, punti?: int}> $scenario
     */
    private function runScenario(array $scenario, MlmCommissionEngine $commissionEngine): void
    {
        $tree = new MlmTreeService();
        $rankEngine = app(MlmRankEngine::class);

        $agentsByName = [];
        $order = [];

        // Passata 1: crea gli agenti finti e li aggancia nell'albero nell'ordine
        // in cui compaiono nel file (uno sponsor deve comparire PRIMA di chi
        // sponsorizza, esattamente come nella realta').
        foreach ($scenario as $i => $row) {
            $nome = $row['nome'] ?? null;
            $sponsorNome = array_key_exists('sponsor', $row) ? $row['sponsor'] : null;
            $punti = (int) ($row['punti'] ?? 0);

            if (! is_string($nome) || $nome === '') {
                throw new \InvalidArgumentException("Riga #{$i} dello scenario: manca il campo 'nome'.");
            }

            if (isset($agentsByName[$nome])) {
                throw new \InvalidArgumentException("Nome duplicato nello scenario: '{$nome}'. Ogni agente deve avere un nome unico.");
            }

            if ($sponsorNome !== null && ! isset($agentsByName[$sponsorNome])) {
                throw new \InvalidArgumentException(
                    "Sponsor '{$sponsorNome}' (per l'agente '{$nome}') non trovato: lo sponsor deve comparire PRIMA nella lista."
                );
            }

            $agent = User::create([
                'name' => $nome,
                'email' => 'sim-' . Str::slug($nome) . '-' . Str::random(6) . '@simulatore.local',
                'password' => 'secret123',
                'account_holder_type' => 'private',
                'company_id' => null,
                'is_active' => true,
                'mlm_role' => 'agente',
                'mlm_rank' => 'start',
                'mlm_activated_at' => now(),
            ]);

            $sponsor = $sponsorNome !== null ? $agentsByName[$sponsorNome] : null;
            $tree->attachAgent($agent, $sponsor);

            if ($punti > 0) {
                $this->assignActivePoints($agent, $punti);
            }

            $agentsByName[$nome] = $agent;
            $order[] = $nome;
        }

        // Passata 2: rivaluta i gradi dal basso verso l'alto (foglie prima
        // della radice, ordinati per profondita' massima decrescente nella
        // closure table) esattamente come fa il job notturno reale
        // (mlm:recalculate-points): un figlio va valutato prima del padre
        // perche' i requisiti "colonne con un Key/Senior/Top" dipendono dal
        // grado GIA' aggiornato dei discendenti.
        $agentIds = collect($agentsByName)->pluck('id');

        $depths = DB::table('mlm_agent_closure')
            ->whereIn('descendant_id', $agentIds)
            ->selectRaw('descendant_id, MAX(depth) as max_depth')
            ->groupBy('descendant_id')
            ->pluck('max_depth', 'descendant_id');

        $byDepthDesc = collect($agentsByName)
            ->sortByDesc(fn (User $a) => (int) ($depths[$a->id] ?? 0))
            ->values();

        $evaluations = [];
        foreach ($byDepthDesc as $agent) {
            $fresh = $agent->fresh();
            $evaluation = $rankEngine->evaluate($fresh);
            $fresh->forceFill(['mlm_rank' => $evaluation['eligible_rank']])->save();
            $evaluations[$fresh->name] = $evaluation;
        }

        // Passata 3: stampa i risultati nell'ordine in cui erano nel file
        // (piu' facile da confrontare con lo scenario originale).
        foreach ($order as $nome) {
            $this->printAgentReport($nome, $evaluations[$nome], $commissionEngine, $scenario);
        }
    }

    private function assignActivePoints(User $agent, int $points): void
    {
        $client = User::create([
            'name' => 'Cliente-sim-' . Str::random(6),
            'email' => 'sim-cliente-' . Str::random(10) . '@simulatore.local',
            'password' => 'secret123',
            'account_holder_type' => 'private',
            'company_id' => null,
            'is_active' => true,
            'mlm_role' => 'cliente',
            'mlm_client_agent_id' => $agent->id,
        ]);

        MlmPointLedgerEntry::create([
            'agent_user_id' => $agent->id,
            'client_user_id' => $client->id,
            'source_type' => 'registration',
            'points' => $points,
            'valid_from' => now()->subDay()->toDateString(),
            'valid_until' => now()->addMonth()->toDateString(),
        ]);
    }

    private function printAgentReport(string $nome, array $evaluation, MlmCommissionEngine $commissionEngine, array $scenario): void
    {
        $row = collect($scenario)->firstWhere('nome', $nome) ?? [];
        $sponsor = $row['sponsor'] ?? null;

        $this->line("<fg=cyan;options=bold>=== {$nome}</> " . ($sponsor ? "(sponsor: {$sponsor})" : '(radice)') . ' <fg=cyan;options=bold>===</>');

        $this->table(
            ['Punti attivi', 'Basic 1 livello', 'Colonne >=300pt', 'Colonne Key+', 'Colonne Senior+', 'Colonne Top+', 'Colonne SuperVisor+'],
            [[
                $evaluation['points'],
                $evaluation['level1_basic_count'],
                $evaluation['branches_300pt'],
                $evaluation['branches_with_key'],
                $evaluation['branches_with_senior'],
                $evaluation['branches_with_top'],
                $evaluation['branches_with_supervisor'],
            ]]
        );

        $this->line('Grado risultante: <fg=green;options=bold>' . strtoupper($evaluation['eligible_rank']) . '</>');
        $this->newLine();

        $this->line('Requisiti per grado (soddisfatto/no):');
        foreach ($evaluation['satisfied'] as $rank => $ok) {
            $marker = $ok ? '<fg=green>[OK]</>' : '<fg=red>[--]</>';
            $this->line('  ' . str_pad($rank, 12) . $marker);
        }
        $this->newLine();

        $directPct = $commissionEngine->directPercentage($evaluation['points']);
        $this->line('Percentuale diretta su questi punti: <fg=yellow>' . round($directPct * 100, 1) . '%</>');
        $this->newLine();

        $this->line('Gating commissioni indirette (per incassare quanto guadagnano i suoi agenti):');
        $gating = $commissionEngine->indirectGatingStatus($evaluation['points'], $evaluation['level1_basic_count']);
        $labels = ['I', 'II', 'III', 'IV', 'V'];
        foreach ($gating as $level => $g) {
            $marker = $g['met'] ? '<fg=green>[OK]</>' : '<fg=red>[--]</>';
            $label = str_pad('Livello ' . $labels[$level - 1], 12);
            $this->line("  {$label}({$g['required_points']}pt/{$g['required_basics']} basic): {$marker}");
        }

        $this->newLine();
        $this->line(str_repeat('-', 60));
        $this->newLine();
    }
}
