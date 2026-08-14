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

    /**
     * @param bool $withContractData 2026-08-14: dal momento in cui i dati
     *        anagrafici del modulo di adesione sono obbligatori per firmare
     *        (User::missingAgentContractFields()), i test che arrivano fino
     *        alla firma partono da un cliente che li ha già; passare false
     *        per esercitare invece il blocco.
     */
    private function makeClient(bool $withContractData = true): User
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

        if ($withContractData) {
            $user->forceFill(self::contractData())->save();
        }

        return $user;
    }

    /** Anagrafica minima che il contratto di nomina deve stampare. */
    private static function contractData(array $overrides = []): array
    {
        return array_merge([
            'fiscal_code'        => strtoupper(Str::random(16)),
            'birth_date'         => '1985-08-01',
            'birth_place'        => 'Roma',
            'residence_address'  => 'Via Roma 10',
            'residence_zip'      => '00100',
            'residence_city'     => 'Roma',
            'residence_province' => 'RM',
        ], $overrides);
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

    // --- 2026-08-14: dati anagrafici obbligatori prima della firma ---
    // Richiesta di Laura: "quando un agente deve firmare il contratto
    // dovrebbe anche essere obbligato a compilare i campi mancanti dal
    // contratto". Chi arriva da richiesta/promozione non ha CF, nascita e
    // residenza (li raccoglie solo il form "Registra agente"): senza questo
    // blocco il modulo di adesione ex art. 19 D. Lgs. 114/98 veniva firmato
    // — e congelato nello snapshot — con le caselle vuote.

    private function promoteWithoutContractData(): User
    {
        Notification::fake();
        $user  = $this->makeClient(withContractData: false);
        $admin = $this->makeAdmin();
        $this->actingAsWithSession($admin)->post(route('admin.mlm.requests.promote', $user));

        return $user->fresh();
    }

    public function test_the_sign_page_asks_for_the_missing_contract_data(): void
    {
        $user = $this->promoteWithoutContractData();

        $this->assertNotSame([], $user->missingAgentContractFields());

        $response = $this->actingAsWithSession($user)->get(route('portal.mlm.agent-contract.show'));

        $response->assertOk();
        $response->assertSee('Completa i tuoi dati per il contratto', false);
        $response->assertSee('name="fiscal_code"', false);
        $response->assertSee('name="residence_province"', false);
        // La firma non deve essere proponibile finché mancano i dati.
        $response->assertDontSee('Invia codice OTP e firma', false);
    }

    public function test_otp_cannot_be_requested_while_contract_data_is_missing(): void
    {
        $user = $this->promoteWithoutContractData();

        $response = $this->actingAsWithSession($user)
            ->post(route('portal.mlm.agent-contract.send-otp'));

        $response->assertRedirect(route('portal.mlm.agent-contract.show'));
        $response->assertSessionHasErrors('general');
        $this->assertNull($user->fresh()->mlm_agent_contract_otp);
        Notification::assertNotSentTo($user->fresh(), MlmAgentContractOtpNotification::class);
    }

    public function test_signing_is_refused_while_contract_data_is_missing(): void
    {
        $user = $this->promoteWithoutContractData();
        // OTP forzato a mano: simula chi prova a postare la firma saltando la UI.
        $user->forceFill([
            'mlm_agent_contract_otp'            => '123456',
            'mlm_agent_contract_otp_expires_at' => now()->addMinutes(15),
        ])->save();

        $response = $this->actingAsWithSession($user->fresh())
            ->post(route('portal.mlm.agent-contract.sign'), ['otp' => '123456']);

        $response->assertRedirect(route('portal.mlm.agent-contract.show'));
        $response->assertSessionHasErrors('general');
        $this->assertFalse($user->fresh()->isMlmAgent());
        $this->assertSame(0, MlmAgentContractSignature::where('user_id', $user->id)->count());
    }

    public function test_the_user_can_fill_the_missing_data_and_then_sign(): void
    {
        $user = $this->promoteWithoutContractData();

        $this->actingAsWithSession($user)
            ->post(route('portal.mlm.agent-contract.data'), [
                'phone'              => '333 1234567',
                'fiscal_code'        => 'rssmra85m01h501z',
                'birth_date'         => '1985-08-01',
                'birth_place'        => 'Roma',
                'residence_address'  => 'Via Roma 10',
                'residence_zip'      => '00100',
                'residence_city'     => 'Roma',
                'residence_province' => 'rm',
            ])
            ->assertRedirect(route('portal.mlm.agent-contract.show'));

        $fresh = $user->fresh();
        // Normalizzazione come in registraAgenteStore().
        $this->assertSame('RSSMRA85M01H501Z', $fresh->fiscal_code);
        $this->assertSame('RM', $fresh->residence_province);
        $this->assertTrue($fresh->hasCompleteAgentContractData());

        $this->actingAsWithSession($fresh)->post(route('portal.mlm.agent-contract.send-otp'));
        $otp = $user->fresh()->mlm_agent_contract_otp;
        $this->assertNotNull($otp);

        $this->actingAsWithSession($user->fresh())
            ->post(route('portal.mlm.agent-contract.sign'), ['otp' => $otp])
            ->assertRedirect(route('portal.mlm.struttura'));

        $signature = MlmAgentContractSignature::where('user_id', $user->id)->firstOrFail();
        // I dati appena inseriti devono finire davvero nel documento firmato.
        $this->assertStringContainsString('RSSMRA85M01H501Z', $signature->contract_html_snapshot);
        $this->assertStringContainsString('Via Roma 10', $signature->contract_html_snapshot);
        $this->assertSame('RSSMRA85M01H501Z', $signature->signer_data_snapshot['fiscal_code']);
    }

    public function test_contract_data_is_validated_like_the_referrer_form(): void
    {
        $user = $this->promoteWithoutContractData();
        $base = [
            'fiscal_code'        => 'RSSMRA85M01H501Z',
            'birth_date'         => '1985-08-01',
            'birth_place'        => 'Roma',
            'residence_address'  => 'Via Roma 10',
            'residence_zip'      => '00100',
            'residence_city'     => 'Roma',
            'residence_province' => 'RM',
        ];

        // Codice fiscale non di 16 caratteri
        $this->actingAsWithSession($user)
            ->post(route('portal.mlm.agent-contract.data'), ['fiscal_code' => 'ABC123'] + $base)
            ->assertSessionHasErrors('fiscal_code');

        // Minorenne: art. 6 delle Condizioni Generali
        $this->actingAsWithSession($user)
            ->post(route('portal.mlm.agent-contract.data'), ['birth_date' => now()->subYears(17)->toDateString()] + $base)
            ->assertSessionHasErrors('birth_date');

        // Campi obbligatori mancanti
        $this->actingAsWithSession($user)
            ->post(route('portal.mlm.agent-contract.data'), [])
            ->assertSessionHasErrors(['fiscal_code', 'birth_date', 'birth_place', 'residence_address', 'residence_zip', 'residence_city', 'residence_province']);

        $this->assertFalse($user->fresh()->hasCompleteAgentContractData());
    }

    public function test_a_fiscal_code_already_used_by_another_user_is_refused(): void
    {
        $other = $this->makeClient();
        $other->forceFill(['fiscal_code' => 'RSSMRA85M01H501Z'])->save();

        $user = $this->promoteWithoutContractData();

        $this->actingAsWithSession($user)
            ->post(route('portal.mlm.agent-contract.data'), [
                'fiscal_code'        => 'RSSMRA85M01H501Z',
                'birth_date'         => '1985-08-01',
                'birth_place'        => 'Roma',
                'residence_address'  => 'Via Roma 10',
                'residence_zip'      => '00100',
                'residence_city'     => 'Roma',
                'residence_province' => 'RM',
            ])
            ->assertSessionHasErrors('fiscal_code');
    }

    public function test_saving_the_data_invalidates_a_previously_issued_otp(): void
    {
        $user = $this->promoteWithoutContractData();
        $user->forceFill([
            'mlm_agent_contract_otp'            => '123456',
            'mlm_agent_contract_otp_expires_at' => now()->addMinutes(15),
        ])->save();

        $this->actingAsWithSession($user->fresh())
            ->post(route('portal.mlm.agent-contract.data'), [
                'fiscal_code'        => 'RSSMRA85M01H501Z',
                'birth_date'         => '1985-08-01',
                'birth_place'        => 'Roma',
                'residence_address'  => 'Via Roma 10',
                'residence_zip'      => '00100',
                'residence_city'     => 'Roma',
                'residence_province' => 'RM',
            ]);

        $this->assertNull($user->fresh()->mlm_agent_contract_otp);
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
