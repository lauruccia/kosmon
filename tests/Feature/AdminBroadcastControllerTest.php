<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminBroadcastControllerTest extends TestCase
{
    use RefreshDatabase;

    private function makeAdmin(): User
    {
        $user = User::create([
            'name'                => 'Admin Broadcast',
            'email'               => 'admin-bc-' . Str::random(6) . '@test.test',
            'password'            => 'secret123',
            'account_holder_type' => 'private',
            'company_id'          => null,
            'is_active'           => true,
            'is_super_admin'      => true,
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();
        return $user;
    }

    public function test_index_requires_authentication(): void
    {
        $this->get(route('admin.broadcast.index'))
            ->assertRedirect(route('login'));
    }

    public function test_admin_can_view_broadcast_page(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->get(route('admin.broadcast.index'))
            ->assertOk()
            ->assertSee('Comunicazione', false);
    }

    public function test_preview_returns_count_and_companies(): void
    {
        $admin = $this->makeAdmin();

        Company::create([
            'name'          => 'Test Azienda',
            'slug'          => 'test-az',
            'status'        => 'active',
            'kyc_status'    => 'approved',
            'currency_code' => 'KY',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.broadcast.preview', ['segment' => 'kyc_approved']))
            ->assertOk()
            ->assertJsonStructure(['count', 'preview']);
    }

    public function test_admin_can_send_broadcast(): void
    {
        Queue::fake();

        $admin = $this->makeAdmin();

        Company::create([
            'name'          => 'Az Broadcast',
            'slug'          => 'az-broadcast',
            'status'        => 'active',
            'kyc_status'    => 'approved',
            'currency_code' => 'KY',
        ]);

        $this->actingAs($admin)
            ->post(route('admin.broadcast.send'), [
                'segment'  => 'all',
                'subject'  => 'Comunicazione importante',
                'body'     => 'Testo della comunicazione di test per tutti gli utenti.',
                'channels' => ['in_app'],
            ])
            ->assertRedirect(route('admin.broadcast.index'));

        Queue::assertPushed(\App\Jobs\SendBroadcastMessageJob::class);
    }

    public function test_send_validates_required_fields(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->post(route('admin.broadcast.send'), [])
            ->assertSessionHasErrors(['segment', 'subject', 'body', 'channels']);
    }
}
