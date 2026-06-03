<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class NotificationPreferencesControllerTest extends TestCase
{
    use RefreshDatabase;

    private function makePrivateUser(): User
    {
        $user = User::create([
            'name'                => 'Notif User',
            'email'               => 'notif-' . Str::random(6) . '@test.test',
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

    public function test_notification_preferences_requires_authentication(): void
    {
        $this->get(route('portal.notification-preferences'))
            ->assertRedirect(route('login'));
    }

    public function test_user_can_view_notification_preferences(): void
    {
        $user = $this->makePrivateUser();

        $this->actingAs($user)
            ->get(route('portal.notification-preferences'))
            ->assertOk()
            ->assertSee('preferenze', false);
    }

    public function test_user_can_update_notification_preferences(): void
    {
        $user = $this->makePrivateUser();

        $this->actingAs($user)
            ->patch(route('portal.notification-preferences.update'), [
                'payment_received' => ['database'],
                'payment_sent'     => ['database', 'mail'],
            ])
            ->assertRedirect();
    }
}
