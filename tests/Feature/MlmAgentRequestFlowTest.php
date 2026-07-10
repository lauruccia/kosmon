<?php

namespace Tests\Feature;

use App\Models\MlmAgentClosure;
use App\Models\MlmAgentContractSignature;
use App\Models\User;
use App\Notifications\MlmAgentActivatedNotification;
use App\Notifications\MlmAgentContractOtpNotification;
use App\Notifications\MlmAgentRequestReviewedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Copre il flusso end-to-end "diventa agente KNM": richiesta utente ->
 * approvazione (o rifiuto) admin -> firma OTP del contratto di nomina ->
 * mlm_role passa ad 'agente' SOLO dopo la firma. Copre anche la promozione
 * diretta dell'admin senza richiesta previa.
 *
 * Vedi MlmAgentRequestController, Admin\MlmAgentRequestController,
 * MlmAgentContractController e [[project_agent_program_flow]] in memoria.
 */
class MlmAgentRequestFlowTest extends TestCase
{
    use RefreshDatabase;

    private function makeClient(): User
    {
        $user = User::create([
            'name'                => 'Cliente ' . Str::random(6),
            'email'                => 'cliente-' . Str::random(10) . '@test.test',
            'password'             => 'secret123',
            'account_holder_type'  => 'private',
            'company_id'           => null,
            'is_active'            => true,
            'mlm_role'             => 'cliente',
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();

        return $user;
    }

    private function makeAdmin(): User
    {
        $user = User::create([
            'name'                => 'Admin',
            'email'                => 'admin-' . Str::random(10) . '@test.test',
            'password'             => 'secret123',
            'account_holder_type'  => 'private',
            'company_id'           => null,
            'is_active'            => true,
            'is_super_admin'       => true,
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();

        return $user;
    }

    public function test_user_can_submit_an_agent_request(): void
    {
        Notification::fake();
        $user = $this->makeClient();

        $response = $this->actingAsWithSession($user)
            ->post(route('portal.mlm.agent-request.store'), ['note' => 'Vorrei entrare nel programma agenti.']);

        $response->assertRedirect(route('portal.mlm.agent-request.show'));

        $fresh = $user->fresh();
        $this->assertSame('pending', $fresh->mlm_agent_request_status);
        $this->assertNotNull($fresh->mlm_agent_requested_at);
        $this->assertTrue($fresh->hasPendingMlmAgentRequest());
    }

    public function test_user_cannot_submit_a_second_request_while_pending(): void
    {
        Notification::fake();
        $user = $this->makeClient();
        $this->actingAsWithSession($user)->post(route('portal.mlm.agent-request.store'));

        $response = $this->actingAsWithSession($user->fresh())
            ->post(route('portal.mlm.agent-request.store'));

        $response->assertForbidden();
    }

    public function test_admin_can_approve_a_pending_request(): void
    {
        Notification::fake();
        $user = $this->makeClient();
        $admin = $this->makeAdmin();
        $this->actingAsWithSession($user)->post(route('portal.mlm.agent-request.store'));

        $response = $this->actingAsWithSession($admin)
            ->post(route('admin.mlm.requests.approve', $user->fresh()));

        $response->assertRedirect();
        $fresh = $user->fresh();
        $this->assertSame('approved', $fresh->mlm_agent_request_status);
        $this->assertSame($admin->id, $fresh->mlm_agent_reviewed_by);
        $this->assertTrue($fresh->mlmAgentAwaitingContract());
        Notification::assertSentTo($user->fresh(), MlmAgentRequestReviewedNotification::class);
    }

    public function test_admin_can_reject_a_pending_request_with_a_reason(): void
    {
        Notification::fake();
        $user = $this->makeClient();
        $admin = $this->makeAdmin();
        $this->actingAsWithSession($user)->post(route('portal.mlm.agent-request.store'));

        $response = $this->actingAsWithSession($admin)
            ->post(route('admin.mlm.requests.reject', $user->fresh()), ['reason' => 'Profilo non idoneo al momento.']);

        $response->assertRedirect();
        $fresh = $user->fresh();
        $this->assertSame('rejected', $fresh->mlm_agent_request_status);
        $this->assertTrue($fresh->hasRejectedMlmAgentRequest());
        // Dopo un rifiuto l'utente puo' ripresentare la richiesta.
        $this->assertTrue($fresh->canRequestMlmAgent());
    }

    public function test_admin_can_promote_a_user_directly_without_a_prior_request(): void
    {
        Notification::fake();
        $user = $this->makeClient();
        $admin = $this->makeAdmin();

        $response = $this->actingAsWithSession($admin)
            ->post(route('admin.mlm.requests.promote', $user));

        $response->assertRedirect();
        $fresh = $user->fresh();
        $this->assertSame('approved', $fresh->mlm_agent_request_status);
        $this->assertTrue($fresh->mlmAgentAwaitingContract());
    }

    public function test_signing_the_contract_activates_the_agent_and_attaches_the_tree(): void
    {
        Notification::fake();
        $user = $this->makeClient();
        $admin = $this->makeAdmin();
        $this->actingAsWithSession($admin)->post(route('admin.mlm.requests.promote', $user));

        $this->actingAsWithSession($user->fresh())->post(route('portal.mlm.agent-contract.send-otp'));
        $otp = $user->fresh()->mlm_agent_contract_otp;
        $this->assertNotNull($otp);

        $response = $this->actingAsWithSession($user->fresh())
            ->post(route('portal.mlm.agent-contract.sign'), ['otp' => $otp]);

        $response->assertRedirect(route('portal.mlm.struttura'));

        $fresh = $user->fresh();
        $this->assertTrue($fresh->isMlmAgent());
        $this->assertNotNull($fresh->mlm_agent_contract_signed_at);
        $this->assertNull($fresh->mlm_agent_contract_otp);
        $this->assertNull($fresh->mlm_client_agent_id);

        $this->assertSame(1, MlmAgentContractSignature::where('user_id', $fresh->id)->count());
        // attachAgent crea almeno la riga self nella closure table.
        $this->assertTrue(MlmAgentClosure::where('ancestor_id', $fresh->id)->where('descendant_id', $fresh->id)->exists());

        Notification::assertSentTo($fresh, MlmAgentActivatedNotification::class);
    }

    public function test_signing_the_contract_with_a_wrong_otp_fails_and_does_not_activate(): void
    {
        Notification::fake();
        $user = $this->makeClient();
        $admin = $this->makeAdmin();
        $this->actingAsWithSession($admin)->post(route('admin.mlm.requests.promote', $user));
        $this->actingAsWithSession($user->fresh())->post(route('portal.mlm.agent-contract.send-otp'));

        $response = $this->actingAsWithSession($user->fresh())
            ->post(route('portal.mlm.agent-contract.sign'), ['otp' => '000000']);

        $response->assertSessionHasErrors('otp');
        $this->assertFalse($user->fresh()->isMlmAgent());
    }

    public function test_signing_the_contract_with_an_expired_otp_fails(): void
    {
        Notification::fake();
        $user = $this->makeClient();
        $admin = $this->makeAdmin();
        $this->actingAsWithSession($admin)->post(route('admin.mlm.requests.promote', $user));
        $this->actingAsWithSession($user->fresh())->post(route('portal.mlm.agent-contract.send-otp'));

        $expired = $user->fresh();
        $otp = $expired->mlm_agent_contract_otp;
        $expired->forceFill(['mlm_agent_contract_otp_expires_at' => now()->subMinute()])->save();

        $response = $this->actingAsWithSession($expired->fresh())
            ->post(route('portal.mlm.agent-contract.sign'), ['otp' => $otp]);

        $response->assertSessionHasErrors('otp');
        $this->assertFalse($user->fresh()->isMlmAgent());
    }

    public function test_a_user_that_is_already_an_agent_cannot_be_promoted_again(): void
    {
        Notification::fake();
        $user = $this->makeClient();
        $admin = $this->makeAdmin();
        $this->actingAsWithSession($admin)->post(route('admin.mlm.requests.promote', $user));
        $this->actingAsWithSession($user->fresh())->post(route('portal.mlm.agent-contract.send-otp'));
        $otp = $user->fresh()->mlm_agent_contract_otp;
        $this->actingAsWithSession($user->fresh())->post(route('portal.mlm.agent-contract.sign'), ['otp' => $otp]);

        $response = $this->actingAsWithSession($admin)
            ->post(route('admin.mlm.requests.promote', $user->fresh()));

        $response->assertStatus(422);
    }
}
