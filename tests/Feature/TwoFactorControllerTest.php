<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TwoFactorControllerTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(bool $with2fa = false): User
    {
        $user = User::factory()->create([
            'email_verified_at'       => now(),
            'two_factor_confirmed_at' => null,
            'contract_signed_at'     => now(),
            'two_factor_secret'       => null,
        ]);
        $company = Company::factory()->create(['kyc_status' => 'approved']);
        Account::factory()->create([
            'company_id'    => $company->id,
            'owner_user_id' => $user->id,
            'status'        => 'active',
            'currency_code' => 'KY',
            'available_balance' => 10000,
        ]);

        if ($with2fa) {
            $secret = Totp::generateSecret();
            $user->forceFill([
                'two_factor_secret'         => $secret,
                'two_factor_confirmed_at'   => now(),
                'two_factor_recovery_codes' => [bcrypt('AAAA-BBBB')],
            ])->save();
        }

        return $user;
    }

    /** GET /profilo/sicurezza — pagina sicurezza visibile */
    public function test_security_page_visible(): void
    {
        $user = $this->makeUser();
        $response = $this->actingAs($user)->get(route('portal.security'));
        $response->assertOk()->assertViewIs('portal.security');
    }

    /** POST /profilo/2fa/inizia — genera secret in sessione */
    public function test_start_setup_stores_secret_in_session(): void
    {
        $user = $this->makeUser();
        $response = $this->actingAs($user)->post(route('portal.2fa.start'));
        $response->assertRedirect(route('portal.security'));
        $response->assertSessionHas('portal_success');
    }

    /** POST /profilo/2fa/conferma — codice valido attiva 2FA */
    public function test_confirm_setup_with_valid_code(): void
    {
        $user   = $this->makeUser();
        $secret = Totp::generateSecret();
        $code   = Totp::currentCode($secret);

        $response = $this->actingAs($user)
            ->withSession(['2fa_pending_secret' => $secret])
            ->post(route('portal.2fa.confirm'), ['code' => $code]);

        $response->assertRedirect(route('portal.2fa.recovery-codes'));
        $this->assertNotNull($user->fresh()->two_factor_confirmed_at);
    }

    /** POST /profilo/2fa/conferma — codice non valido */
    public function test_confirm_setup_with_invalid_code(): void
    {
        $user   = $this->makeUser();
        $secret = Totp::generateSecret();

        $response = $this->actingAs($user)
            ->withSession(['2fa_pending_secret' => $secret])
            ->post(route('portal.2fa.confirm'), ['code' => '000000']);

        $response->assertSessionHasErrors('code');
        $this->assertNull($user->fresh()->two_factor_confirmed_at);
    }

    /** POST /profilo/2fa/conferma — senza secret in sessione */
    public function test_confirm_without_session_secret(): void
    {
        $user = $this->makeUser();
        $response = $this->actingAs($user)->post(route('portal.2fa.confirm'), ['code' => '123456']);
        $response->assertSessionHasErrors('code');
    }

    /** POST /profilo/2fa/disattiva — disattiva con password corretta */
    public function test_disable_2fa_with_correct_password(): void
    {
        $user = $this->makeUser(with2fa: true);

        $response = $this->actingAs($user)
            ->withSession(['two_factor_verified' => true, 'step_up_verified_at' => now()->timestamp])
            ->post(route('portal.2fa.disable'), ['password' => 'password']);

        $response->assertRedirect(route('portal.security'));
        $this->assertNull($user->fresh()->two_factor_confirmed_at);
        $this->assertNull($user->fresh()->two_factor_secret);
    }

    /** POST /profilo/2fa/disattiva — password errata */
    public function test_disable_2fa_with_wrong_password(): void
    {
        $user = $this->makeUser(with2fa: true);

        $response = $this->actingAs($user)
            ->withSession(['two_factor_verified' => true, 'step_up_verified_at' => now()->timestamp])
            ->post(route('portal.2fa.disable'), ['password' => 'wrong-password']);

        $response->assertSessionHasErrors('password');
        $this->assertNotNull($user->fresh()->two_factor_confirmed_at);
    }

    /** GET /2fa/verifica — mostra challenge */
    public function test_challenge_page_visible(): void
    {
        $user = $this->makeUser(with2fa: true);
        $response = $this->actingAs($user)->get(route('2fa.challenge'));
        $response->assertOk()->assertViewIs('2fa.challenge');
    }

    /** POST /2fa/verifica — codice TOTP valido */
    public function test_verify_challenge_with_valid_totp(): void
    {
        $user   = $this->makeUser(with2fa: true);
        $secret = $user->two_factor_secret;
        $code   = Totp::currentCode($secret);

        $response = $this->actingAs($user)->post(route('2fa.verify'), ['code' => $code]);
        $response->assertRedirect(route('portal.dashboard'));
        $this->assertTrue(session('two_factor_verified'));
    }

    /** POST /2fa/verifica — codice errato */
    public function test_verify_challenge_with_invalid_code(): void
    {
        $user = $this->makeUser(with2fa: true);

        $response = $this->actingAs($user)->post(route('2fa.verify'), ['code' => '000000']);
        $response->assertSessionHasErrors();
    }
}
