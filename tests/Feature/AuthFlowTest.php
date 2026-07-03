<?php

namespace Tests\Feature;

use Database\Seeders\RolesAndPermissionsSeeder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AuthFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_page_loads(): void
    {
        $response = $this->get('/login');

        $response->assertOk()->assertSee('Accedi a KMoney', false);
    }

    public function test_user_can_login_with_seeded_company_credentials(): void
    {
        $this->seed();

        $response = $this->post('/login', [
            'email' => 'operatore-panificio-canale@kmoney.test',
            'password' => 'secret123',
        ]);

        $response->assertRedirect('/dashboard');
        $this->assertAuthenticated();
    }

    public function test_superadmin_is_sent_to_backoffice_after_login(): void
    {
        $this->seed();

        $response = $this->post('/login', [
            'email' => 'superadmin@kmoney.test',
            'password' => 'secret123',
        ]);

        $response->assertRedirect('/admin');
        $this->assertAuthenticated();
    }

    public function test_private_user_can_register_and_open_account(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $response = $this->post('/register', [
            'account_holder_type' => 'private',
            'name' => 'Luca Serra',
            'email' => 'luca.serra@example.test',
            'phone' => '333000111',
            'fiscal_code' => 'SRRLCU80A01H501X',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ]);

        $response->assertRedirect('/dashboard');
        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', ['email' => 'luca.serra@example.test', 'account_holder_type' => 'private']);
        $this->assertDatabaseHas('accounts', ['owner_type' => 'private', 'account_name' => 'Conto personale Luca Serra']);

        $user = User::where('email', 'luca.serra@example.test')->firstOrFail();
        $this->assertTrue($user->roles()->where('slug', 'private-member')->exists());
        $this->assertTrue($user->hasPermission('payments.send'));
        $this->assertTrue($user->hasPermission('payments.receive'));
    }

    public function test_company_user_can_register_and_company_is_listed(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $response = $this->post('/register', [
            'account_holder_type' => 'company',
            'name' => 'Giulia Riva',
            'email' => 'giulia.riva@example.test',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
            'company_name' => 'Riva Lab SRL',
            'vat_number' => 'IT12345678901',
            'company_email' => 'info@rivalab.test',
        ]);

        $response->assertRedirect('/dashboard');
        $this->assertDatabaseHas('companies', ['name' => 'Riva Lab SRL']);
        $this->assertDatabaseHas('accounts', ['owner_type' => 'company', 'account_name' => 'Conto principale Riva Lab SRL']);

        $user = User::where('email', 'giulia.riva@example.test')->firstOrFail();
        $this->assertTrue($user->roles()->where('slug', 'company-member')->exists());
        $this->assertTrue($user->hasPermission('payments.send'));
        $this->assertTrue($user->hasPermission('payments.receive'));
        $this->assertFalse($user->hasPermission('announcements.publish'));
        $this->assertFalse($user->hasPermission('marketplace.buy'));
    }

    public function test_login_with_legacy_non_bcrypt_hash_shows_reset_message_instead_of_500(): void
    {
        // Simula un account importato dal vecchio kosmomoney: la colonna password
        // contiene un hash crypt() valido (qui SHA-512-crypt) ma non Bcrypt. In fase di
        // import Hash::isHashed() lo riconosce come gia' hashato e lo salva cosi' com'e'
        // (bypassando il cast 'hashed' con un update diretto, come fa l'import via SQL).
        $user = User::factory()->create([
            'email' => 'legacy@example.test',
            'is_active' => true,
        ]);

        DB::table('users')->where('id', $user->id)->update([
            'password' => crypt('secret123', '$6$rounds=5000$legacysalt$'),
        ]);

        $response = $this->post('/login', [
            'email' => 'legacy@example.test',
            'password' => 'secret123',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }
}
