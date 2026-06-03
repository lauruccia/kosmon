<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HelpControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_help_page_is_publicly_accessible(): void
    {
        $this->get(route('help.index'))
            ->assertOk()
            ->assertSee('Assistenza', false);
    }

    public function test_contact_form_stores_support_message(): void
    {
        $this->post(route('help.contact'), [
            'name'    => 'Mario Rossi',
            'email'   => 'mario@test.test',
            'subject' => 'Problema con il pagamento',
            'body'    => 'Non riesco a completare il pagamento. Potete aiutarmi?',
        ])->assertRedirect();

        $this->assertDatabaseHas('support_messages', [
            'email'   => 'mario@test.test',
            'subject' => 'Problema con il pagamento',
        ]);
    }

    public function test_contact_form_validates_required_fields(): void
    {
        $this->post(route('help.contact'), [])
            ->assertSessionHasErrors(['name', 'email', 'subject', 'body']);
    }

    public function test_contact_form_validates_email_format(): void
    {
        $this->post(route('help.contact'), [
            'name'    => 'Test',
            'email'   => 'not-an-email',
            'subject' => 'Test',
            'body'    => 'Messaggio di test per assistenza.',
        ])->assertSessionHasErrors('email');
    }
}
