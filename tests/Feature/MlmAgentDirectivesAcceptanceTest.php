<?php

namespace Tests\Feature;

use App\Models\MlmAgentContractSignature;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Copre l'accettazione delle "Direttive e Procedure Kosmos" (2026-08-07,
 * richiesta di Laura: oltre al contratto, l'agente deve accettare anche
 * questo secondo documento). Firmato con la STESSA firma OTP del contratto
 * di nomina, non un flusso separato — vedi MlmAgentContractController,
 * SystemSetting::defaultAgentDirectivesText().
 *
 * Segue lo stesso schema di setup di MlmAgentCreateByReferrerTest (uno
 * sponsor registra un nuovo agente con i dati anagrafici completi, poi il
 * nuovo agente firma il contratto).
 */
class MlmAgentDirectivesAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    private function makeAgent(string $name = 'Agente Sponsor'): User
    {
        $user = User::create([
            'name'                => $name,
            'email'               => 'agente-' . Str::random(10) . '@test.test',
            'password'            => 'secret123',
            'account_holder_type' => 'private',
            'is_active'           => true,
            'mlm_role'            => 'agente',
            'mlm_activated_at'    => now(),
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();
        $user->agentCode();

        return $user;
    }

    private function fullPayload(array $overrides = []): array
    {
        return array_merge([
            'name'               => 'Luigi Nuovo Agente',
            'email'              => 'luigi-' . Str::random(8) . '@test.test',
            'phone'              => '333 1234567',
            'fiscal_code'        => 'RSSMRA85M01H501Z',
            'birth_date'         => '1985-08-01',
            'birth_place'        => 'Roma',
            'residence_address'  => 'Via Roma 10',
            'residence_zip'      => '00100',
            'residence_city'     => 'Roma',
            'residence_province' => 'rm',
        ], $overrides);
    }

    private function makeNewAgentAwaitingContract(): User
    {
        Notification::fake();
        $this->seed(RolesAndPermissionsSeeder::class);
        $sponsor = $this->makeAgent('Mario Sponsor');
        $this->actingAsWithSession($sponsor)->post(route('portal.mlm.agent-create.store'), $this->fullPayload());

        return User::where('name', 'Luigi Nuovo Agente')->firstOrFail();
    }

    public function test_the_sign_page_shows_both_the_contract_and_the_directives(): void
    {
        $newAgent = $this->makeNewAgentAwaitingContract();

        $response = $this->actingAsWithSession($newAgent)->get(route('portal.mlm.agent-contract.show'));

        $response->assertOk();
        // Contratto (già coperto da MlmAgentCreateByReferrerTest, verifica minima qui)
        $response->assertSee('CONDIZIONI GENERALI PER', false);
        // Direttive e Procedure Kosmos: contenuto distintivo del documento
        $response->assertSee('Direttive e Procedure Kosmos', false);
        $response->assertSee('Reti di distribuzione multiple', false);
        $response->assertSee('Glossario dei termini', false);
    }

    public function test_signing_the_contract_also_freezes_the_directives_snapshot(): void
    {
        $newAgent = $this->makeNewAgentAwaitingContract();

        $this->actingAsWithSession($newAgent)->post(route('portal.mlm.agent-contract.send-otp'));
        $otp = $newAgent->fresh()->mlm_agent_contract_otp;
        $this->assertNotNull($otp);

        $response = $this->actingAsWithSession($newAgent->fresh())
            ->post(route('portal.mlm.agent-contract.sign'), ['otp' => $otp]);

        $response->assertRedirect(route('portal.mlm.struttura'));

        $signature = MlmAgentContractSignature::where('user_id', $newAgent->fresh()->id)->firstOrFail();
        $this->assertEquals(1, $signature->directives_version);
        $this->assertNotEmpty($signature->directives_html_snapshot);
        $this->assertStringContainsString('Reti di distribuzione multiple', $signature->directives_html_snapshot);
    }

    public function test_admin_default_directives_text_can_be_customized_and_reset(): void
    {
        $admin = User::create([
            'name'                => 'Admin Test',
            'email'               => 'admin-' . Str::random(8) . '@test.test',
            'password'            => 'secret123',
            'account_holder_type' => 'private',
            'is_active'           => true,
            'is_super_admin'      => true,
        ]);
        $admin->forceFill(['email_verified_at' => now()])->save();

        $custom = '<p>Testo direttive personalizzato di prova.</p>';
        $response = $this->actingAsWithSession($admin)->post(route('admin.agent-directives-text.update'), [
            'agent_directives_text' => $custom,
        ]);
        $response->assertRedirect();

        $settings = \App\Models\SystemSetting::agentContractSettings();
        $this->assertSame($custom, $settings->fresh()->mlm_agent_directives_text);
        $this->assertSame(2, $settings->fresh()->mlm_agent_directives_version);

        // Ripristina default
        $this->actingAsWithSession($admin)->get(route('admin.contract-settings') . '?default_agent_directives_text=1');
        $this->assertNull($settings->fresh()->mlm_agent_directives_text);
        $this->assertSame(1, $settings->fresh()->mlm_agent_directives_version);
    }
}
