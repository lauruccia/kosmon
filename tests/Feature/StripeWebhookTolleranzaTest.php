<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\KyCard;
use App\Models\KyCardPurchase;
use App\Models\Plan;
use App\Models\PlanPayment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * IL WEBHOOK STRIPE, TUTTI E QUATTRO GLI INCASSI (01/09/2026).
 *
 * L'endpoint `/stripe/webhook` e' uno solo per tutto il circuito: ricarica
 * KYCard, upgrade di piano, quota di iscrizione dei privati e quota per il
 * codice agente. Fino a stamattina tre rami su quattro guardavano
 * `isPending()`: una riga finita `failed` — accredito andato storto, o
 * tentativo dato per abbandonato — veniva saltata in silenzio, e chi aveva
 * pagato restava senza niente per sempre. Nessun errore, nessun log: il
 * webhook rispondeva 200 e non faceva nulla.
 *
 * Ora la condizione e' "non e' ne' chiusa ne' disfatta", e la prova
 * dell'incasso resta dove era: StripeCheckoutVerifier, che chiede a Stripe se
 * quella sessione e' stata davvero pagata, dell'importo esatto e per QUEL
 * pagamento.
 *
 * Questi test dimostrano le DUE meta' della stessa regola, per ogni ramo:
 * con la prova si accredita anche partendo da `failed`; senza la prova non si
 * accredita niente, e su una riga gia' disfatta (`refunded`, `cancelled`) non
 * si torna sopra nemmeno con la prova.
 *
 * La quota codice agente ha i suoi tre test in AgentCodeFeeTest, e quella dei
 * privati era gia' coperta.
 */
class StripeWebhookTolleranzaTest extends TestCase
{
    use RefreshDatabase;

    // ─── Ricarica KYCard ────────────────────────────────────────────────────

    public function test_il_webhook_ripesca_una_ricarica_kycard_finita_failed(): void
    {
        $this->fingiStripe(true);
        [$user, $account] = $this->makeAzienda();
        $acquisto = $this->makeAcquisto($user, $account, 'failed');

        $saldoPrima = (int) $account->fresh()->available_balance;

        $this->postWebhookStripe($acquisto->stripe_checkout_session_id)->assertOk();

        $this->assertTrue($acquisto->fresh()->isCompleted());
        $this->assertSame($saldoPrima + (int) $acquisto->ky_amount, (int) $account->fresh()->available_balance);
    }

    public function test_senza_la_prova_di_stripe_la_ricarica_non_viene_accreditata(): void
    {
        $this->fingiStripe(false);
        [$user, $account] = $this->makeAzienda();
        $acquisto = $this->makeAcquisto($user, $account, 'failed');

        $saldoPrima = (int) $account->fresh()->available_balance;

        $this->postWebhookStripe($acquisto->stripe_checkout_session_id)->assertOk();

        $this->assertFalse($acquisto->fresh()->isCompleted());
        $this->assertSame($saldoPrima, (int) $account->fresh()->available_balance);
    }

    /**
     * Una ricarica rimborsata e' una risposta gia' data, al contrario: i KY
     * sono stati tolti apposta. Riaccreditarli su un webhook in ritardo
     * sarebbe moneta creata dal nulla.
     */
    public function test_una_ricarica_rimborsata_non_si_riaccredita(): void
    {
        $this->fingiStripe(true);
        [$user, $account] = $this->makeAzienda();
        $acquisto = $this->makeAcquisto($user, $account, 'refunded');

        $saldoPrima = (int) $account->fresh()->available_balance;

        $this->postWebhookStripe($acquisto->stripe_checkout_session_id)->assertOk();

        $this->assertSame('refunded', $acquisto->fresh()->status);
        $this->assertSame($saldoPrima, (int) $account->fresh()->available_balance);
    }

    // ─── Upgrade di piano ───────────────────────────────────────────────────

    public function test_il_webhook_ripesca_un_upgrade_di_piano_finito_failed(): void
    {
        $this->fingiStripe(true);
        [$user, $account] = $this->makeAzienda();
        [$pagamento, $pianoNuovo] = $this->makeUpgrade($user, 'failed');

        $this->postWebhookStripe($pagamento->stripe_checkout_session_id)->assertOk();

        $this->assertTrue($pagamento->fresh()->isCompleted());
        // Il piano dell'azienda e' passato davvero: era il senso del pagamento.
        $this->assertSame($pianoNuovo->id, (int) $user->fresh()->company->plan_id);
    }

    public function test_senza_la_prova_di_stripe_il_piano_non_cambia(): void
    {
        $this->fingiStripe(false);
        [$user, $account] = $this->makeAzienda();
        [$pagamento, $pianoNuovo] = $this->makeUpgrade($user, 'failed');

        $pianoPrima = (int) $user->fresh()->company->plan_id;

        $this->postWebhookStripe($pagamento->stripe_checkout_session_id)->assertOk();

        $this->assertFalse($pagamento->fresh()->isCompleted());
        $this->assertSame($pianoPrima, (int) $user->fresh()->company->plan_id);
    }

