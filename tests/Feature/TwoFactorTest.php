<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class TwoFactorTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // Setup flow
    // -------------------------------------------------------------------------

    public function test_user_can_start_2fa_setup(): void
    {
        $user = $this->makeUser();

        $response = $this->actingAs($user)
            ->post(route('portal.2fa.start'));

        $response->assertRedirect(route('portal.security'));
        $this->assertNotNull(session('2fa_pending_secret'));
    }

    public function test_user_can_confirm_2fa_setup_with_valid_code(): void
    {
        $user   = $this->makeUser();
        $secret = Totp::generateSecret();
        $code   = Totp::currentCode($secret);

        $response = $this->actingAs($user)
            ->withSession(['2fa_pending_secret' => $secret])
            ->post(route('portal.2fa.confirm'), ['code' => $code]);

        $response->assertRedirect(route('portal.2fa.recovery-codes'));

        $user->refresh();
        $this->assertNotNull($user->two_factor_confirmed_at);
        $this->assertSame($secret, $user->two_factor_secret);
        $this->assertCount(8, $user->two_factor_recovery_codes);
    }

    public function test_confirm_2fa_rejects_invalid_otp_code(): void
    {
        $user   = $this->makeUser();
        $secret = Totp::generateSecret();

        $response = $this->actingAs($user)
            ->withSession(['2fa_pending_secret' => $secret])
            ->post(route('portal.2fa.confirm'), ['code' => '000000']);

        $response->assertRedirect(route('portal.security'));
        $response->assertSessionHasErrors('code');

        $user->refresh();
        $this->assertNull($user->two_factor_confirmed_at);
    }

    public function test_confirm_2fa_fails_without_pending_secret_in_session(): void
    {
        $user = $this->makeUser();

        $response = $this->actingAs($user)
            ->post(route('portal.2fa.confirm'), ['code' => '123456']);

        $response->assertRedirect(route('portal.security'));
        $response->assertSessionHasErrors('code');
    }

    // -------------------------------------------------------------------------
    // Recovery codes display
    // -------------------------------------------------------------------------

    public function test_recovery_codes_page_shows_codes_from_session(): void
    {
        $user  = $this->makeUser();
        $codes = ['aabb1-cc2dd', '11223-44556'];

        $response = $this->actingAs($user)
            ->withSession(['2fa_recovery_codes_plain' => $codes])
            ->get(route('portal.2fa.recovery-codes'));

        $response->assertOk();
        $response->assertSee('aabb1-cc2dd');
        $response->assertSee('11223-44556');
        // Codes should have been pulled from session (consumed)
        $this->assertNull(session('2fa_recovery_codes_plain'));
    }

    public function test_recovery_codes_page_redirects_without_session_codes(): void
    {
        $user = $this->makeUser();

        $response = $this->actingAs($user)
            ->get(route('portal.2fa.recovery-codes'));

        $response->assertRedirect(route('portal.security'));
    }

    // -------------------------------------------------------------------------
    // Challenge: TOTP
    // -------------------------------------------------------------------------

    public function test_user_can_verify_challenge_with_valid_totp(): void
    {
        $secret = Totp::generateSecret();
        $user   = $this->makeEnabledUser($secret);
        $code   = Totp::currentCode($secret);

        $response = $this->actingAs($user)
            ->post(route('2fa.verify'), ['code' => $code]);

        $response->assertRedirect(route('portal.dashboard'));
        $this->assertTrue(session('two_factor_verified'));
    }

    public function test_challenge_rejects_wrong_totp_code(): void
    {
        $secret = Totp::generateSecret();
        $user   = $this->makeEnabledUser($secret);

        $response = $this->actingAs($user)
            ->post(route('2fa.verify'), ['code' => '000000']);

        $response->assertRedirect();
        $response->assertSessionHasErrors('code');
        $this->assertFalse((bool) session('two_factor_verified'));
    }

    // -------------------------------------------------------------------------
    // Challenge: recovery codes
    // -------------------------------------------------------------------------

    public function test_user_can_verify_challenge_with_recovery_code(): void
    {
        $secret    = Totp::generateSecret();
        $plainCode = 'abcd1-23456';
        $user      = $this->makeEnabledUser($secret, [$plainCode]);

        $response = $this->actingAs($user)
            ->post(route('2fa.verify'), ['recovery_code' => $plainCode]);

        $response->assertRedirect(route('portal.dashboard'));
        $this->assertTrue(session('two_factor_verified'));
    }

    public function test_recovery_code_is_consumed_after_use(): void
    {
        $secret    = Totp::generateSecret();
        $plainCode = 'abcd1-23456';
        $user      = $this->makeEnabledUser($secret, [$plainCode]);

        $this->actingAs($user)
            ->post(route('2fa.verify'), ['recovery_code' => $plainCode]);

        $user->refresh();
        $this->assertCount(0, $user->two_factor_recovery_codes ?? []);
    }

    public function test_invalid_recovery_code_is_rejected(): void
    {
        $secret = Totp::generateSecret();
        $user   = $this->makeEnabledUser($secret, ['abcd1-23456']);

        $response = $this->actingAs($user)
            ->post(route('2fa.verify'), ['recovery_code' => 'wrong-nope0']);

        $response->assertSessionHasErrors('code');
        $this->assertFalse((bool) session('two_factor_verified'));
    }

    // -------------------------------------------------------------------------
    // Disable 2FA
    // -------------------------------------------------------------------------

    public function test_user_can_disable_2fa_with_correct_password(): void
    {
        $secret = Totp::generateSecret();
        $user   = $this->makeEnabledUser($secret);

        $response = $this->actingAs($user)
            ->withSession(['two_factor_verified' => true])
            ->post(route('portal.2fa.disable'), ['password' => 'secret123']);

        $response->assertRedirect(route('portal.security'));

        $user->refresh();
        $this->assertNull($user->two_factor_confirmed_at);
        $this->assertNull($user->two_factor_secret);
        $this->assertNull($user->two_factor_recovery_codes);
        $this->assertFalse((bool) session('two_factor_verified'));
    }

    public function test_disable_2fa_fails_with_wrong_password(): void
    {
        $secret = Totp::generateSecret();
        $user   = $this->makeEnabledUser($secret);

        $response = $this->actingAs($user)
            ->withSession(['two_factor_verified' => true])
            ->post(route('portal.2fa.disable'), ['password' => 'wrongpassword']);

        $response->assertSessionHasErrors('password');

        $user->refresh();
        $this->assertNotNull($user->two_factor_confirmed_at);
    }

    // -------------------------------------------------------------------------
    // Regenerate recovery codes
    // -------------------------------------------------------------------------

    public function test_user_can_regenerate_recovery_codes(): void
    {
        $secret    = Totp::generateSecret();
        $oldCodes  = ['abcd1-23456', 'ffff0-00001'];
        $user      = $this->makeEnabledUser($secret, $oldCodes);

        $response = $this->actingAs($user)
            ->withSession(['two_factor_verified' => true])
            ->post(route('portal.2fa.regenerate-codes'), ['password' => 'secret123']);

        $response->assertRedirect(route('portal.2fa.recovery-codes'));

        $user->refresh();
        $this->assertCount(8, $user->two_factor_recovery_codes);

        // Old plaintext codes must no longer match any stored hash
        foreach ($oldCodes as $old) {
            $match = collect($user->two_factor_recovery_codes)
                ->contains(fn ($hash) => Hash::check($old, $hash));
            $this->assertFalse($match, "Old recovery code '{$old}' should be invalidated");
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeUser(array $attrs = []): User
    {
        return User::create(array_merge([
            'name'                => 'Test User',
            'email'               => 'user-' . Str::random(8) . '@test.test',
            'password'            => 'secret123',
            'account_holder_type' => 'private',
            'company_id'          => null,
            'is_active'           => true,
            'is_super_admin'      => false,
            'email_verified_at'   => now(),
        ], $attrs));
    }

    /** Create a user with 2FA already enabled (and optional plaintext recovery codes). */
    private function makeEnabledUser(string $secret, array $plainRecoveryCodes = []): User
    {
        $hashed = array_map(fn ($c) => bcrypt($c), $plainRecoveryCodes);

        $user = $this->makeUser();
        $user->forceFill([
            'two_factor_secret'         => $secret,
            'two_factor_confirmed_at'   => now(),
            'two_factor_recovery_codes' => empty($hashed) ? null : $hashed,
        ])->save();

        return $user;
    }
}
