<?php

namespace Tests\Feature;

use App\Jobs\SendClientWebhookJob;
use App\Jobs\SendWebhookJob;
use App\Models\Account;
use App\Models\Company;
use App\Models\Listing;
use App\Models\User;
use App\Models\Webhook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\ClientWebhookDelivery;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `company.trading_status_changed` — §3.2 di PIANO_SHOP_ESTERNO.md.
 *
 * È l'aggancio che non si può semplicemente tagliare. Oggi la banca, quando
 * un'azienda va in debito, entra dentro la tabella dei prodotti e le forza il
 * mix al 100% KY. Quando il catalogo sarà in un'altra applicazione quella
 * scrittura non si potrà più fare, e senza questo evento **le aziende in debito
 * venderebbero al mix sbagliato**: incasserebbero euro che le regole del
 * circuito non permettono.
 *
 * Quindi qui si sorvegliano due cose che devono valere insieme:
 *
 *  1. **l'evento parte quando lo stato cambia davvero, e solo allora.** Un
 *     canale che grida a ogni movimento è un canale che nessuno ascolta — e
 *     sarebbe un webhook per ogni pagamento di ogni azienda del circuito.
 *  2. **il catalogo interno continua a essere riallineato come prima.** Finché
 *     lo shop interno è vivo le due cose convivono; la 2b non deve rompere il
 *     comportamento del 13/08 mentre prepara quello di dopo.
 */
class TradingStatusWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const CLIENT_ID = 'kshop-test-client';

    protected function setUp(): void
    {
        parent::setUp();

        // In test la coda è `sync`: senza questo, i job partirebbero davvero e
        // proverebbero a bussare a un dominio che non esiste.
        Http::fake();

        config()->set('oauth.clients.kshop', [
            'name'          => 'Kosmoshop',
            'client_id'     => self::CLIENT_ID,
            'secret'        => 'segreto-di-prova-molto-lungo',
            'redirect_uris' => ['https://kosmoshop.test/oauth/callback'],
            'scopes'        => ['profile', 'account.read', 'orders.write', 'mandate'],
            'webhook'       => ['url' => 'https://kosmoshop.test/webhook', 'secret' => 'segreto-webhook'],
        ]);
    }

    // =========================================================================
    // 1. Quando parte, e cosa dice
    // =========================================================================

    public function test_entrare_in_debito_avvisa_l_applicazione(): void
    {
        Bus::fake();

        [$account] = $this->azienda(saldo: 5000);

        $account->forceFill(['available_balance' => -1000])->save();

        Bus::assertDispatched(
            SendClientWebhookJob::class,
            function (SendClientWebhookJob $job) use ($account) {
                $corpo = json_decode($job->body, true);

                return $job->event === 'company.trading_status_changed'
                    && $corpo['payload']['trading_status'] === 'in_debit'
                    && $corpo['payload']['previous_trading_status'] === 'free'
                    && $corpo['payload']['required_ky_percentage'] === 100
                    && $corpo['payload']['allowed_ky_percentages'] === [100]
                    && $corpo['payload']['account_number'] === $account->account_number;
            }
        );
    }

    public function test_uscire_dal_debito_restituisce_la_liberta_di_scelta(): void
    {
        [$account] = $this->azienda(saldo: -1000);

        Bus::fake();

        $account->forceFill(['available_balance' => 2000])->save();

        Bus::assertDispatched(
            SendClientWebhookJob::class,
            function (SendClientWebhookJob $job) {
                $corpo = json_decode($job->body, true);

                return $corpo['payload']['trading_status'] === 'free'
                    && $corpo['payload']['previous_trading_status'] === 'in_debit'
                    && $corpo['payload']['required_ky_percentage'] === null
                    && $corpo['payload']['allowed_ky_percentages'] === [25, 50, 75, 100];
            }
        );
    }

    public function test_toccare_il_tetto_massimo_ferma_le_vendite(): void
    {
        [$account] = $this->azienda(saldo: 1000);
        $account->forceFill(['max_balance' => 10000])->save();

        Bus::fake();

        $account->forceFill(['available_balance' => 10000])->save();

        Bus::assertDispatched(
            SendClientWebhookJob::class,
            function (SendClientWebhookJob $job) {
                $corpo = json_decode($job->body, true);

                return $corpo['payload']['trading_status'] === 'at_ceiling'
                    && $corpo['payload']['can_sell'] === false
                    && $corpo['payload']['allowed_ky_percentages'] === [];
            }
        );
    }

    public function test_anche_abbassare_il_tetto_cambia_lo_stato(): void
    {
        // Non è il saldo a muoversi: è l'admin che stringe il massimale.
        // Guardare solo `available_balance` lascerebbe kshop convinto che
        // l'azienda possa ancora vendere.
        [$account] = $this->azienda(saldo: 8000);

        Bus::fake();

        $account->forceFill(['max_balance' => 5000])->save();

        Bus::assertDispatched(
            SendClientWebhookJob::class,
            fn (SendClientWebhookJob $job) => json_decode($job->body, true)['payload']['trading_status'] === 'at_ceiling'
        );
    }

    public function test_sospendere_il_conto_lo_dice_a_chi_vende_per_noi(): void
    {
        [$account] = $this->azienda(saldo: 5000);

        Bus::fake();

        $account->forceFill(['status' => 'suspended'])->save();

        Bus::assertDispatched(
            SendClientWebhookJob::class,
            fn (SendClientWebhookJob $job) => json_decode($job->body, true)['payload']['trading_status'] === 'suspended'
        );
    }

    // =========================================================================
    // 2. Quando NON parte
    // =========================================================================

    public function test_un_movimento_che_non_cambia_stato_non_avvisa_nessuno(): void
    {
        [$account] = $this->azienda(saldo: 5000);

        Bus::fake();

        // Da 50 a 30 KY: il saldo cambia, lo stato commerciale no.
        $account->forceFill(['available_balance' => 3000])->save();

        Bus::assertNotDispatched(SendClientWebhookJob::class);
    }

    public function test_i_sottoconti_non_generano_eventi(): void
    {
        [$account, $company] = $this->azienda(saldo: 5000);

        $sottoconto = Account::create([
            'company_id'        => $company->id,
            'parent_account_id' => $account->id,
            'owner_type'        => 'company',
            'type'              => 'member',
            'status'            => 'active',
            'available_balance' => 1000,
        ]);

        Bus::fake();

        $sottoconto->forceFill(['available_balance' => -500])->save();

        Bus::assertNotDispatched(SendClientWebhookJob::class);
    }

    public function test_senza_canale_configurato_non_si_spedisce_niente(): void
    {
        config()->set('oauth.clients.kshop.webhook', ['url' => null, 'secret' => null]);

        [$account] = $this->azienda(saldo: 5000);

        Bus::fake();

        $account->forceFill(['available_balance' => -1000])->save();

        Bus::assertNotDispatched(SendClientWebhookJob::class);
    }

    public function test_un_url_senza_segreto_non_viene_usato(): void
    {
        // Un webhook non firmato non è verificabile da chi lo riceve: meglio
        // non spedirlo che spedirlo e far credere che sia autentico.
        config()->set('oauth.clients.kshop.webhook', ['url' => 'https://kosmoshop.test/webhook', 'secret' => '']);

        [$account] = $this->azienda(saldo: 5000);

        Bus::fake();

        $account->forceFill(['available_balance' => -1000])->save();

        Bus::assertNotDispatched(SendClientWebhookJob::class);
    }

    // =========================================================================
    // 3. Anche l'azienda, se si è registrata
    // =========================================================================

    public function test_l_azienda_iscritta_all_evento_lo_riceve(): void
    {
        [$account, $company] = $this->azienda(saldo: 5000);

        Webhook::create([
            'company_id' => $company->id,
            'url'        => 'https://gestionale-azienda.test/hook',
            'events'     => ['company.trading_status_changed'],
            'is_active'  => true,
        ]);

        Bus::fake();

        $account->forceFill(['available_balance' => -1000])->save();

        Bus::assertDispatched(
            SendWebhookJob::class,
            fn (SendWebhookJob $job) => $job->event === 'company.trading_status_changed'
        );
    }

    public function test_l_azienda_iscritta_ad_altro_non_lo_riceve(): void
    {
        [$account, $company] = $this->azienda(saldo: 5000);

        Webhook::create([
            'company_id' => $company->id,
            'url'        => 'https://gestionale-azienda.test/hook',
            'events'     => ['transfer.booked'],
            'is_active'  => true,
        ]);

        Bus::fake();

        $account->forceFill(['available_balance' => -1000])->save();

        Bus::assertNotDispatched(SendWebhookJob::class);
    }

    // =========================================================================
    // 4. Il catalogo interno continua a funzionare come prima
    // =========================================================================

    public function test_lo_shop_interno_resta_riallineato_come_prima(): void
    {
        [$account, $company] = $this->azienda(saldo: 5000);

        $prodotto = Listing::create([
            'company_id'         => $company->id,
            'created_by_user_id' => User::query()->where('company_id', $company->id)->value('id'),
            'title'              => 'Prodotto di prova',
            'description'        => 'Descrizione del prodotto di prova.',
            'category'           => 'informatica',
            'price_ky'           => 5000,
            'ky_percentage'      => 50,
            'desired_ky_percentage' => 50,
            'status'             => 'active',
            'delivery_type'      => Listing::DELIVERY_TYPE_SERVIZIO,
        ]);

        $account->forceFill(['available_balance' => -1000])->save();
        $this->assertSame(100, (int) $prodotto->fresh()->ky_percentage);

        $account->forceFill(['available_balance' => 2000])->save();
        $this->assertSame(50, (int) $prodotto->fresh()->ky_percentage);
    }

    // =========================================================================
    // 5. La consegna vera: firma e registro
    // =========================================================================

    public function test_la_consegna_e_firmata_e_lascia_traccia(): void
    {
        [$account] = $this->azienda(saldo: 5000);

        $account->forceFill(['available_balance' => -1000])->save();

        $consegna = ClientWebhookDelivery::query()
            ->where('event', 'company.trading_status_changed')
            ->sole();

        $this->assertSame(self::CLIENT_ID, $consegna->client_id);
        $this->assertSame('https://kosmoshop.test/webhook', $consegna->url);

        // La firma si calcola sui byte esatti che sono stati spediti: è per
        // questo che il corpo viene conservato così com'è invece di essere
        // ri-serializzato al bisogno.
        $attesa = 'sha256=' . hash_hmac('sha256', $consegna->body, 'segreto-webhook');

        Http::assertSent(function ($request) use ($attesa, $consegna) {
            return $request->url() === 'https://kosmoshop.test/webhook'
                && $request->header('X-KMoney-Signature')[0] === $attesa
                && $request->header('X-KMoney-Event')[0] === 'company.trading_status_changed'
                && $request->header('X-KMoney-Delivery')[0] === $consegna->uuid;
        });
    }

    // =========================================================================
    // 6. Il calcolo, isolato
    // =========================================================================

    public function test_la_precedenza_fra_i_tre_stati(): void
    {
        // Un conto sospesto non vende comunque, e chi ha toccato il tetto non
        // vende nemmeno al 100% KY: l'ordine non è arbitrario.
        $this->assertSame('suspended', Account::tradingStatusFor('suspended', -5000, 1000));
        $this->assertSame('at_ceiling', Account::tradingStatusFor('active', 1000, 1000));
        $this->assertSame('in_debit', Account::tradingStatusFor('active', -1, null));
        $this->assertSame('free', Account::tradingStatusFor('active', 0, null));
        $this->assertSame('free', Account::tradingStatusFor('active', 999, 1000));
    }

    // =========================================================================

    /**
     * @return array{0: Account, 1: Company}
     */
    private function azienda(int $saldo = 0): array
    {
        $slug = 'azienda-' . Str::random(6);

        $company = Company::create([
            'name'          => 'Azienda ' . Str::random(4),
            'slug'          => $slug,
            'email'         => $slug . '@test.test',
            'status'        => 'active',
            'kyc_status'    => 'approved',
            'currency_code' => 'KY',
            'sector'        => 'informatica',
            'description'   => 'Azienda di test',
        ]);

        $user = User::create([
            'name'                => 'Titolare ' . $company->name,
            'email'               => 'titolare-' . Str::random(8) . '@test.test',
            'password'            => 'secret123',
            'account_holder_type' => 'company',
            'company_id'          => $company->id,
            'role'                => 'owner',
            'is_active'           => true,
            'is_super_admin'      => false,
            'email_verified_at'   => now(),
            'contract_signed_at'  => now(),
        ]);

        $account = Account::create([
            'company_id'        => $company->id,
            'owner_user_id'     => $user->id,
            'owner_type'        => 'company',
            'type'              => 'member',
            'status'            => 'active',
            'available_balance' => $saldo,
        ]);

        return [$account->fresh(), $company->fresh()];
    }
}
