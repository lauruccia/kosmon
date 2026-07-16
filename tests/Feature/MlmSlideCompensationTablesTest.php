<?php

namespace Tests\Feature;

use App\Models\MlmCommission;
use App\Models\MlmCommissionBaseLedgerEntry;
use App\Models\User;
use App\Services\MlmCommissionEngine;
use App\Services\MlmTreeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Riproduce AL CENTESIMO le 4 tabelle "Esempio compensi" delle slide KNM
 * (2026-07-16, "le slide fanno fede" — Laura). Formula delle slide:
 *
 *   MontImp(livello) = n° agenti al livello x clienti ciascuno x importo mensile
 *   Prov K           = MontImp x margine KNM   (il "30 %" / "10 %" in testa alla tabella)
 *   Comp(livello)    = Prov K x % livello      (L1 4%, L2 2%, L3 1%, L4 0,5%, L5 8%, L6+ 0,5%)
 *
 * Le colonne "Comp" delle slide sono in EUR arrotondati; qui asseriamo i
 * CENTESIMI esatti (es. slide "29" = 28,80 EUR = 2880 centesimi) e, per la
 * prima tabella, anche il cumulato dei livelli 1-5 (slide "1037" = 1036,80).
 *
 * Ogni livello e' rappresentato da UN agente con UN cliente il cui importo
 * mensile e' il MontImp aggregato del livello: il motore e' lineare nella
 * base, quindi il totale per livello e' identico a quello di N agenti
 * separati (la struttura ad albero e i singoli agenti sono coperti da
 * MlmCommissionEngineTest).
 */
class MlmSlideCompensationTablesTest extends TestCase
{
    use RefreshDatabase;

