<?php

namespace Tests\Feature;

use App\Models\MlmBonusPayout;
use App\Models\MlmPendingRankAward;
use App\Models\MlmPointLedgerEntry;
use App\Models\User;
use App\Notifications\MlmRankDemotedNotification;
use App\Services\MlmAwardService;
use App\Services\MlmRankEngine;
use App\Services\MlmTreeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Copre i premi una tantum introdotti il 2026-07-13:
 * - Bonus Diretti KNM: 200/300/400 EUR a 4/6/12 punti ATTIVI, una volta
 *   per soglia per agente (anche se i punti scadono e vengono ri-raggiunti).
 * - Extra Bonus KNM: premio alla prima promozione a senior/top/spv/mng
 *   (300/3.000/5.000/20.000 EUR), non retroattivo, mai due volte per grado.
 * - Notifica MlmRankDemotedNotification alla retrocessione.
 *
 * Vedi app/Services/MlmAwardService.php e [[mlm_qualifiche_retrocessione]].
 */
class MlmAwardServiceTest extends TestCase
{
    use RefreshDatabase;

    private MlmAwardService $awards;
    private MlmTreeService $tree;
    private MlmRankEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->awards = new MlmAwardService();
        $this->tree = new MlmTreeService();
        $this->engine = new MlmRankEngine($this->tree, $this->awards);
    }

    private function makeAgent(string $rank = 'start'): User
    {
        return User::create([
            'name'                => 'Agente ' . Str::random(6),
            'email'                => 'agente-' . Str::random(10) . '@test.test',
            'password'             => 'secret123',
            'account_holder_type'  => 'private',
            'company_id'           => null,
            'is_active'            => true,
            'mlm_role'             => 'agente',
            'mlm_rank'             => $rank,
            'mlm_activated_at'     => now(),
        ]);
    }

    private function givePoints(User $agent, int $points, bool $expired = false): void
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
            'valid_from'     => now()->subMonths(2)->toDateString(),
            'valid_until'    => $expired ? now()->subDay()->toDateString() : now()->addMonth()->toDateString(),
        ]);
    }

    public function test_direct_bonuses_are_cumulative_across_thresholds(): void
    {
        $agent = $this->makeAgent();
        $this->givePoints($agent, 12);

        $granted = $this->awards->grantDirectPointBonuses($agent);

        $this->assertSame(3, $granted, 'A 12 punti attivi scattano tutte e tre le soglie (4, 6, 12).');

        $amounts = MlmBonusPayout::where('beneficiary_user_id', $agent->id)
            ->where('kind', 'diretto')
            ->orderBy('amount_eur_cents')
            ->pluck('amount_eur_cents')
            ->all();

        $this->assertSame([20_000, 30_000, 40_000], $amounts);
    }

    public function test_direct_bonuses_pay_only_reached_thresholds(): void
    {
        $agent = $this->makeAgent();
        $this->givePoints($agent, 5); // >= 4, < 6

        $granted = $this->awards->grantDirectPointBonuses($agent);

        $this->assertSame(1, $granted);
        $this->assertSame(
            20_000,
            (int) MlmBonusPayout::where('beneficiary_user_id', $agent->id)->sum('amount_eur_cents')
        );
    }

    public function test_direct_bonuses_never_pay_the_same_threshold_twice(): void
    {
        $agent = $this->makeAgent();
        $this->givePoints($agent, 6);

        $this->assertSame(2, $this->awards->grantDirectPointBonuses($agent));
        $this->assertSame(0, $this->awards->grantDirectPointBonuses($agent), 'Seconda valutazione: nessun doppio pagamento.');

        $this->assertSame(2, MlmBonusPayout::where('beneficiary_user_id', $agent->id)->count());
    }

    public function test_direct_bonuses_require_active_points(): void
    {
        $agent = $this->makeAgent();
        $this->givePoints($agent, 12, expired: true); // punti gia' scaduti

        $this->assertSame(0, $this->awards->grantDirectPointBonuses($agent));
        $this->assertSame(0, MlmBonusPayout::where('beneficiary_user_id', $agent->id)->count());
    }

    public function test_direct_bonus_payouts_land_on_a_wednesday_in_the_standard_flow(): void
    {
        $agent = $this->makeAgent();
        $this->givePoints($agent, 4);
        $this->awards->grantDirectPointBonuses($agent);

        $payout = MlmBonusPayout::where('beneficiary_user_id', $agent->id)->first();

        $this->assertTrue($payout->week_ending->isWednesday(), 'Accredito settimanale il mercoledi\', come i bonus di struttura.');
        $this->assertNull($payout->mlm_bonus_event_id);
        $this->assertSame('pending', $payout->status);
    }

    public function test_rank_promotion_queues_the_award_without_paying_it_immediately(): void
    {
        // Agente che soddisfa i requisiti Senior: 48 pt + 3 Basic + 2 Key su 2 colonne.
        $agent = $this->makeAgent('basic');
        $this->tree->attachAgent($agent, null);
        $this->givePoints($agent, 48);

        foreach (['key', 'key', 'basic'] as $childRank) {
            $child = $this->makeAgent($childRank);
            $this->tree->attachAgent($child, $agent);
        }

        $this->assertSame('promoted', $this->engine->syncRank($agent));
        $this->assertSame('senior', $agent->fresh()->mlm_rank);

        // Dal 2026-07-15: la promozione ACCODA il premio (mlm_pending_rank_awards),
        // ma non lo paga subito — l'erogazione avviene nel job settimanale.
        $this->assertSame(
            0,
            MlmBonusPayout::where('beneficiary_user_id', $agent->id)->where('kind', 'extra')->count(),
            'Nessun Extra Bonus deve essere pagato subito alla promozione: e\' accodato per il job settimanale.'
        );
        $pending = MlmPendingRankAward::where('user_id', $agent->id)->first();
        $this->assertNotNull($pending, 'La promozione deve accodare un premio in attesa.');
        $this->assertSame('senior', $pending->rank);
        $this->assertNull($pending->processed_at);
    }

    public function test_processing_pending_rank_awards_pays_the_queued_extra_bonus(): void
    {
        $agent = $this->makeAgent('basic');
        $this->tree->attachAgent($agent, null);
        $this->givePoints($agent, 48);

        foreach (['key', 'key', 'basic'] as $childRank) {
            $child = $this->makeAgent($childRank);
            $this->tree->attachAgent($child, $agent);
        }

        $this->assertSame('promoted', $this->engine->syncRank($agent));

        $granted = $this->awards->processPendingRankAwards();
        $this->assertSame(1, $granted);

        $award = MlmBonusPayout::where('beneficiary_user_id', $agent->id)->where('kind', 'extra')->first();
        $this->assertNotNull($award, 'Extra Bonus atteso dopo l\'elaborazione settimanale.');
        $this->assertSame(30_000, $award->amount_eur_cents);
        $this->assertSame('senior', $award->rank_at_time);

        $this->assertNotNull($pending = MlmPendingRankAward::where('user_id', $agent->id)->first());
        $this->assertNotNull($pending->processed_at, 'La riga in coda deve risultare processata.');

        // Idempotente: rieseguire non paga una seconda volta.
        $this->assertSame(0, $this->awards->processPendingRankAwards());
        $this->assertSame(1, MlmBonusPayout::where('beneficiary_user_id', $agent->id)->where('kind', 'extra')->count());
    }

    public function test_queue_rank_award_ignores_ranks_without_a_prize(): void
    {
        $agent = $this->makeAgent();

        $this->awards->queueRankAward($agent, 'basic');
        $this->awards->queueRankAward($agent, 'key');

        $this->assertSame(0, MlmPendingRankAward::count());
    }

    public function test_rank_award_is_not_repeated_after_demotion_and_repromotion(): void
    {
        $agent = $this->makeAgent();
        $this->givePoints($agent, 12);

        // Simula un premio senior gia' ricevuto in passato.
        $this->awards->grantRankAward($agent, 'senior');
        $this->assertFalse($this->awards->grantRankAward($agent, 'senior'), 'Mai due volte per lo stesso grado.');

        $this->assertSame(1, MlmBonusPayout::where('beneficiary_user_id', $agent->id)->where('kind', 'extra')->count());
    }

    public function test_queue_rank_award_does_not_requeue_an_already_processed_rank(): void
    {
        $agent = $this->makeAgent();

        // Il premio e' gia' stato erogato in passato per questo grado (fuori
        // dal normale flusso di coda, es. dati storici pre-2026-07-15).
        $this->awards->grantRankAward($agent, 'senior');

        $this->awards->queueRankAward($agent, 'senior');
        $this->assertSame(0, MlmPendingRankAward::where('user_id', $agent->id)->count(), 'Non deve mai rimettersi in coda un grado gia\' premiato.');

        $this->assertSame(0, $this->awards->processPendingRankAwards());
        $this->assertSame(1, MlmBonusPayout::where('beneficiary_user_id', $agent->id)->where('kind', 'extra')->count(), 'Nessun doppio pagamento.');
    }

    public function test_no_rank_award_for_basic_or_key(): void
    {
        $agent = $this->makeAgent();

        $this->assertFalse($this->awards->grantRankAward($agent, 'basic'));
        $this->assertFalse($this->awards->grantRankAward($agent, 'key'));
        $this->assertSame(0, MlmBonusPayout::count());
    }

    public function test_no_award_without_promotion_event(): void
    {
        // NON retroattivo: chi e' GIA' senior e resta senior non riceve nulla
        // dal ricalcolo (syncRank non registra alcuna promozione).
        $agent = $this->makeAgent('senior');
        $this->tree->attachAgent($agent, null);
        $this->givePoints($agent, 48);

        foreach (['key', 'key', 'basic'] as $childRank) {
            $child = $this->makeAgent($childRank);
            $this->tree->attachAgent($child, $agent);
        }

        $this->assertNull($this->engine->syncRank($agent), 'Grado gia' . "'" . ' corretto: nessun evento.');
        $this->assertSame(0, MlmBonusPayout::where('kind', 'extra')->count());
    }

    public function test_demotion_sends_notification_to_the_agent(): void
    {
        Notification::fake();

        $agent = $this->makeAgent('basic');
        $this->givePoints($agent, 12, expired: true);

        $this->assertSame('demoted', $this->engine->syncRank($agent));

        Notification::assertSentTo($agent, MlmRankDemotedNotification::class);
    }

    public function test_promotion_does_not_send_demotion_notification(): void
    {
        Notification::fake();

        $agent = $this->makeAgent();
        $this->givePoints($agent, 12);

        $this->assertSame('promoted', $this->engine->syncRank($agent));

        Notification::assertNotSentTo($agent, MlmRankDemotedNotification::class);
    }
}
