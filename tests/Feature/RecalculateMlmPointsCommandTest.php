<?php

namespace Tests\Feature;

use App\Models\MlmMetricGrant;
use App\Models\MlmPointLedgerEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Copre il rilevamento BasiQ del comando `mlm:recalculate-points` (PASSATA
 * 1): un agente diventa BasiQ a 12 punti attivi entro 30 giorni
 * dall'attivazione. Dal 2026-07-30 (decisione di Laura) questa soglia usa
 * SOLO punti REALI (User::mlmRealActivePoints()) — a differenza della
 * qualifica (PASSATA 2) e dei Bonus Diretti KNM, che restano su
 * mlmActivePoints() (reali + omaggio, invariato). Vedi
 * app/Console/Commands/RecalculateMlmPoints.php.
 */
class RecalculateMlmPointsCommandTest extends TestCase
{
    use RefreshDatabase;

    private function makeAgent(): User
    {
        return User::create([
            'name'                => 'Agente ' . Str::random(6),
            'email'                => 'agente-' . Str::random(10) . '@test.test',
            'password'             => 'secret123',
            'account_holder_type'  => 'private',
            'company_id'           => null,
            'is_active'            => true,
            'mlm_role'             => 'agente',
            'mlm_rank'             => 'start',
            'mlm_activated_at'     => now(),
        ]);
    }

    private function givePoints(User $agent, int $points): void
    {
        $client = User::create([
            'name'                => 'Cliente ' . Str::random(6),
            'email'                => 'cliente-' . Str::random(10) . '@test.test',
            'password'             => 'secret123',
            'account_holder_type'  => 'private',
            'company_id'           => null,
            'is_active'            => true,
            'mlm_role'             => 'cliente',
            'mlm_client_agent_id'  => $agent->id,
        ]);

        MlmPointLedgerEntry::create([
            'agent_user_id'  => $agent->id,
            'client_user_id' => $client->id,
            'source_type'    => 'registration',
            'points'         => $points,
            'valid_from'     => now()->subDay()->toDateString(),
            'valid_until'    => now()->addMonth()->toDateString(),
        ]);
    }

    public function test_real_points_trigger_basiq(): void
    {
        $agent = $this->makeAgent();
        $this->givePoints($agent, 12);

        $this->artisan('mlm:recalculate-points')->assertSuccessful();

        $this->assertNotNull($agent->fresh()->mlm_basiq_at);
    }

    /**
     * Decisione di Laura del 2026-07-30: un admin puo' regalare punti per
     * far scattare la qualifica e i Bonus Diretti, ma NON puo' innescare
     * la cascata bonus di struttura sull'upline (che parte da BasiQ) senza
     * attivita' reale del cliente.
     */
    public function test_gifted_points_alone_do_not_trigger_basiq(): void
    {
        $agent = $this->makeAgent();

        MlmMetricGrant::create([
            'agent_user_id' => $agent->id,
            'metric'        => 'points',
            'amount'        => 12,
        ]);

        // La qualifica (reali + omaggio) vedrebbe gia' 12 punti...
        $this->assertSame(12, $agent->mlmActivePoints());
        // ...ma i punti REALI sono 0: BasiQ non deve scattare.
        $this->assertSame(0, $agent->mlmRealActivePoints());

        $this->artisan('mlm:recalculate-points')->assertSuccessful();

        $this->assertNull($agent->fresh()->mlm_basiq_at);
    }

    public function test_real_and_gifted_points_combined_trigger_basiq_when_real_alone_reaches_the_threshold(): void
    {
        $agent = $this->makeAgent();
        $this->givePoints($agent, 12);

        MlmMetricGrant::create([
            'agent_user_id' => $agent->id,
            'metric'        => 'points',
            'amount'        => 5,
        ]);

        $this->artisan('mlm:recalculate-points')->assertSuccessful();

        $this->assertNotNull($agent->fresh()->mlm_basiq_at);
    }

    public function test_partial_real_points_below_threshold_do_not_trigger_basiq_even_with_gifted_points(): void
    {
        $agent = $this->makeAgent();
        $this->givePoints($agent, 11); // sotto la soglia di 1 punto reale

        MlmMetricGrant::create([
            'agent_user_id' => $agent->id,
            'metric'        => 'points',
            'amount'        => 5, // porterebbe il totale a 16, ma non conta
        ]);

        $this->artisan('mlm:recalculate-points')->assertSuccessful();

        $this->assertNull($agent->fresh()->mlm_basiq_at);
    }
}