    private MlmCommissionEngine $engine;
    private MlmTreeService $tree;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tree = new MlmTreeService();
        $this->engine = new MlmCommissionEngine($this->tree);
    }

    private function makeAgent(string $rank = 'start'): User
    {
        return User::create([
            'name'                => 'Agente ' . Str::random(6),
            'email'               => 'agente-' . Str::random(10) . '@test.test',
            'password'            => 'secret123',
            'account_holder_type' => 'private',
            'company_id'          => null,
            'is_active'           => true,
            'mlm_role'            => 'agente',
            'mlm_rank'            => $rank,
            'mlm_activated_at'    => now(),
        ]);
    }

    private function makeClient(User $agent): User
    {
        return User::create([
            'name'                => 'Cliente ' . Str::random(6),
            'email'               => 'cliente-' . Str::random(10) . '@test.test',
            'password'            => 'secret123',
            'account_holder_type' => 'private',
            'company_id'          => null,
            'is_active'           => true,
            'mlm_role'            => 'cliente',
            'mlm_client_agent_id' => $agent->id,
        ]);
    }

    private function givePoints(User $agent, int $points): void
    {
        \App\Models\MlmPointLedgerEntry::create([
            'agent_user_id'  => $agent->id,
            'client_user_id' => $this->makeClient($agent)->id,
            'source_type'    => 'registration',
            'points'         => $points,
            'valid_from'     => now()->startOfMonth()->subDay()->toDateString(),
            'valid_until'    => now()->addMonth()->toDateString(),
        ]);
    }

    /**
     * Le 4 tabelle "Esempio compensi" delle slide:
     * [n° invitati per agente, importo mensile cliente (cent), margine %, livelli, Comp attesi per livello (cent)]
     * MontImp(L) = ins^L x 24 clienti x impM. Comp attesi = valori delle
     * slide senza arrotondamento all'euro (slide "29" = 2880 cent).
     */
    public static function slideTables(): array
    {
        return [
            'Ins=2, Cli=24, ImpM=50 EUR, margine 30% (fino al 10° livello)' => [
                2, 5_000, 30,
                [1 => 2_880, 2 => 2_880, 3 => 2_880, 4 => 2_880, 5 => 92_160,
                 6 => 11_520, 7 => 23_040, 8 => 46_080, 9 => 92_160, 10 => 184_320],
            ],
            'Ins=2, Cli=24, ImpM=100 EUR, margine 30% (livelli 1-5)' => [
                2, 10_000, 30,
                [1 => 5_760, 2 => 5_760, 3 => 5_760, 4 => 5_760, 5 => 184_320],
            ],
            'Ins=3, Cli=24, ImpM=50 EUR, margine 30% (livelli 1-5)' => [
                3, 5_000, 30,
                [1 => 4_320, 2 => 6_480, 3 => 9_720, 4 => 14_580, 5 => 699_840],
            ],
            'Ins=5, Cli=24, ImpM=30 EUR, margine 10% (livelli 1-5)' => [
                5, 3_000, 10,
                [1 => 1_440, 2 => 3_600, 3 => 9_000, 4 => 22_500, 5 => 1_800_000],
            ],
        ];
    }

    #[DataProvider('slideTables')]
    public function test_slide_compensation_table_is_reproduced_to_the_cent(
        int $invitedPerAgent,
        int $monthlyAmountEurCents,
        int $marginPercent,
        array $expectedByLevel,
    ): void {
        $maxLevel = max(array_keys($expectedByLevel));

        // Il beneficiario in cima alla tabella: 48 punti + 3 Basic al 1°
        // livello soddisfano il gating dei livelli 1-5; per le righe oltre
        // il 5° livello (prima tabella) serve un grado Top+.
        $root = $this->makeAgent($maxLevel > 5 ? 'top' : 'start');
        $this->tree->attachAgent($root, null);
        $this->givePoints($root, 48);
        for ($i = 0; $i < 3; $i++) {
            $basic = $this->makeAgent('basic');
            $this->tree->attachAgent($basic, $root);
        }

        // Un agente per livello, in catena, con UN cliente che porta il
        // MontImp aggregato del livello e lo snapshot del margine della
        // tabella (la colonna "30 %" / "10 %").
        $sponsor = $root;
        $levelAgents = [];
        foreach (range(1, $maxLevel) as $level) {
            $agent = $this->makeAgent();
            $this->tree->attachAgent($agent, $sponsor);

            $montImp = (int) (pow($invitedPerAgent, $level) * 24 * $monthlyAmountEurCents);
            MlmCommissionBaseLedgerEntry::create([
                'client_user_id'           => $this->makeClient($agent)->id,
                'direct_agent_id'          => $agent->id,
                'monthly_amount_eur_cents' => $montImp,
                'knm_margin_percent'       => $marginPercent,
                'valid_from'               => now()->startOfMonth()->toDateString(),
                'valid_until'              => now()->addMonths(11)->toDateString(),
            ]);

            $levelAgents[$level] = $agent;
            $sponsor = $agent;
        }

        $this->engine->runForMonth(now());

        foreach ($expectedByLevel as $level => $expectedCents) {
            $commission = MlmCommission::where('agent_user_id', $root->id)
                ->where('type', 'indiretta')
                ->where('source_agent_id', $levelAgents[$level]->id)
                ->first();

            $this->assertNotNull($commission, "Manca la commissione del livello {$level}.");
            $this->assertSame(
                $expectedCents,
                $commission->amount_eur_cents,
                "Livello {$level}: la slide vale " . number_format($expectedCents / 100, 2, ',', '.') . " EUR."
            );
        }

        // Cumulato livelli 1-5 ("Mont %" della riga evidenziata in rosso):
        // prima tabella slide = 1037 EUR arrotondati = 1036,80 esatti.
        $levels1to5 = array_sum(array_intersect_key($expectedByLevel, array_flip([1, 2, 3, 4, 5])));
        $actual1to5 = (int) MlmCommission::where('agent_user_id', $root->id)
            ->where('type', 'indiretta')
            ->where('level', '<=', 5)
            ->sum('amount_eur_cents');
        $this->assertSame($levels1to5, $actual1to5, 'Il cumulato dei livelli 1-5 deve tornare col "Mont %" della slide.');
    }

    public function test_direct_commission_follows_up_to_40_percent_of_the_knm_compensation(): void
    {
        // Slide "Reddito residuale": "Fino al 40% del compenso KNM sulle
        // vendite dirette" + esempio "24 clienti personali". Con 200 punti
        // attivi (fascia 40%), 24 clienti da 50 EUR/mese e margine 30%:
        // MontImp 1.200 EUR -> Prov K 360 EUR -> 40% = 144 EUR.
        $agent = $this->makeAgent();
        $this->tree->attachAgent($agent, null);
        $this->givePoints($agent, 200);

        for ($i = 0; $i < 24; $i++) {
            MlmCommissionBaseLedgerEntry::create([
                'client_user_id'           => $this->makeClient($agent)->id,
                'direct_agent_id'          => $agent->id,
                'monthly_amount_eur_cents' => 5_000,
                'knm_margin_percent'       => 30,
                'valid_from'               => now()->startOfMonth()->toDateString(),
                'valid_until'              => now()->addMonths(11)->toDateString(),
            ]);
        }

        $this->engine->runForMonth(now());

        $total = (int) MlmCommission::where('agent_user_id', $agent->id)
            ->where('type', 'diretta')
            ->sum('amount_eur_cents');

        $this->assertSame(14_400, $total, '40% del compenso KNM (360 EUR) = 144 EUR.');
    }
}
