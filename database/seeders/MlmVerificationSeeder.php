<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\MlmMetricGrant;
use App\Models\MlmPointLedgerEntry;
use App\Models\Role;
use App\Models\User;
use App\Services\MlmTreeService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Albero MLM DETERMINISTICO per verificare "a mano" il calcolo di bonus e
 * commissioni su TUTTI i livelli/gradi in un colpo solo (richiesta di Laura,
 * 30/07/2026: "un seed per caricare tutti gli agenti nell'albero e i clienti
 * per verificare istantaneamente il calcolo di bonus e commissioni di tutti
 * i livelli").
 *
 * A differenza di MlmTreeSeeder (che genera un albero ampio ma CASUALE, utile
 * per guardare "Struttura"/"Albero agenti" pieni), qui ogni numero e' scelto
 * a mano cosi' i risultati attesi si possono calcolare a mente e confrontare
 * con l'output reale dei comandi. Vedi il file
 * MLM_VERIFICATION_SEED_RISULTATI_ATTESI.md consegnato insieme a questo seeder
 * per la tabella completa dei risultati attesi.
 *
 * STRUTTURA (una sola "spina dorsale" verticale, cosi' un solo albero
 * copre contemporaneamente sia la cascata bonus BasiQ→upline sia le
 * commissioni indirette su tutti i livelli):
 *
 *   Manager (mgr, radice)
 *    └─ SuperVisor (sv)
 *        └─ Top (top)
 *            └─ Senior (sr)
 *                └─ Key (key)
 *                    ├─ basiq_demo   (rank iniziale 'start', 12 punti via
 *                    │                registrazione cliente, attivato 5gg fa:
 *                    │                candidato reale a BasiQ per
 *                    │                mlm:recalculate-points)
 *                    ├─ chain_1..10  (coda profonda per testare i livelli
 *                    │                indiretti 6+ dei gradi estesi;
 *                    │                chain_4 = rank 'top' per dimostrare il
 *                    │                blocco "stesso grado o superiore")
 *                    └─ key_c1..3    (figli 'basic' reali, per sbloccare il
 *                                     gating livello V=48pt/3basic su key)
 *    + mgr_c1..3, sv_c1..3, top_c1..3, sr_c1..3: figli 'basic' reali di
 *      ciascun nodo della spina, stesso scopo (gating indiretto livello V)
 *      e allo stesso tempo test dei tagli % dirette (12/24/48/96/150/200pt).
 *    + tier0, tier5: due agenti extra sotto mgr con 0 e 6 punti, per
 *      dimostrare i tagli 0%/5% delle commissioni dirette.
 *
 * Ogni agente ha ESATTAMENTE UN cliente diretto con un deposito da 1.000,00 €
 * (margine KNM 30% → base Prov K = 300,00 € per cliente, tranne dove
 * diversamente indicato) cosi' la matematica resta la stessa ovunque:
 * commissione diretta = 300 € × percentuale del proprio grado punti.
 *
 * mgr/sv/top/sr/key hanno inoltre 200 punti personali propri (taglio diretto
 * 40%) e "grant" (MlmMetricGrant) che coprono INTERAMENTE i requisiti
 * strutturali del proprio grado (clienti, colonne, Basic al 1° livello) COSI'
 * CHE IL GRADO REGGA anche dopo che mlm:calculate-commissions richiama
 * internamente mlm:recalculate-points (che rivaluta per davvero ogni grado
 * dalla struttura reale). I grant NON influenzano pero' il conteggio "Basic
 * al 1° livello" usato dal MOTORE COMMISSIONI per il gating dei livelli
 * indiretti (quello e' sempre calcolato dalla struttura vera, mai dai grant)
 * — per questo key/sr/top/sv/mgr hanno comunque, ciascuno, 3 figli REALI
 * 'basic' (key_c1..3 ecc.) con punti e clienti reali sufficienti.
 *
 * Isolato con prefisso email "mlmverify-": rieseguibile, pulisce prima di
 * ricreare, non tocca altri dati demo (stesso pattern di MlmTreeSeeder).
 */
class MlmVerificationSeeder extends Seeder
{
    private const EMAIL_PREFIX = 'mlmverify-';

    private MlmTreeService $tree;
    private ?Role $baseRole = null;
    private int $counter = 0;

    /** @var array<string, User> */
    private array $agents = [];

    public function run(): void
    {
        $this->tree = app(MlmTreeService::class);
        $this->baseRole = Role::query()->where('slug', 'private-member')->first();

        $this->cleanPreviousSeed();

        $this->command?->info('Creazione albero MLM deterministico di verifica...');

        DB::transaction(function (): void {
            $this->buildSpine();
            $this->buildStructuralFillers();
            $this->buildDirectTierExtras();
            $this->buildBasiqDemo();
            $this->buildDeepChain();
        });

        $totalUsers = User::where('email', 'like', self::EMAIL_PREFIX . '%')->count();
        $this->command?->info('  OK: ' . count($this->agents) . ' agenti, ' . ($totalUsers - count($this->agents)) . ' clienti, ' . $totalUsers . ' utenti totali (prefisso ' . self::EMAIL_PREFIX . ').');
        $this->command?->info('  Prossimi passi: php artisan mlm:recalculate-points, poi mlm:calculate-weekly-bonuses, poi mlm:calculate-commissions.');
        $this->command?->info('  Vedi MLM_VERIFICATION_SEED_RISULTATI_ATTESI.md per i numeri attesi.');
    }

    // -------------------------------------------------------------------

    private function cleanPreviousSeed(): void
    {
        $existingIds = User::where('email', 'like', self::EMAIL_PREFIX . '%')->pluck('id');

        if ($existingIds->isEmpty()) {
            return;
        }

        $this->command?->info('  Rimozione seed di verifica precedente (' . $existingIds->count() . ' utenti)...');

        Account::whereIn('owner_user_id', $existingIds)->delete();
        User::whereIn('id', $existingIds)->delete();
    }

    /** I 5 nodi della spina, dal basso (key) verso l'alto (mgr) sono agganciati nell'ordine mgr->sv->top->sr->key. */
    private function buildSpine(): void
    {
        $mgr = $this->makeAgent('mgr', 'manager', personalPoints: 200, activatedDaysAgo: 400);
        $this->tree->attachAgent($mgr, null);
        $this->grant($mgr, [
            'clients_count' => 24,
            'level1_basic_count' => 6,
            'branches_with_supervisor' => 3,
        ]);

        $sv = $this->makeAgent('sv', 'supervisor', personalPoints: 200, activatedDaysAgo: 400);
        $this->tree->attachAgent($sv, $mgr);
        $this->grant($sv, [
            'clients_count' => 24,
            'level1_basic_count' => 5,
            'branches_with_senior' => 4,
            'branches_with_top' => 2,
        ]);

        $top = $this->makeAgent('top', 'top', personalPoints: 200, activatedDaysAgo: 400);
        $this->tree->attachAgent($top, $sv);
        $this->grant($top, [
            'clients_count' => 24,
            'level1_basic_count' => 4,
            'branches_300pt' => 3,
        ]);

        $sr = $this->makeAgent('sr', 'senior', personalPoints: 200, activatedDaysAgo: 400);
        $this->tree->attachAgent($sr, $top);
        $this->grant($sr, [
            'clients_count' => 24,
            'level1_basic_count' => 3,
            'branches_with_key' => 2,
        ]);

        $key = $this->makeAgent('key', 'key', personalPoints: 200, activatedDaysAgo: 400);
        $this->tree->attachAgent($key, $sr);
        $this->grant($key, [
            'clients_count' => 12,
            'level1_basic_count' => 2,
        ]);

        foreach (['mgr', 'sv', 'top', 'sr', 'key'] as $name) {
            $this->makeClientWithDeposit($this->agents[$name], 100_000);
        }
    }

    /**
     * 3 figli REALI 'basic' per ciascun nodo della spina: sbloccano il
     * gating "3 Basic al 1° livello" del livello indiretto V (48pt/3basic) —
     * quel conteggio lo fa SEMPRE il motore commissioni dalla struttura vera,
     * mai dai grant. Punti scelti a scaletta (12/24/48/96/150/200) cosi'
     * ognuno testa anche un taglio diverso della tabella diretta (§5.1).
     */
    private function buildStructuralFillers(): void
    {
        $pointsCycle = [12, 24, 48, 96, 150, 200];
        $i = 0;

        foreach (['mgr', 'sv', 'top', 'sr', 'key'] as $parentName) {
            $parent = $this->agents[$parentName];

            for ($n = 1; $n <= 3; $n++) {
                $points = $pointsCycle[$i % count($pointsCycle)];
                $i++;

                $child = $this->makeAgent("{$parentName}_c{$n}", 'basic', personalPoints: $points, activatedDaysAgo: 400);
                $this->tree->attachAgent($child, $parent);
                // Basic reale: min_points=12 (soddisfatto da tutti i punti scelti sopra), min_clients=6 (1 reale + 5 omaggio).
                $this->grant($child, ['clients_count' => 5]);
                $this->makeClientWithDeposit($child, 100_000);
            }
        }
    }

    /** 2 agenti extra sotto mgr per dimostrare i tagli diretti 0% e 5% (punti sotto la soglia Basic). */
    private function buildDirectTierExtras(): void
    {
        $mgr = $this->agents['mgr'];

        $tier0 = $this->makeAgent('tier0', 'start', personalPoints: 0, activatedDaysAgo: 400);
        $this->tree->attachAgent($tier0, $mgr);
        $this->makeClientWithDeposit($tier0, 100_000);

        $tier5 = $this->makeAgent('tier5', 'start', personalPoints: 6, activatedDaysAgo: 400);
        $this->tree->attachAgent($tier5, $mgr);
        $this->makeClientWithDeposit($tier5, 100_000);
    }

    /**
     * L'agente che diventera' BasiQ quando lancerai `php artisan
     * mlm:recalculate-points`: NON impostiamo qui ne' mlm_basiq_at ne'
     * l'evento bonus, cosi' il comando reale lo rileva da solo (12 punti
     * attivi raggiunti entro 30gg dall'attivazione, come da regola vera).
     * Upline attesa (da questo nodo verso la radice): Key(60) → Senior(50)
     * → Top(40) → SuperVisor(30) → Manager(20) = 200€ totali, esattamente
     * la tabella verificata in MLM_PROPOSAL.md §6.4 estesa di un grado.
     */
    private function buildBasiqDemo(): void
    {
        $key = $this->agents['key'];

        $basiqDemo = $this->makeAgent('basiq_demo', 'start', personalPoints: null, activatedDaysAgo: 5);
        $this->tree->attachAgent($basiqDemo, $key);

        // 12 punti da REGISTRAZIONE cliente (non deposito: niente base
        // commissioni per questo nodo, serve solo a far scattare BasiQ).
        $client = $this->makeClient($basiqDemo, 'reg');
        MlmPointLedgerEntry::create([
            'uuid' => (string) Str::uuid(),
            'agent_user_id' => $basiqDemo->id,
            'client_user_id' => $client->id,
            'source_type' => 'registration',
            'points' => 12,
            'valid_from' => now()->subDays(4),
            'valid_until' => now()->addYears(2),
        ]);
    }

    /**
     * Coda profonda di 10 nodi sotto key, per testare i livelli indiretti
     * 6+ (percentuale estesa 0,5%, solo per Top/SuperVisor/Manager) e la
     * regola di blocco "primo grado pari o superiore incontrato lungo il
     * ramo, poi si paga solo altri 5 livelli". chain_4 = 'top' (rank 4):
     * blocca la discesa di TOP (anche lui rank 'top') dal suo livello 6 in
     * poi, fermandosi al livello 11 (6+5) — ma NON blocca SuperVisor ne'
     * Manager (rank piu' alto del blocco), che restano illimitati fino in
     * fondo alla catena. Vedi il file dei risultati attesi per i dettagli
     * livello per livello.
     */
    private function buildDeepChain(): void
    {
        $parent = $this->agents['key'];

        for ($n = 1; $n <= 10; $n++) {
            $rank = $n === 4 ? 'top' : 'start';
            $points = $n === 4 ? 200 : 0;

            $node = $this->makeAgent("chain_{$n}", $rank, personalPoints: $points, activatedDaysAgo: 400);
            $this->tree->attachAgent($node, $parent);

            if ($n === 4) {
                // Stessi grant di 'top' per reggere il grado a struttura reale zero.
                $this->grant($node, [
                    'clients_count' => 24,
                    'level1_basic_count' => 4,
                    'branches_300pt' => 3,
                ]);
            }

            $this->makeClientWithDeposit($node, 100_000);

            $parent = $node;
        }
    }

    // -------------------------------------------------------------------

    private function grant(User $agent, array $metrics): void
    {
        foreach ($metrics as $metric => $amount) {
            MlmMetricGrant::create([
                'agent_user_id' => $agent->id,
                'metric' => $metric,
                'amount' => $amount,
                'reason' => 'Seed di verifica MLM (mlm:seed-verifica) — copre il requisito strutturale senza dover costruire l\'albero reale.',
            ]);
        }
    }

    private function makeAgent(string $name, string $rank, ?int $personalPoints, int $activatedDaysAgo): User
    {
        $activatedAt = now()->subDays($activatedDaysAgo);

        $user = User::create([
            'company_id' => null,
            'account_holder_type' => 'private',
            'name' => 'MLM-' . $name,
            'email' => $this->email($name),
            'password' => 'secret123',
            'role' => 'private-member',
            'is_active' => true,
            'is_super_admin' => false,
        ]);

        $user->forceFill([
            'email_verified_at' => now(),
            'contract_signed_at' => $activatedAt,
            'mlm_role' => 'agente',
            'mlm_rank' => $rank,
            'mlm_rank_updated_at' => $activatedAt,
            'mlm_activated_at' => $activatedAt,
            'mlm_agent_request_status' => 'approved',
            'mlm_agent_requested_at' => $activatedAt->copy()->subDay(),
            'mlm_agent_reviewed_at' => $activatedAt,
            'mlm_agent_contract_signed_at' => $activatedAt,
        ])->save();

        if ($this->baseRole) {
            $user->roles()->sync([$this->baseRole->id]);
        }

        $this->makeAccount($user);

        if ($personalPoints !== null && $personalPoints > 0) {
            $selfClient = $this->makeClient($user, 'self');
            MlmPointLedgerEntry::create([
                'uuid' => (string) Str::uuid(),
                'agent_user_id' => $user->id,
                'client_user_id' => $selfClient->id,
                'source_type' => 'deposit',
                'points' => $personalPoints,
                'valid_from' => now()->subYear(),
                'valid_until' => now()->addYears(2),
            ]);
        }

        $this->agents[$name] = $user;

        return $user;
    }

    private function makeClient(User $agent, string $suffix): User
    {
        $this->counter++;
        $registeredAt = now()->subDays(30);

        $user = User::create([
            'company_id' => null,
            'account_holder_type' => 'private',
            'name' => 'Cliente-' . $suffix . '-' . $this->counter,
            'email' => $this->email('cli-' . $suffix . '-' . $this->counter),
            'password' => 'secret123',
            'role' => 'private-member',
            'is_active' => true,
            'is_super_admin' => false,
        ]);

        $user->forceFill([
            'email_verified_at' => now(),
            'contract_signed_at' => $registeredAt,
            'mlm_role' => 'cliente',
            'mlm_client_agent_id' => $agent->id,
        ])->save();

        if ($this->baseRole) {
            $user->roles()->sync([$this->baseRole->id]);
        }

        $this->makeAccount($user);

        return $user;
    }

    /**
     * Cliente + riga mlm_commission_base_ledger diretta: 1.000,00 € di
     * deposito, margine 30% -> 300,00 € di base Prov K, attiva per un
     * ampio intervallo (2026-01-01..2027-12-31) cosi' qualunque mese lanci
     * `mlm:calculate-commissions` la cattura.
     */
    private function makeClientWithDeposit(User $agent, int $depositEurCents): User
    {
        $client = $this->makeClient($agent, 'dep');

        DB::table('mlm_commission_base_ledger')->insert([
            'uuid' => (string) Str::uuid(),
            'client_user_id' => $client->id,
            'direct_agent_id' => $agent->id,
            'source_transfer_id' => null,
            'monthly_amount_eur_cents' => $depositEurCents,
            'knm_margin_percent' => 30,
            'valid_from' => '2026-01-01',
            'valid_until' => '2027-12-31',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $client;
    }

    private function makeAccount(User $user): Account
    {
        return Account::create([
            'company_id' => null,
            'owner_user_id' => $user->id,
            'owner_type' => 'private',
            'type' => 'primary',
            'account_name' => 'Conto personale ' . $user->name,
            'currency_code' => 'KY',
            'status' => 'active',
            'allow_negative_balance' => false,
            'available_balance' => 0,
            'pending_balance' => 0,
        ]);
    }

    private function email(string $slug): string
    {
        return self::EMAIL_PREFIX . Str::slug($slug) . '@kmoney.test';
    }
}
