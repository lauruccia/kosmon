<?php

namespace Tests\Feature;

use App\Http\Middleware\RequireStepUp;
use App\Models\Account;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * A2 — la finestra dei 15 minuti dello step-up.
 *
 * Prima del 28/08/2026 non scadeva mai, per due bug sovrapposti: in sessione si
 * salvava l'oggetto Carbon invece del timestamp (e Carbon, ricostruendolo, ne
 * sommava i gruppi di cifre finendo nel 1970), e il confronto usava
 * `now()->diffInMinutes($passato)`, negativo e quindi sempre < 15.
 *
 * Nessun test verificava la SCADENZA: si controllava solo che la chiave di
 * sessione ci fosse. Questi test coprono quel buco.
 */
class StepUpWindowTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(): User
    {
        $company = Company::factory()->create(['kyc_status' => 'approved']);
        $user = User::factory()->create([
            'company_id'              => $company->id,
            'email_verified_at'       => now(),
            'two_factor_confirmed_at' => null,
            'contract_signed_at'      => now(),
            'password'                => Hash::make('password-corretta'),
        ]);
        Account::factory()->create([
            'company_id'        => $company->id,
            'owner_user_id'     => $user->id,
            'status'            => 'active',
            'currency_code'     => 'KY',
            'available_balance' => 10000,
        ]);

        return $user;
    }

    /** Una rotta qualsiasi protetta da `step.up`. */
    private function protectedRoute(): string
    {
        return route('portal.api-tokens.store');
    }

    private function payload(): array
    {
        return ['name' => 'Token ' . uniqid(), 'abilities' => ['read']];
    }

    // ─── La finestra ─────────────────────────────────────────────────────────

    public function test_step_up_valido_entro_i_15_minuti(): void
    {
        $user = $this->makeUser();

        $response = $this->actingAs($user)
            ->withSession([RequireStepUp::SESSION_KEY => now()->subMinutes(14)->getTimestamp()])
            ->post($this->protectedRoute(), $this->payload());

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
        $this->assertDatabaseCount('api_tokens', 1);
    }

    /** Il cuore del bug: dopo 16 minuti si deve riconfermare l'identità. */
    public function test_step_up_scaduto_dopo_i_15_minuti(): void
    {
        $user = $this->makeUser();

        $response = $this->actingAs($user)
            ->withSession([RequireStepUp::SESSION_KEY => now()->subMinutes(16)->getTimestamp()])
            ->post($this->protectedRoute(), $this->payload());

        $response->assertRedirect(route('portal.step-up.show'));
        $this->assertDatabaseCount('api_tokens', 0);
    }

    public function test_senza_step_up_si_viene_rimandati_alla_conferma(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)
            ->post($this->protectedRoute(), $this->payload())
            ->assertRedirect(route('portal.step-up.show'));

        $this->assertDatabaseCount('api_tokens', 0);
    }

    /**
     * Sessione aperta PRIMA del fix: contiene un oggetto Carbon. Deve valere
     * "non verificato" — al massimo si riconferma l'identità una volta.
     */
    public function test_valore_legacy_carbon_in_sessione_non_vale(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)
            ->withSession([RequireStepUp::SESSION_KEY => now()])
            ->post($this->protectedRoute(), $this->payload())
            ->assertRedirect(route('portal.step-up.show'));

        $this->assertDatabaseCount('api_tokens', 0);
    }

    // ─── Cosa viene scritto in sessione ──────────────────────────────────────

    /** Il controller deve scrivere un INTERO, non l'oggetto Carbon di now(). */
    public function test_la_conferma_identita_salva_un_timestamp_intero(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)
            ->post(route('portal.step-up.verify'), ['password' => 'password-corretta'])
            ->assertRedirect();

        $salvato = session(RequireStepUp::SESSION_KEY);

        $this->assertIsInt($salvato, 'In sessione deve finire un timestamp intero: un oggetto Carbon viene riletto come 1970.');
        $this->assertEqualsWithDelta(now()->getTimestamp(), $salvato, 5);
    }

    public function test_password_sbagliata_non_segna_la_sessione(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)
            ->post(route('portal.step-up.verify'), ['password' => 'password-sbagliata'])
            ->assertSessionHasErrors('credential');

        $this->assertNull(session(RequireStepUp::SESSION_KEY));
    }

    // ─── La regola, senza passare dalle rotte ────────────────────────────────

    public function test_is_within_window_accetta_solo_timestamp_recenti(): void
    {
        $this->assertTrue(RequireStepUp::isWithinWindow(now()->subMinutes(14)->getTimestamp()));
        $this->assertTrue(RequireStepUp::isWithinWindow((string) now()->subMinute()->getTimestamp()));

        $this->assertFalse(RequireStepUp::isWithinWindow(now()->subMinutes(16)->getTimestamp()));
        $this->assertFalse(RequireStepUp::isWithinWindow(null));
        $this->assertFalse(RequireStepUp::isWithinWindow(''));
        $this->assertFalse(RequireStepUp::isWithinWindow(now()));                       // oggetto Carbon
        $this->assertFalse(RequireStepUp::isWithinWindow(now()->toDateTimeString()));   // stringa data
        $this->assertFalse(RequireStepUp::isWithinWindow(now()->addHour()->getTimestamp())); // futuro
    }

    /**
     * Guardia contro la ricomparsa del bug: la finestra si legge e si scrive SOLO
     * in RequireStepUp. Era la copia in tre punti (middleware + due in
     * PortalController) a tenerla in vita.
     */
    public function test_la_finestra_step_up_vive_in_un_solo_file(): void
    {
        $trovati = [];

        $iter = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path()));
        foreach ($iter as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $path = $file->getPathname();
            if (str_ends_with($path, 'RequireStepUp.php')) {
                continue;
            }
            if (str_contains((string) file_get_contents($path), 'step_up_verified_at')) {
                $trovati[] = $path;
            }
        }

        $this->assertSame([], $trovati, 'Usare RequireStepUp::markVerified()/isVerified() invece di rileggere la chiave di sessione a mano.');
    }
}
