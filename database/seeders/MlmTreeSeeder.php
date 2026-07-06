<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\Role;
use App\Models\User;
use App\Services\MlmTreeService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Popola il DB locale (SQLite dev) con un albero MLM ampio e profondo,
 * con agenti a TUTTI i rank (start..manager) e clienti attribuiti, per
 * poter visionare "Struttura" (portale) e "Albero agenti" (admin) pieni.
 *
 * Solo dati fittizi, isolati tramite prefisso email "mlmseed-" cosi' da
 * poter essere ri-eseguito (pulisce e ricrea) senza toccare altri dati demo.
 *
 * Uso:
 *   php artisan db:seed --class=MlmTreeSeeder
 *
 * NON e' agganciato a DatabaseSeeder: gli account nascono a saldo 0, quindi
 * non rompe l'invariante SUM(available_balance)=0, ma resta volutamente
 * separato per non rallentare/appesantire il seed demo principale.
 */
class MlmTreeSeeder extends Seeder
{
    private const EMAIL_PREFIX = 'mlmseed-';

    /** Rank per profondita' nell'albero (depth 0 = radice = piu' alto). */
    private const RANK_BY_DEPTH = [
        0 => ['manager'],
        1 => ['manager', 'supervisor'],
        2 => ['supervisor', 'top'],
        3 => ['top', 'senior'],
        4 => ['senior', 'key'],
        5 => ['key', 'basic'],
        6 => ['basic', 'start'],
    ];

    /** Profondita' massima dell'albero (garantisce livelli indiretti 1..7+ per il commission engine). */
    private const MAX_DEPTH = 7;

    /** Limiti di sicurezza per tenere il seed veloce su SQLite locale. */
    private const MAX_AGENTS = 220;
    private const MAX_CLIENTS = 400;

    private const FIRST_NAMES = [
        'Marco', 'Giulia', 'Luca', 'Sara', 'Andrea', 'Francesca', 'Matteo', 'Chiara',
        'Davide', 'Elena', 'Simone', 'Valentina', 'Alessandro', 'Federica', 'Stefano',
        'Martina', 'Riccardo', 'Silvia', 'Lorenzo', 'Alice', 'Giacomo', 'Sofia',
        'Nicola', 'Paola', 'Fabio', 'Laura', 'Roberto', 'Ilaria', 'Michele', 'Elisa',
        'Antonio', 'Cristina', 'Giuseppe', 'Roberta', 'Daniele', 'Beatrice', 'Emanuele',
        'Camilla', 'Filippo', 'Noemi',
    ];

    private const LAST_NAMES = [
        'Rossi', 'Ferrari', 'Russo', 'Bianchi', 'Romano', 'Gallo', 'Costa', 'Fontana',
        'Conti', 'Esposito', 'Ricci', 'Bruno', 'Marino', 'Greco', 'Rizzo', 'Lombardi',
        'Moretti', 'Barbieri', 'Villa', 'Testa', 'Colombo', 'Serra', 'Coppola', 'Marchetti',
        'Gentile', 'Longo', 'Caruso', 'Ferraro', 'Sanna', 'Pellegrini',
    ];

    private MlmTreeService $tree;
    private ?Role $baseRole = null;
    private int $counter = 0;
    private int $agentsCreated = 0;
    private int $clientsCreated = 0;

    public function run(): void
    {
        $this->tree = app(MlmTreeService::class);
        $this->baseRole = Role::query()->where('slug', 'private-member')->first();

        $this->cleanPreviousSeed();

        $this->command?->info('Creazione albero MLM ampio e profondo (dati locali di test)...');

        $rootCount = 3;

        DB::transaction(function () use ($rootCount): void {
            for ($i = 0; $i < $rootCount; $i++) {
                if ($this->agentsCreated >= self::MAX_AGENTS) {
                    break;
                }

                $root = $this->makeAgent(0);
                $this->tree->attachAgent($root, null);
                $this->agentsCreated++;

                $this->buildSubtree($root, 1);
            }
        });

        $this->command?->info("  ✓ Albero MLM creato: {$this->agentsCreated} agenti, {$this->clientsCreated} clienti.");
        $this->command?->info('  Vedi: portale → Rete MLM → Struttura, oppure admin → MLM → Albero agenti.');
    }

    // -------------------------------------------------------------------------

    private function cleanPreviousSeed(): void
    {
        $existingIds = User::where('email', 'like', self::EMAIL_PREFIX . '%')->pluck('id');

        if ($existingIds->isEmpty()) {
            return;
        }

        $this->command?->info('  Rimozione seed MLM precedente (' . $existingIds->count() . ' utenti)...');

        Account::whereIn('owner_user_id', $existingIds)->delete();
        // mlm_agent_closure e mlm_point_ledger sono cascadeOnDelete sugli users.
        User::whereIn('id', $existingIds)->delete();
    }

