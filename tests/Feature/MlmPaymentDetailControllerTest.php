<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\MlmPaymentDetail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Copre /mlm/dati-bancari (MlmPaymentDetailController). Dal 2026-07-13
 * (decisione di Laura): l'agente inserisce SOLO l'IBAN (e opzionalmente
 * BIC/banca) — l'intestatario e' sempre il nome di registrazione
 * dell'account, non un campo del form, e non c'e' piu' alcuna approvazione
 * admin: l'IBAN e' utilizzabile subito per le liquidazioni EUR. In
 * precedenza ogni cambio IBAN riportava verification_status a 'pending' in
 * attesa di verifica admin (mai implementata lato UI, vedi
 * [[incident_mlm_ricalcola_smtp_550_crash]] in memoria di progetto) — quel
 * flusso e' stato rimosso.
 */
class MlmPaymentDetailControllerTest extends TestCase
{
    use RefreshDatabase;

    private function makeAgent(string $name = 'Mario Rossi'): User
    {
        $user = User::create([
            'name'                => $name,
            'email'                => 'agente-' . Str::random(10) . '@test.test',
            'password'             => 'secret123',
            'account_holder_type'  => 'private',
            'company_id'           => null,
            'is_active'            => true,
            'mlm_role'             => 'agente',
            'mlm_rank'             => 'start',
            'mlm_activated_at'     => now(),
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();

        return $user;
    }

    private function stepUpSession(): array
    {
        return ['step_up_verified_at' => now()->timestamp];
    }

    private function validIban(): string
    {
        // IBAN italiano valido (checksum mod-97 corretto), usato in giro nella suite come esempio.
        return 'IT60X0542811101000000123456';
    }

    public function test_non_agent_cannot_access_the_form(): void
    {
        $user = $this->makeAgent();
        $user->forceFill(['mlm_role' => 'cliente'])->save();

        $response = $this->actingAsWithSession($user)->get(route('portal.mlm.payment-details.edit'));

        $response->assertForbidden();
    }

    public function test_agent_can_save_iban_without_admin_approval(): void
    {
        $agent = $this->makeAgent('Mario Rossi');

        $response = $this->actingAsWithSession($agent, $this->stepUpSession())
            ->post(route('portal.mlm.payment-details.update'), [
                'iban' => $this->validIban(),
            ]);

        $response->assertRedirect(route('portal.mlm.payment-details.edit'));

        $detail = MlmPaymentDetail::where('agent_user_id', $agent->id)->first();
        $this->assertNotNull($detail);
        $this->assertSame('IT60X0542811101000000123456', $detail->iban);
        // L'intestatario e' sempre il nome di registrazione, non un input del form.
        $this->assertSame('Mario Rossi', $detail->account_holder_name);
    }

    public function test_the_form_does_not_accept_a_different_account_holder_name(): void
    {
        $agent = $this->makeAgent('Mario Rossi');

        // Anche forzando il campo nella request (form rimosso dalla UI, ma
        // testiamo che il controller lo ignori comunque lato server).
        $this->actingAsWithSession($agent, $this->stepUpSession())
            ->post(route('portal.mlm.payment-details.update'), [
                'iban' => $this->validIban(),
                'account_holder_name' => 'Nome Diverso',
            ]);

        $detail = MlmPaymentDetail::where('agent_user_id', $agent->id)->first();
        $this->assertSame('Mario Rossi', $detail->account_holder_name);
    }

    public function test_invalid_iban_is_rejected(): void
    {
        $agent = $this->makeAgent();

        $response = $this->actingAsWithSession($agent, $this->stepUpSession())
            ->post(route('portal.mlm.payment-details.update'), [
                'iban' => 'NOT-AN-IBAN',
            ]);

        $response->assertSessionHasErrors('iban');
        $this->assertNull(MlmPaymentDetail::where('agent_user_id', $agent->id)->first());
    }

    public function test_updating_the_iban_does_not_require_reverification(): void
    {
        $agent = $this->makeAgent();

        $this->actingAsWithSession($agent, $this->stepUpSession())
            ->post(route('portal.mlm.payment-details.update'), ['iban' => $this->validIban()]);

        $response = $this->actingAsWithSession($agent, $this->stepUpSession())
            ->post(route('portal.mlm.payment-details.update'), ['iban' => 'IT07X0542811101000000654321']);

        $response->assertRedirect(route('portal.mlm.payment-details.edit'));
        $response->assertSessionHas('portal_success', 'Dati bancari salvati.');

        $detail = MlmPaymentDetail::where('agent_user_id', $agent->id)->first();
        $this->assertSame('IT07X0542811101000000654321', $detail->iban);
    }

    public function test_saving_iban_writes_an_audit_log(): void
    {
        $agent = $this->makeAgent();

        $this->actingAsWithSession($agent, $this->stepUpSession())
            ->post(route('portal.mlm.payment-details.update'), ['iban' => $this->validIban()]);

        $this->assertDatabaseHas('audit_logs', [
            'event'         => 'mlm.payment_details_updated',
            'actor_user_id' => $agent->id,
        ]);
        $this->assertSame(1, AuditLog::where('event', 'mlm.payment_details_updated')->count());
    }
}
