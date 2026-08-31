<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\CreditLimit;
use App\Models\Transfer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * M1 (31/08) — «Invia KY» non paga due volte lo stesso invio.
 *
 * Prima `SendPaymentController::execute()` generava `Str::uuid()` a ogni POST:
 * il motore sapeva gia' riconoscere un invio ripetuto (book() restituisce il
 * transfer esistente, e `transfers.idempotency_key` e' UNIQUE dalla migrazione
 * iniziale), ma con una chiave nuova ogni volta quella capacita' era buttata
 * via. La difesa esisteva solo nel browser — il bottone che si disabilita in
 * invia.blade.php — e non copriva il tasto indietro, il retry di rete, il POST
 * diretto all'endpoint, ne' una pagina con JavaScript rotto.
 *
 * Qui si prova il comportamento del SERVER, che e' l'unico che vale.
 */
class SendPaymentIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: Account} */
    private function makeSender(int $balance = 50000): array
    {
        $user = User::factory()->create([
            'email_verified_at'  => now(),
            'contract_signed_at' => now(),
            'role'               => 'private-owner',
        ]);

        $account = Account::factory()->create([
            'owner_user_id'          => $user->id,
            'owner_type'             => 'private',
            'type'                   => 'primary',
            'status'                 => 'active',
            'currency_code'          => 'KY',
            'available_balance'      => $balance,
            'allow_negative_balance' => true,
        ]);

        CreditLimit::create([
            'account_id'            => $account->id,
            'credit_limit'          => 100000,
            'daily_outgoing_limit'  => 200000,
            'single_transfer_limit' => 100000,
            'status'                => 'active',
        ]);

        return [$user, $account];
    }

    private function makeRecipientAccount(): Account
    {
        $company = Company::factory()->create(['kyc_status' => 'approved', 'status' => 'active']);

        return Account::factory()->create([
            'company_id'        => $company->id,
            'owner_type'        => 'company',
            'type'              => 'primary',
            'status'            => 'active',
            'currency_code'     => 'KY',
            'available_balance' => 0,
        ]);
    }

    private function invia(User $user, Account $to, string $amount, string $token, ?string $description = null): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($user)->post(route('portal.invia.esegui'), [
            'to_account_id' => $to->id,
            'amount'        => $amount,
            'description'   => $description,
            'invio_token'   => $token,
        ]);
    }

    /** Il caso vero: lo stesso form arriva due volte al server. */
    public function test_the_same_form_sent_twice_charges_once(): void
    {
        [$user, $from] = $this->makeSender(50000);
        $to    = $this->makeRecipientAccount();
        $token = (string) Str::uuid();

        $this->invia($user, $to, '20.00', $token)->assertRedirect();
        $this->invia($user, $to, '20.00', $token)->assertRedirect();

        $this->assertSame(1, Transfer::where('kind', 'portal_payment')->count());
        $this->assertSame(48000, (int) $from->fresh()->available_balance);
        $this->assertSame(2000, (int) $to->fresh()->available_balance);
    }

    /** E il secondo invio lo DICE, invece di far finta di essere nuovo. */
    public function test_the_repeated_send_shows_the_original_receipt_and_says_so(): void
    {
        [$user] = $this->makeSender();
        $to    = $this->makeRecipientAccount();
        $token = (string) Str::uuid();

        $this->invia($user, $to, '20.00', $token);
        $primo = Transfer::where('kind', 'portal_payment')->sole();

        $this->invia($user, $to, '20.00', $token)
            ->assertRedirect(route('portal.invia.ricevuta', $primo->uuid))
            ->assertSessionHas('portal_warning');
    }

    /**
     * Il token non deve trasformarsi in una gabbia: chi torna indietro e cambia
     * l'importo sta facendo un pagamento DIVERSO, e deve passare.
     */
    public function test_a_different_amount_with_the_same_token_is_a_new_payment(): void
    {
        [$user, $from] = $this->makeSender(50000);
        $to    = $this->makeRecipientAccount();
        $token = (string) Str::uuid();

        $this->invia($user, $to, '20.00', $token)->assertRedirect();
        $this->invia($user, $to, '35.00', $token)->assertRedirect();

        $this->assertSame(2, Transfer::where('kind', 'portal_payment')->count());
        // 500 - 20 - 35 = 445 KY: i due importi sono usciti entrambi.
        $this->assertSame(44500, (int) $from->fresh()->available_balance);
    }

    /** Stesso importo e stesso destinatario, ma da un form nuovo: passa. */
    public function test_a_new_form_can_repeat_the_same_payment_on_purpose(): void
    {
        [$user, $from] = $this->makeSender(50000);
        $to = $this->makeRecipientAccount();

        $this->invia($user, $to, '20.00', (string) Str::uuid())->assertRedirect();
        $this->invia($user, $to, '20.00', (string) Str::uuid())->assertRedirect();

        $this->assertSame(2, Transfer::where('kind', 'portal_payment')->count());
        $this->assertSame(46000, (int) $from->fresh()->available_balance);
    }

    /**
     * Il conto pagatore fa parte della chiave. Senza, chi indovinasse il token
     * di un altro non solo bloccherebbe il suo pagamento, ma si vedrebbe
     * rimandare alla RICEVUTA di un pagamento non suo.
     */
    public function test_two_senders_with_the_same_token_do_not_collide(): void
    {
        [$primo]  = $this->makeSender();
        [$secondo] = $this->makeSender();
        $to    = $this->makeRecipientAccount();
        $token = 'token-identico-per-tutti-e-due';

        $this->invia($primo, $to, '20.00', $token)->assertRedirect();
        $this->invia($secondo, $to, '20.00', $token)
            ->assertRedirect()
            ->assertSessionMissing('portal_warning');

        $this->assertSame(2, Transfer::where('kind', 'portal_payment')->count());
        $this->assertSame(4000, (int) $to->fresh()->available_balance);
    }

    /**
     * Anche la causale distingue due invii: stesso importo e stesso token, ma
     * scritta diversa = intenzione diversa.
     */
    public function test_a_different_description_is_a_different_payment(): void
    {
        [$user] = $this->makeSender();
        $to    = $this->makeRecipientAccount();
        $token = (string) Str::uuid();

        $this->invia($user, $to, '20.00', $token, 'Fattura 1')->assertRedirect();
        $this->invia($user, $to, '20.00', $token, 'Fattura 2')->assertRedirect();

        $this->assertSame(2, Transfer::where('kind', 'portal_payment')->count());
    }

    /**
     * POST diretto all'endpoint, senza il campo del form (client vecchio,
     * script, JavaScript rotto): si ricade sulla finestra di un minuto. Non e'
     * preciso come il token, ma e' comunque una chiave STABILE — quella
     * casuale di prima non fermava nulla.
     */
    public function test_without_the_form_token_the_minute_window_still_protects(): void
    {
        // Il ripiego usa il minuto corrente: senza congelare l'orologio il
        // test sarebbe scaduto (raramente) a cavallo di un cambio di minuto.
        $this->freezeTime();

        [$user, $from] = $this->makeSender(50000);
        $to = $this->makeRecipientAccount();

        $paga = fn () => $this->actingAs($user)->post(route('portal.invia.esegui'), [
            'to_account_id' => $to->id,
            'amount'        => '20.00',
        ]);

        $paga()->assertRedirect();
        $paga()->assertRedirect();

        $this->assertSame(1, Transfer::where('kind', 'portal_payment')->count());
        $this->assertSame(48000, (int) $from->fresh()->available_balance);
    }

    /**
     * Il vincolo provato al livello a cui vive. Il controllo preventivo nel
     * controller e questo indice producono lo stesso stato finale — una riga
     * sola — quindi un test sullo stato finale non distingue quale dei due sta
     * lavorando (la lezione di A5). Qui si scrive diritto sul modello.
     */
    public function test_the_database_refuses_two_transfers_with_the_same_key(): void
    {
        [$user, $from] = $this->makeSender();
        $to = $this->makeRecipientAccount();

        $riga = [
            'initiated_by'    => $user->id,
            'from_account_id' => $from->id,
            'to_account_id'   => $to->id,
            'amount'          => 2000,
            'currency_code'   => 'KY',
            'status'          => 'booked',
            'kind'            => 'portal_payment',
            'idempotency_key' => 'invia_prova_chiave_ripetuta',
            'booked_at'       => now(),
        ];

        Transfer::create($riga);

        $this->expectException(\Illuminate\Database\QueryException::class);
        Transfer::create($riga);
    }

    /** La pagina serve un token nuovo a ogni caricamento. */
    public function test_each_page_load_serves_a_fresh_token(): void
    {
        [$user] = $this->makeSender();

        $uno = $this->actingAs($user)->get(route('portal.invia'))->viewData('invioToken');
        $due = $this->actingAs($user)->get(route('portal.invia'))->viewData('invioToken');

        $this->assertNotSame($uno, $due);
        $this->assertNotEmpty($uno);
    }

    /**
     * E il token arriva davvero nel form. Senza questa asserzione, togliere il
     * campo nascosto dalla pagina non farebbe fallire nulla: la protezione
     * scivolerebbe in silenzio sul ripiego a finestra di un minuto.
     */
    public function test_the_form_carries_the_token(): void
    {
        [$user] = $this->makeSender();

        $this->actingAs($user)
            ->get(route('portal.invia'))
            ->assertSee('name="invio_token"', false);
    }
}