    private function buildSubtree(User $sponsor, int $depth): void
    {
        if ($depth > self::MAX_DEPTH || $this->agentsCreated >= self::MAX_AGENTS) {
            $this->attachClients($sponsor);

            return;
        }

        foreach (range(1, $this->branchFactorForDepth($depth)) as $ignored) {
            if ($this->agentsCreated >= self::MAX_AGENTS) {
                break;
            }

            $agent = $this->makeAgent($depth);
            $this->tree->attachAgent($agent, $sponsor);
            $this->agentsCreated++;

            $this->buildSubtree($agent, $depth + 1);
        }

        $this->attachClients($sponsor);
    }

    /** Fattore di ramificazione per profondita': largo in cima, si assottiglia scendendo. */
    private function branchFactorForDepth(int $depth): int
    {
        return match (true) {
            $depth <= 1 => random_int(3, 4),
            $depth <= 2 => random_int(2, 3),
            $depth <= 3 => random_int(1, 2),
            $depth <= 4 => random_int(1, 2),
            $depth <= 5 => random_int(0, 1),
            default => random_int(0, 1),
        };
    }

    private function attachClients(User $agent): void
    {
        if ($this->clientsCreated >= self::MAX_CLIENTS) {
            return;
        }

        $count = random_int(0, 4);

        for ($i = 0; $i < $count; $i++) {
            if ($this->clientsCreated >= self::MAX_CLIENTS) {
                return;
            }

            $this->makeClient($agent);
            $this->clientsCreated++;
        }
    }

    private function makeAgent(int $depth): User
    {
        $this->counter++;
        $name = $this->randomName();
        $rank = $this->rankForDepth($depth);
        $activatedAt = now()->subDays(random_int(10, 420));

        $user = User::create([
            'company_id'          => null,
            'account_holder_type' => 'private',
            'name'                => $name,
            'email'               => $this->uniqueEmail($name),
            'password'            => 'secret123',
            'role'                => 'private-member',
            'is_active'           => true,
            'is_super_admin'      => false,
        ]);

        $user->forceFill([
            'email_verified_at'            => now(),
            'contract_signed_at'           => $activatedAt,
            'mlm_role'                     => 'agente',
            'mlm_rank'                     => $rank,
            'mlm_rank_updated_at'          => $activatedAt,
            'mlm_activated_at'             => $activatedAt,
            'mlm_agent_request_status'     => 'approved',
            'mlm_agent_requested_at'       => $activatedAt->copy()->subDay(),
            'mlm_agent_reviewed_at'        => $activatedAt,
            'mlm_agent_contract_signed_at' => $activatedAt,
        ])->save();

        if ($this->baseRole) {
            $user->roles()->sync([$this->baseRole->id]);
        }

        $this->makeAccount($user);

        return $user;
    }

    private function makeClient(User $agent): User
    {
        $this->counter++;
        $name = $this->randomName();
        $registeredAt = now()->subDays(random_int(1, 300));

        $user = User::create([
            'company_id'          => null,
            'account_holder_type' => 'private',
            'name'                => $name,
            'email'               => $this->uniqueEmail($name),
            'password'            => 'secret123',
            'role'                => 'private-member',
            'is_active'           => true,
            'is_super_admin'      => false,
        ]);

        $user->forceFill([
            'email_verified_at'   => now(),
            'contract_signed_at'  => $registeredAt,
            'mlm_client_agent_id' => $agent->id,
        ])->save();

        if ($this->baseRole) {
            $user->roles()->sync([$this->baseRole->id]);
        }

        $this->makeAccount($user);

        DB::table('mlm_point_ledger')->insert([
            'uuid'                => (string) Str::uuid(),
            'agent_user_id'       => $agent->id,
            'client_user_id'      => $user->id,
            'source_type'         => 'registration',
            'source_transfer_id'  => null,
            'points'              => random_int(5, 25),
            'valid_from'          => $registeredAt->toDateString(),
            'valid_until'         => now()->addDays(random_int(30, 180))->toDateString(),
            'created_at'          => $registeredAt,
            'updated_at'          => $registeredAt,
        ]);

        return $user;
    }

    private function makeAccount(User $user): Account
    {
        return Account::create([
            'company_id'             => null,
            'owner_user_id'          => $user->id,
            'owner_type'             => 'private',
            'type'                   => 'primary',
            'account_name'           => 'Conto personale ' . $user->name,
            'currency_code'          => 'KY',
            'status'                 => 'active',
            'allow_negative_balance' => false,
            'available_balance'      => 0,
            'pending_balance'        => 0,
        ]);
    }

    private function rankForDepth(int $depth): string
    {
        $pool = self::RANK_BY_DEPTH[$depth] ?? ['start'];

        return $pool[array_rand($pool)];
    }

    private function randomName(): string
    {
        $first = self::FIRST_NAMES[array_rand(self::FIRST_NAMES)];
        $last = self::LAST_NAMES[array_rand(self::LAST_NAMES)];

        return "{$first} {$last}";
    }

    private function uniqueEmail(string $name): string
    {
        return self::EMAIL_PREFIX . $this->counter . '-' . Str::slug($name) . '@kmoney.test';
    }
}
