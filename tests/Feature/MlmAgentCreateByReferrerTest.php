<?php

namespace Tests\Feature;

use App\Models\MlmAgentContractSignature;
use App\Models\User;
use App\Notifications\MlmAgentCreatedByReferrerNotification;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Copre il flusso "un agente registra un nuovo agente sotto di sé" con i
 * dati anagrafici completi per il contratto di nomina (2026-07-31, richiesta
 * di Laura: "vorrei che inserisse per l'agente che deve registrare tutti i
 * dati utili al contratto ... il contratto allegato già compilato da firmare
 * con OTP prima di iniziare a lavorare come agente").
 *
 * Vedi MlmPortalController::registraAgenteStore(),
 * SystemSetting::renderAgentContractText()/defaultAgentContractText(),
 * MlmAgentCreatedByReferrerNotification, MlmAgentContractController::sign().
 */
class MlmAgentCreateByReferrerTest extends TestCase
{
    use RefreshDatabase;

    private function makeAgent(string $name = 'Agente Sponsor'): User
    {
        $user = User::create([
            'name'               => $name,
            'email'              => 'agente-' . Str::random(10) . '@test.test',
            'password'           => 'secret123',
            'account_holder_type' => 'private',
            'is_active'          => true,
            'mlm_role'           => 'agente',
            'mlm_activated_at'   => now(),
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();
        $user->agentCode();

        return $user;
    }

    private function fullPayload(array $overrides = []): array
    {
        return array_merge([
            'name'  => 'Luigi Nuovo Agente',
            'email' => 'luigi-' . Str::random(8) . '@test.test',
            'phone' => '333 1234567',
            'fiscal_code' => 'RSSMRA85M01H501Z',
            'birth_date' => '1985-08-01',
            'birth_place' => 'Roma',
            'residence_address' => 'Via Roma 10',
            'residence_zip' => '00100',
            'residence_city' => 'Roma',
            'residence_province' => 'rm',
        ], $overrides);
    }

    public function test_a_non_agent_cannot_access_the_registration_form(): void
    {
        Notification::fake();
        $client = User::create([
            'name' => 'Cliente', 'email' => 'cliente-' . Str::random(8) . '@test.test',
            'password' => 'secret123', 'account_holder_type' => 'private', 'is_active' => true,
            'mlm_role' => 'cliente',
        ]);
        $client->forceFill(['email_verified_at' => now()])->save();

        $response = $this->actingAsWithSession($client)->get(route('portal.mlm.agent-create.show'));

        $response->assertForbidden();
    }

    public function test_agent_can_register_a_new_agent_with_full_contract_data(): void
    {
        Notification::fake();
        $this->seed(RolesAndPermissionsSeeder::class);
        $sponsor = $this->makeAgent();

        $response = $this->actingAsWithSession($sponsor)
            ->post(route('portal.mlm.agent-create.store'), $this->fullPayload());

        $response->assertRedirect(route('portal.mlm.struttura'));

        $newAgent = User::where('name', 'Luigi Nuovo Agente')->firstOrFail();
        $this->assertSame('RSSMRA85M01H501Z', $newAgent->fiscal_code);
        $this->assertSame('1985-08-01', $newAgent->birth_date->toDateString());
        $this->assertSame('Roma', $newAgent->birth_place);
        $this->assertSame('Via Roma 10', $newAgent->residence_address);
        $this->assertSame('00100', $newAgent->residence_zip);
        $this->assertSame('Roma', $newAgent->residence_city);
        $this->assertSame('RM', $newAgent->residence_province); // normalizzato in maiuscolo
        $this->assertSame('cliente', $newAgent->mlm_role); // diventa 'agente' solo dopo la firma OTP
        $this->assertSame($sponsor->id, $newAgent->referred_by_user_id);
        $this->assertTrue($newAgent->mlmAgentAwaitingContract());

        Notification::assertSentTo($newAgent, MlmAgentCreatedByReferrerNotification::class, function ($notification) use ($newAgent) {
            $mail = $notification->toMail($newAgent);
            $this->assertNotEmpty($mail->rawAttachments, 'Il contratto compilato deve arrivare in allegato PDF.');
            $this->assertSame('contratto-nomina-agente-knm.pdf', $mail->rawAttachments[0]['name']);
            $this->assertStringStartsWith('%PDF', $mail->rawAttachments[0]['data'], 'L\'allegato deve essere un PDF valido.');

            return true;
        });
    }

    public function test_registration_requires_the_full_legal_data_set(): void
    {
        Notification::fake();
        $sponsor = $this->makeAgent();

        $response = $this->actingAsWithSession($sponsor)->post(route('portal.mlm.agent-create.store'), [
            'name'  => 'Senza Dati',
            'email' => 'senzadati-' . Str::random(8) . '@test.test',
        ]);

        $response->assertSessionHasErrors([
            'fiscal_code', 'birth_date', 'birth_place',
            'residence_address', 'residence_zip', 'residence_city', 'residence_province',
        ]);
        $this->assertFalse(User::where('name', 'Senza Dati')->exists());
    }

    public function test_new_agent_must_be_at_least_18_years_old(): void
    {
        Notification::fake();
        $sponsor = $this->makeAgent();

        $response = $this->actingAsWithSession($sponsor)->post(
            route('portal.mlm.agent-create.store'),
            $this->fullPayload(['birth_date' => now()->subYears(10)->toDateString()])
        );

        $response->assertSessionHasErrors('birth_date');
    }

    public function test_fiscal_code_must_be_unique(): void
    {
        Notification::fake();
        $this->seed(RolesAndPermissionsSeeder::class);
        $sponsor = $this->makeAgent();
        $this->actingAsWithSession($sponsor)->post(route('portal.mlm.agent-create.store'), $this->fullPayload());

        $response = $this->actingAsWithSession($sponsor)->post(
            route('portal.mlm.agent-create.store'),
            $this->fullPayload(['email' => 'altra-' . Str::random(8) . '@test.test'])
        );

        $response->assertSessionHasErrors('fiscal_code');
    }

    public function test_compiled_contract_renders_the_new_agent_and_sponsor_data(): void
    {
        Notification::fake();
        $this->seed(RolesAndPermissionsSeeder::class);
        $sponsor = $this->makeAgent('Mario Sponsor');
        $this->actingAsWithSession($sponsor)->post(route('portal.mlm.agent-create.store'), $this->fullPayload());

        $newAgent = User::where('name', 'Luigi Nuovo Agente')->firstOrFail();

        $response = $this->actingAsWithSession($newAgent)->get(route('portal.mlm.agent-contract.show'));

        $response->assertOk();
        $response->assertSee('Luigi Nuovo Agente');
        $response->assertSee('RSSMRA85M01H501Z');
        $response->assertSee('Via Roma 10', false);
        $response->assertSee('Mario Sponsor');
        $response->assertSee($sponsor->mlm_agent_code);
        // Il testo del contratto è renderizzato "raw" (Blade {!! !!}, non
        // escaped): evitiamo di includere l'apostrofo tipografico nella
        // stringa attesa per non dipendere dalla codifica esatta usata nel
        // testo sorgente (vedi SystemSetting::defaultAgentContractText()).
        $response->assertSee('CONDIZIONI GENERALI PER', false);
        $response->assertSee('INCARICATO DI VENDITA', false);
    }

    public function test_signing_the_compiled_contract_activates_the_agent_and_freezes_the_signer_snapshot(): void
    {
        Notification::fake();
        $this->seed(RolesAndPermissionsSeeder::class);
        $sponsor = $this->makeAgent('Mario Sponsor');
        $this->actingAsWithSession($sponsor)->post(route('portal.mlm.agent-create.store'), $this->fullPayload());
        $newAgent = User::where('name', 'Luigi Nuovo Agente')->firstOrFail();

        $this->actingAsWithSession($newAgent)->post(route('portal.mlm.agent-contract.send-otp'));
        $otp = $newAgent->fresh()->mlm_agent_contract_otp;
        $this->assertNotNull($otp);

        $response = $this->actingAsWithSession($newAgent->fresh())
            ->post(route('portal.mlm.agent-contract.sign'), ['otp' => $otp]);

        $response->assertRedirect(route('portal.mlm.struttura'));

        $fresh = $newAgent->fresh();
        $this->assertTrue($fresh->isMlmAgent());
        $this->assertNotNull($fresh->mlm_agent_contract_signed_at);

        $signature = MlmAgentContractSignature::where('user_id', $fresh->id)->firstOrFail();
        $this->assertSame('RSSMRA85M01H501Z', $signature->signer_data_snapshot['fiscal_code']);
        $this->assertSame('1985-08-01', $signature->signer_data_snapshot['birth_date']);
        $this->assertSame('Roma', $signature->signer_data_snapshot['residence_city']);
        $this->assertSame('Mario Sponsor', $signature->signer_data_snapshot['sponsor_name']);
        $this->assertSame($sponsor->mlm_agent_code, $signature->signer_data_snapshot['sponsor_agent_code']);
    }
}
