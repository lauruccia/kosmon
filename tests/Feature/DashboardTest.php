<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $response = $this->get('/dashboard');

        $response->assertRedirect('/login');
    }

    public function test_dashboard_page_loads_for_company_user(): void
    {
        $this->seed();
        $user = User::where('email', 'operatore-panificio-canale@kmoney.test')->firstOrFail();

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk()->assertSee('Conto KMoney', false)->assertSee('Panificio Canale', false);
    }

    public function test_companies_page_is_scoped_to_company_user(): void
    {
        $this->seed();
        $user = User::where('email', 'operatore-panificio-canale@kmoney.test')->firstOrFail();

        $response = $this->actingAs($user)->get('/aziende');

        $response
            ->assertOk()
            ->assertSee('Panificio Canale', false)
            ->assertSee('Azienda Agricola Selene', false)
            ->assertSee('Settori', false);
    }

    public function test_private_user_can_open_companies_directory(): void
    {
        $this->seed();
        $user = User::where('email', 'maria.ferri@kmoney.test')->firstOrFail();

        $response = $this->actingAs($user)->get('/aziende');

        $response
            ->assertOk()
            ->assertSee('Panificio Canale', false);
    }

    public function test_admin_can_open_companies_directory(): void
    {
        $this->seed();
        $admin = User::where('email', 'superadmin@kmoney.test')->firstOrFail();

        $response = $this->actingAs($admin)->get('/admin/companies');

        $response
            ->assertOk()
            ->assertSee('aziende trovate', false)
            ->assertSee('Panificio Canale', false);
    }

    public function test_superadmin_can_open_admin_dashboard(): void
    {
        $this->seed();
        $admin = User::where('email', 'superadmin@kmoney.test')->firstOrFail();

        $response = $this->actingAs($admin)->get('/admin');

        $response->assertOk()->assertSee('Superadmin KMoney', false)->assertSee('Backoffice centrale KMoney', false);
    }

    public function test_superadmin_is_redirected_away_from_portal_dashboard(): void
    {
        $this->seed();
        $admin = User::where('email', 'superadmin@kmoney.test')->firstOrFail();

        $response = $this->actingAs($admin)->get('/dashboard');

        $response->assertRedirect('/admin');
    }

    public function test_pay_page_loads_for_company_user_with_permission(): void
    {
        $this->seed();
        $user = User::where('email', 'operatore-panificio-canale@kmoney.test')->firstOrFail();

        $response = $this->actingAs($user)->get('/paga');

        $response->assertOk()->assertSee('Effettua un pagamento', false);
    }

    public function test_viewer_cannot_open_payment_page(): void
    {
        $this->seed();
        $viewer = User::where('email', 'viewer-panificio@kmoney.test')->firstOrFail();

        $response = $this->actingAs($viewer)->get('/paga');

        $response->assertForbidden();
    }

    public function test_movements_page_loads(): void
    {
        $this->seed();
        $user = User::where('email', 'operatore-panificio-canale@kmoney.test')->firstOrFail();

        $response = $this->actingAs($user)->get('/movimenti');

        $response->assertOk()->assertSee('Lista movimenti', false)->assertSee('Tutti i movimenti', false);
    }
}
