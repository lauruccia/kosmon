<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Transfer;
use App\Models\User;
use App\Services\TransferBookingService;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * I freni che mancavano del tutto prima del 28/08/2026:
 *
 *  - A4: /2fa/verifica e /profilo/conferma-identita non avevano NESSUN limite
 *    ai tentativi. La seconda in particolare era un modo illimitato per
 *    indovinare la password dell'account.
 *  - C4: TransferBookingService::bookFee() senza la guardia "se a pagare e' il
 *    conto sistema, esci": $payer e $system sarebbero due istanze della stessa
 *    riga e il secondo salvataggio sovrascrive il primo, lasciando il conto
 *    sistema a saldo + commissione. Moneta creata dal nulla.
 */
class FreniCredenzialiTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(bool $con2fa = false): User
    {
        $user = User::create([
            'name'                => 'Utente ' . Str::random(5),
            'email'               => 'utente-' . Str::random(8) . '@test.test',
            'password'            => 'secret123',
            'account_holder_type' => 'private',
            'company_id'          => null,
            'is_active'           => true,
        ]);

        $campi = ['email_verified_at' => now()];

        if ($con2fa) {
            $campi['two_factor_secret']       = Totp::generateSecret();
            $campi['two_factor_confirmed_at'] = now();
        }

        $user->forceFill($campi)->save();

        return $user->fresh();
    }

    // ── A4: il challenge 2FA ────────────────────────────────────────────────

    public function test_dopo_cinque_codici_2fa_sbagliati_anche_quello_giusto_viene_respinto(): void
    {
        $user = $this->makeUser(con2fa: true);

        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($user)
                ->from(route('2fa.challenge'))
                ->post('/2fa/verifica', ['code' => '000000'])
                ->assertSessionHasErrors('code');
        }

        // Il codice VERO, mentre il blocco e' attivo: deve essere respinto lo
        // stesso, altrimenti il freno non frena.
        $response = $this->actingAs($user)
            ->from(route('2fa.challenge'))
            ->post('/2fa/verifica', ['code' => Totp::currentCode($user->two_factor_secret)]);

        $response->assertSessionHasErrors('code');
        $this->assertNotTrue(session('two_factor_verified'));
    }

    public function test_il_codice_2fa_giusto_passa_se_non_c_e_blocco(): void
    {
        $user = $this->makeUser(con2fa: true);

        $this->actingAs($user)
            ->post('/2fa/verifica', ['code' => Totp::currentCode($user->two_factor_secret)])
            ->assertRedirect();

        $this->assertTrue(session('two_factor_verified'));
    }

    // ── A4: la conferma identita' ───────────────────────────────────────────

    public function test_dopo_cinque_password_sbagliate_anche_quella_giusta_viene_respinta(): void
    {
        $user = $this->makeUser();

        for ($i = 0; $i < 5; $i++) {
            $this->actingAsWithSession($user)
                ->from(route('portal.step-up.show'))
                ->post('/profilo/conferma-identita', ['password' => 'sbagliata' . $i])
                ->assertSessionHasErrors('credential');
        }

        $response = $this->actingAsWithSession($user)
            ->from(route('portal.step-up.show'))
            ->post('/profilo/conferma-identita', ['password' => 'secret123']);

        $response->assertSessionHasErrors('credential');
        $this->assertNull(session('step_up_verified_at'));
    }

    public function test_la_password_giusta_passa_se_non_c_e_blocco(): void
    {
        $user = $this->makeUser();

        $this->actingAsWithSession($user)
            ->post('/profilo/conferma-identita', ['password' => 'secret123'])
            ->assertRedirect();

        $this->assertNotNull(session('step_up_verified_at'));
    }

    // ── C4: la commissione non crea moneta ──────────────────────────────────

    public function test_la_commissione_non_si_addebita_al_conto_sistema_stesso(): void
    {
        $sistema = Account::systemAccount();
        $this->assertNotNull($sistema, 'Conto sistema non creato dalle migration.');

        $sistema->forceFill(['available_balance' => 100_000])->save();

        $padre = Transfer::create([
            'from_account_id' => $sistema->id,
            'to_account_id'   => $sistema->id,
            'amount'          => 1_000,
            'currency_code'   => 'KY',
            'kind'            => 'portal_payment',
            'status'          => 'booked',
            'description'     => 'Movimento di prova',
            'idempotency_key' => 'padre_' . Str::random(8),
            'booked_at'       => now(),
        ]);

        $metodo = new \ReflectionMethod(TransferBookingService::class, 'bookFee');
        $metodo->setAccessible(true);
        $metodo->invoke(app(TransferBookingService::class), $sistema, $sistema, 500, 'portal_payment', $padre);

        $this->assertSame(
            100_000,
            (int) Account::findOrFail($sistema->id)->available_balance,
            'Il conto sistema non deve guadagnare la commissione che avrebbe dovuto pagare.'
        );

        $this->assertSame(0, Transfer::where('kind', 'portal_fee')->count());
    }
}
