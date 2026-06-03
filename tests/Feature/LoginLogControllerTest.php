<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class LoginLogControllerTest extends TestCase
{
    use RefreshDatabase;

    private function makePrivateUser(): User
    {
        $user = User::create([
            'name'                => 'Log User',
            'email'               => 'loguser-' . Str::random(6) . '@test.test',
            'password'            => 'secret123',
            'account_holder_type' => 'private',
            'company_id'          => null,
            'is_active'           => true,
            'is_super_admin'      => false,
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();

        Account::create([
            'owner_user_id'     => $user->id,
            'owner_type'        => 'private',
            'type'              => 'member',
            'status'            => 'active',
            'available_balance' => 0,
        ]);

        return $user;
    }

    public function test_login_logs_requires_authentication(): void
    {
        $this->get(route('portal.login-logs'))
            ->assertRedirect(route('login'));
    }

    public function test_user_can_view_sessions_page(): void
    {
        $user = $this->makePrivateUser();

        $this->actingAs($user)
            ->get(route('portal.login-logs'))
            ->assertOk()
            ->assertSee('Sessioni');
    }
}
