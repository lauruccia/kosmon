<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Copre il cambio email del portale.
 *
 * Il motivo per cui questo file esiste: fino al 28/08/2026
 * EmailChangeController::verify() non confrontava MAI il codice inserito con
 * `email_change_token`. Otto caratteri qualsiasi confermavano il cambio e
 * marcavano la nuova casella come verificata (`email_verified_at`) senza che
 * nessuno avesse mai letto la mail inviata a quell'indirizzo.
 */
class EmailChangeVerificationTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(): User
    {
        $user = User::create([
            'name'                => 'Utente Privato',
            'email'               => 'vecchia-' . Str::random(6) . '@test.test',
            'password'            => 'secret123',
            'account_holder_type' => 'private',
            'company_id'          => null,
            'is_active'           => true,
        ]);

        // Privato: niente onboarding e niente contratto da firmare.
        $user->forceFill(['email_verified_at' => now()->subYear()])->save();

        return $user->fresh();
    }

    /** Avvia una richiesta di cambio e restituisce il codice generato. */
    private function requestChange(User $user, string $newEmail): string
    {
        Mail::fake();

        $this->actingAsWithSession($user)
            ->post('/profilo/email', [
                'new_email'        => $newEmail,
                'current_password' => 'secret123',
            ])
            ->assertRedirect(route('portal.email-change.verify-form'));

        $user->refresh();

        $this->assertSame($newEmail, $user->pending_email);
        $this->assertNotNull($user->email_change_token);

        return $user->email_change_token;
    }

    // ── Il buco ─────────────────────────────────────────────────────────────

    public function test_un_codice_sbagliato_non_cambia_l_email(): void
    {
        $user     = $this->makeUser();
        $vecchia  = $user->email;
        $verifica = $user->email_verified_at;

        $vero = $this->requestChange($user, 'nuova@test.test');
        $finto = $vero === 'AAAAAAAA' ? 'BBBBBBBB' : 'AAAAAAAA';

        $this->actingAsWithSession($user)
            ->from(route('portal.email-change.verify-form'))
            ->post('/profilo/email/verifica', ['token' => $finto])
            ->assertSessionHasErrors('token');

        $user->refresh();

        $this->assertSame($vecchia, $user->email, 'L\'email e\' cambiata con un codice inventato.');
        $this->assertSame('nuova@test.test', $user->pending_email, 'La richiesta doveva restare in attesa.');
        $this->assertEquals($verifica, $user->email_verified_at, 'email_verified_at non deve essere toccato.');
    }

    public function test_il_codice_giusto_cambia_l_email(): void
    {
        $user    = $this->makeUser();
        $vecchia = $user->email;
        $codice  = $this->requestChange($user, 'nuova@test.test');

        $this->actingAsWithSession($user)
            ->post('/profilo/email/verifica', ['token' => $codice])
            ->assertRedirect(route('portal.dashboard'));

        $user->refresh();

        $this->assertSame('nuova@test.test', $user->email);
        $this->assertNull($user->pending_email);
        $this->assertNull($user->email_change_token);
        $this->assertNotNull($user->email_verified_at);

        $this->assertDatabaseHas('audit_logs', [
            'event'         => 'user.email_changed',
            'actor_user_id' => $user->id,
        ]);

        $this->assertDatabaseMissing('users', ['email' => $vecchia]);
    }

    public function test_il_codice_e_accettato_anche_in_minuscolo(): void
    {
        $user   = $this->makeUser();
        $codice = $this->requestChange($user, 'nuova@test.test');

        $this->actingAsWithSession($user)
            ->post('/profilo/email/verifica', ['token' => strtolower($codice)])
            ->assertRedirect(route('portal.dashboard'));

        $this->assertSame('nuova@test.test', $user->fresh()->email);
    }

    // ── Difese intorno al controllo ─────────────────────────────────────────

    public function test_cinque_codici_sbagliati_annullano_la_richiesta(): void
    {
        $user = $this->makeUser();
        $this->requestChange($user, 'nuova@test.test');

        for ($i = 1; $i <= 5; $i++) {
            $this->actingAsWithSession($user)
                ->from(route('portal.email-change.verify-form'))
                ->post('/profilo/email/verifica', ['token' => 'ZZZZZZZ' . $i])
                ->assertSessionHasErrors('token');
        }

        $user->refresh();

        $this->assertNull($user->pending_email, 'Dopo 5 errori la richiesta doveva essere annullata.');
        $this->assertNull($user->email_change_token);

        $this->assertDatabaseHas('audit_logs', [
            'event'         => 'user.email_change_blocked',
            'actor_user_id' => $user->id,
        ]);
    }

    public function test_il_codice_scaduto_non_passa(): void
    {
        $user    = $this->makeUser();
        $vecchia = $user->email;
        $codice  = $this->requestChange($user, 'nuova@test.test');

        $user->forceFill(['email_change_expires_at' => now()->subMinute()])->save();

        $this->actingAsWithSession($user)
            ->post('/profilo/email/verifica', ['token' => $codice])
            ->assertSessionHasErrors('token');

        $user->refresh();

        $this->assertSame($vecchia, $user->email);
        $this->assertNull($user->pending_email);
    }

    public function test_indirizzo_occupato_nel_frattempo_non_passa(): void
    {
        $user    = $this->makeUser();
        $vecchia = $user->email;
        $codice  = $this->requestChange($user, 'contesa@test.test');

        // Qualcun altro si registra con quell'indirizzo dopo la richiesta:
        // il controllo unique: era stato fatto fino a 30 minuti prima.
        User::create([
            'name'                => 'Altro',
            'email'               => 'contesa@test.test',
            'password'            => 'secret123',
            'account_holder_type' => 'private',
            'is_active'           => true,
        ]);

        $this->actingAsWithSession($user)
            ->post('/profilo/email/verifica', ['token' => $codice])
            ->assertSessionHasErrors('token');

        $user->refresh();

        $this->assertSame($vecchia, $user->email);
        $this->assertNull($user->pending_email);
    }

    public function test_il_link_di_revoca_annulla_la_richiesta(): void
    {
        $user = $this->makeUser();
        $this->requestChange($user, 'nuova@test.test');

        $user->refresh();
        $cancelToken = $user->email_change_cancel_token;
        $this->assertNotNull($cancelToken);

        $this->get(route('email-change.cancel-by-token', ['token' => $cancelToken]))
            ->assertRedirect(route('login'));

        $user->refresh();

        $this->assertNull($user->pending_email);
        $this->assertNull($user->email_change_token);
        $this->assertNull($user->email_change_cancel_token);

        $this->assertDatabaseHas('audit_logs', [
            'event'         => 'user.email_change_cancelled_by_link',
            'actor_user_id' => $user->id,
        ]);
    }
}
