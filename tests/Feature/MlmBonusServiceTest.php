<?php

namespace Tests\Feature;

use App\Models\MlmBonusEvent;
use App\Models\MlmBonusPayout;
use App\Models\User;
use App\Services\MlmBonusService;
use App\Services\MlmTreeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Copre la cascata bonus di struttura: sottrazione telescopica lungo la
 * catena upline, regola speciale Key (paga solo dal 3° evento BasiQ nella
 * sua downline) e idempotenza dell'evento.
 *
 * Vedi app/Services/MlmBonusService.php e MLM_PROPOSAL.md §6.
 */
class MlmBonusServiceTest extends TestCase
{
    use RefreshDatabase;

    private MlmBonusService $service;
    private MlmTreeService $tree;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tree = new MlmTreeService();
        $this->service = new MlmBonusService($this->tree);
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

    /** Crea N eventi BasiQ gia' processati nella downline di $keyAgent per renderlo eleggibile al bonus. */
    private function preloadBasiqEvents(User $keyAgent, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $basiq = $this->makeAgent();
            $this->tree->attachAgent($basiq, $keyAgent);
            MlmBonusEvent::create([
                'basiq_user_id'         => $basiq->id,
                'triggered_at'          => now()->subDays(10),
                'status'                => 'processed',
                'processed_at'          => now()->subDays(10),
                'upline_chain_snapshot' => [],
            ]);
        }
    }

    public function test_cascade_telescopes_amounts_across_the_full_chain(): void
    {
        $manager = $this->makeAgent('manager');
        $supervisor = $this->makeAgent('supervisor');
        $top = $this->makeAgent('top');
        $senior = $this->makeAgent('senior');
        $key = $this->makeAgent('key');
        $basiq = $this->makeAgent('basic');

        $this->tree->attachAgent($manager, null);
        $this->tree->attachAgent($supervisor, $manager);
        $this->tree->attachAgent($top, $supervisor);
        $this->tree->attachAgent($senior, $top);
        $this->tree->attachAgent($key, $senior);
        $this->tree->attachAgent($basiq, $key);

        // Il Key deve essere eleggibile: precarica 2 eventi precedenti nella sua downline.
        $this->preloadBasiqEvents($key, 2);

        $event = $this->service->processBasiqEvent($basiq);

        $payouts = MlmBonusPayout::where('mlm_bonus_event_id', $event->id)->get()->keyBy('beneficiary_user_id');

        $this->assertSame(6_000, (int) $payouts[$key->id]->amount_eur_cents);
        $this->assertSame(5_000, (int) $payouts[$senior->id]->amount_eur_cents); // 11.000 - 6.000
        $this->assertSame(4_000, (int) $payouts[$top->id]->amount_eur_cents); // 15.000 - 11.000
        $this->assertSame(3_000, (int) $payouts[$supervisor->id]->amount_eur_cents); // 18.000 - 15.000
        $this->assertSame(2_000, (int) $payouts[$manager->id]->amount_eur_cents); // 20.000 - 18.000

        // La somma dei payout deve essere pari all'importo della qualifica piu' alta presente.
        $this->assertSame(20_000, (int) $payouts->sum('amount_eur_cents'));
    }

    public function test_key_below_the_third_basiq_event_is_skipped_and_senior_absorbs_the_full_tier(): void
    {
        $senior = $this->makeAgent('senior');
        $key = $this->makeAgent('key');
        $basiq = $this->makeAgent('basic');

        $this->tree->attachAgent($senior, null);
        $this->tree->attachAgent($key, $senior);
        $this->tree->attachAgent($basiq, $key);

        // Solo 1 evento precedente: il Key non e' ancora eleggibile (serve il 3°).
        $this->preloadBasiqEvents($key, 1);

        $event = $this->service->processBasiqEvent($basiq);

        $payouts = MlmBonusPayout::where('mlm_bonus_event_id', $event->id)->get()->keyBy('beneficiary_user_id');

        $this->assertArrayNotHasKey($key->id, $payouts->toArray());
        // Il Senior, primo rank bonus-eligibile presente, incassa l'intero importo pieno (non la differenza).
        $this->assertSame(11_000, (int) $payouts[$senior->id]->amount_eur_cents);
    }

    public function test_process_basiq_event_is_idempotent(): void
    {
        $senior = $this->makeAgent('senior');
        $basiq = $this->makeAgent('basic');
        $this->tree->attachAgent($senior, null);
        $this->tree->attachAgent($basiq, $senior);

        $event1 = $this->service->processBasiqEvent($basiq);
        $countAfterFirst = MlmBonusPayout::count();

        $event2 = $this->service->processBasiqEvent($basiq);
        $countAfterSecond = MlmBonusPayout::count();

        $this->assertSame($event1->id, $event2->id);
        $this->assertSame($countAfterFirst, $countAfterSecond);
        $this->assertGreaterThan(0, $countAfterFirst);
    }

    public function test_no_bonus_eligible_ancestor_marks_event_processed_without_payouts(): void
    {
        $plainRoot = $this->makeAgent('start');
        $basiq = $this->makeAgent('basic');
        $this->tree->attachAgent($plainRoot, null);
        $this->tree->attachAgent($basiq, $plainRoot);

        $event = $this->service->processBasiqEvent($basiq);

        $this->assertSame('processed', $event->status);
        $this->assertSame(0, MlmBonusPayout::where('mlm_bonus_event_id', $event->id)->count());
    }
}
