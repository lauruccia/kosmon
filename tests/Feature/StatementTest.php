<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class StatementTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // Form
    // -------------------------------------------------------------------------

    public function test_user_can_view_statement_form(): void
    {
        [$user, $account] = $this->makeUserAndAccount();

        $this->actingAs($user)
            ->get(route('portal.statement'))
            ->assertOk();
    }

    public function test_statement_requires_authentication(): void
    {
        $this->get(route('portal.statement'))
            ->assertRedirect(route('login'));
    }

    public function test_admin_is_redirected_from_statement_form(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->get(route('portal.statement'))
            ->assertRedirect(route('admin.dashboard'));
    }

    // -------------------------------------------------------------------------
    // Download PDF
    // -------------------------------------------------------------------------

    public function test_user_can_download_statement_pdf(): void
    {
        [$user, $account] = $this->makeUserAndAccount();

        $response = $this->actingAs($user)
            ->get(route('portal.statement.download', ['mese' => now()->format('Y-m')]));

        // DomPDF restituisce application/pdf
        $response->assertOk();
        $this->assertStringContainsString('pdf', strtolower($response->headers->get('Content-Type') ?? ''));
    }

    public function test_pdf_download_requires_authentication(): void
    {
        $this->get(route('portal.statement.download'))
            ->assertRedirect(route('login'));
    }

    public function test_admin_is_redirected_from_pdf_download(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->get(route('portal.statement.download'))
            ->assertRedirect(route('admin.dashboard'));
    }

    // -------------------------------------------------------------------------
    // Admin download (qualsiasi conto)
    // -------------------------------------------------------------------------

    public function test_admin_can_download_any_account_statement(): void
    {
        $admin = $this->makeAdmin();
        [, $account] = $this->makeUserAndAccount();

        $response = $this->actingAs($admin)
            ->get(route('admin.accounts.statement', [
                'account' => $account->id,
                'mese'    => now()->format('Y-m'),
            ]));

        $response->assertOk();
        $this->assertStringContainsString('pdf', strtolower($response->headers->get('Content-Type') ?? ''));
    }

    public function test_non_admin_cannot_use_admin_statement_route(): void
    {
        [$user, $account] = $this->makeUserAndAccount();

        $this->actingAs($user)
            ->get(route('admin.accounts.statement', [
                'account' => $account->id,
                'mese'    => now()->format('Y-m'),
            ]))
            ->assertForbidden();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeUserAndAccount(): array
    {
        $user = User::create([
            'name'                => 'Statement User',
            'email'               => 'stmt-' . Str::random(8) . '@test.test',
            'password'            => 'secret123',
            'account_holder_type' => 'private',
            'company_id'          => null,
            'is_active'           => true,
            'is_super_admin'      => false,
            'email_verified_at'   => now(),
        ]);

        $account = Account::create([
            'owner_user_id'     => $user->id,
            'owner_type'        => 'private',
            'type'              => 'member',
            'status'            => 'active',
            'available_balance' => 0,
        ]);

        return [$user, $account];
    }

    private function makeAdmin(): User
    {
        return User::create([
            'name'                => 'Admin User',
            'email'               => 'admin-' . Str::random(8) . '@test.test',
            'password'            => 'secret123',
            'account_holder_type' => 'private',
            'company_id'          => null,
            'is_active'           => true,
            'is_super_admin'      => true,
            'email_verified_at'   => now(),
        ]);
    }
}