    // ─── Aiutanti ───────────────────────────────────────────────────────────

    private function fingiStripe(bool $pagata): void
    {
        $this->instance(
            \App\Services\StripeCheckoutVerifier::class,
            new class($pagata) extends \App\Services\StripeCheckoutVerifier {
                public function __construct(private readonly bool $pagata) {}

                public function isPaidFor(?string $storedSessionId, int $expectedAmountCents, string $expectedReference, string $context = 'stripe'): bool
                {
                    return $this->pagata;
                }

                public function sessionMatches(object $session, int $expectedAmountCents, string $expectedReference, string $context = 'stripe'): bool
                {
                    return $this->pagata;
                }
            }
        );
    }

    /** @return array{0: User, 1: Account} */
    private function makeAzienda(): array
    {
        $slug = 'webhook-' . Str::random(6);

        $company = Company::create([
            'name'          => 'Webhook Co',
            'slug'          => $slug,
            'email'         => $slug . '@test.test',
            'status'        => 'active',
            'kyc_status'    => 'approved',
            'currency_code' => 'KY',
            'sector'        => 'informatica',
            'description'   => 'Test',
        ]);

        $user = User::create([
            'company_id'          => $company->id,
            'account_holder_type' => 'company',
            'name'                => 'Titolare Webhook',
            'email'               => 'wh-' . Str::random(8) . '@test.test',
            'password'            => 'secret123',
            'role'                => 'company-manager',
            'is_active'           => true,
            'is_super_admin'      => false,
        ]);
        $user->forceFill(['email_verified_at' => now(), 'contract_signed_at' => now()])->save();

        $account = Account::create([
            'company_id'        => $company->id,
            'owner_user_id'     => $user->id,
            'owner_type'        => 'company',
            'type'              => 'primary',
            'account_name'      => 'Conto Webhook',
            'currency_code'     => 'KY',
            'status'            => 'active',
            'available_balance' => 0,
        ]);

        $sistema = Account::systemAccount();
        $this->assertNotNull($sistema, 'Conto sistema non creato dalle migration.');
        $sistema->forceFill(['available_balance' => 10_000_000])->save();

        return [$user->fresh(), $account->fresh()];
    }

    private function makeAcquisto(User $user, Account $account, string $stato): KyCardPurchase
    {
        $card = KyCard::create([
            'name'            => 'Ricarica 120',
            'ky_base_amount'  => 12000,
            'price_eur_cents' => 12000,
            'bonus_type'      => 'percentage',
            'bonus_value'     => 25,
            'is_active'       => true,
        ]);

        return KyCardPurchase::create([
            'ky_card_id'                 => $card->id,
            'account_id'                 => $account->id,
            'user_id'                    => $user->id,
            'price_eur_cents'            => $card->price_eur_cents,
            'ky_amount'                  => $card->ky_total,
            'status'                     => $stato,
            'payment_method'             => 'stripe',
            'stripe_checkout_session_id' => 'cs_test_' . Str::random(16),
        ]);
    }

    /** @return array{0: PlanPayment, 1: Plan} */
    private function makeUpgrade(User $user, string $stato): array
    {
        $daPiano = Plan::create([
            'name' => 'Base ' . Str::random(4), 'slug' => 'base-' . Str::random(6),
            'monthly_price_cents' => 1000, 'is_active' => true,
        ]);
        $aPiano = Plan::create([
            'name' => 'Pro ' . Str::random(4), 'slug' => 'pro-' . Str::random(6),
            'monthly_price_cents' => 5000, 'is_active' => true,
        ]);

        $user->company->forceFill(['plan_id' => $daPiano->id])->save();

        $pagamento = PlanPayment::create([
            'company_id'                 => $user->company_id,
            'user_id'                    => $user->id,
            'from_plan_id'               => $daPiano->id,
            'to_plan_id'                 => $aPiano->id,
            'amount_cents'               => 4000,
            'status'                     => $stato,
            'payment_method'             => 'stripe',
            'stripe_checkout_session_id' => 'cs_test_' . Str::random(16),
        ]);

        return [$pagamento, $aPiano];
    }

    private function postWebhookStripe(string $sessionId): \Illuminate\Testing\TestResponse
    {
        config([
            'services.stripe.secret'         => 'sk_test_finta',
            'services.stripe.webhook_secret' => 'whsec_test_finta',
        ]);

        $payload = json_encode([
            'id'   => 'evt_' . Str::random(12),
            'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'id'             => $sessionId,
                'object'         => 'checkout.session',
                'payment_intent' => 'pi_' . Str::random(12),
                'amount_total'   => 12000,
            ]],
        ], JSON_THROW_ON_ERROR);

        $t     = time();
        $firma = hash_hmac('sha256', $t . '.' . $payload, 'whsec_test_finta');

        return $this->call(
            'POST',
            '/stripe/webhook',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => 't=' . $t . ',v1=' . $firma],
            $payload
        );
    }
}
