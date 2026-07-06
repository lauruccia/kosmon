<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordResetFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_forgot_password_sends_reset_notification_with_working_link(): void
    {
        $this->seed();
        $user = User::where('email', 'maria.ferri@kmoney.test')->firstOrFail();

        Notification::fake();

        $this->post('/forgot-password', ['email' => $user->email])
            ->assertSessionHas('status');

        Notification::assertSentTo($user, ResetPasswordNotification::class, function ($notification, $channels) use ($user) {
            $this->assertContains('mail', $channels);

            // Renderizza davvero il template email per intercettare errori
            // (variabili mancanti, branding rotto, url malformato...) che
            // Notification::fake() da solo non rileverebbe.
            $mail = $notification->toMail($user);
            $rendered = (string) $mail->render();

            $expectedUrl = route('password.reset', [
                'token' => $notification->token,
                'email' => $user->email,
            ], false);

            $this->assertStringContainsString('Reimposta la password', $rendered);
            $this->assertStringContainsString($expectedUrl, $rendered);

            return true;
        });
    }

    public function test_forgot_password_with_unknown_email_shows_generic_error(): void
    {
        $this->post('/forgot-password', ['email' => 'nessuno@example.test'])
            ->assertSessionHasErrors('email');
    }

    public function test_reset_password_form_loads_with_valid_token(): void
    {
        $this->seed();
        $user = User::where('email', 'maria.ferri@kmoney.test')->firstOrFail();
        $token = Password::createToken($user);

        $this->get('/reset-password/' . $token . '?email=' . urlencode($user->email))
            ->assertOk();
    }

    public function test_user_can_complete_password_reset_and_then_login(): void
    {
        $this->seed();
        $user = User::where('email', 'maria.ferri@kmoney.test')->firstOrFail();
        $token = Password::createToken($user);

        $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'nuovaPassword123',
            'password_confirmation' => 'nuovaPassword123',
        ])->assertRedirect('/login');

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'nuovaPassword123',
        ])->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($user->fresh());
    }
}
