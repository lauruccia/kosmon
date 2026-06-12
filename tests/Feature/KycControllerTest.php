<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\KycDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class KycControllerTest extends TestCase
{
    use RefreshDatabase;

    private function makeAdmin(): User
    {
        $user = User::create([
            'name'                => 'Admin KYC',
            'email'               => 'admin-kyc-' . Str::random(6) . '@test.test',
            'password'            => 'secret123',
            'account_holder_type' => 'private',
            'company_id'          => null,
            'is_active'           => true,
            'is_super_admin'      => true,
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();
        return $user;
    }

    private function makeCompanyWithUser(string $kycStatus = 'pending'): array
    {
        $slug = 'kyc-co-' . Str::random(4);

        $company = Company::create([
            'name'          => 'KYC Company',
            'slug'          => $slug,
            'email'         => $slug . '@test.test',
            'status'        => 'active',
            'kyc_status'    => $kycStatus,
            'currency_code' => 'KY',
            'sector'        => 'informatica',
            'description'   => 'Test company for KYC',
        ]);

        $user = User::create([
            'company_id'          => $company->id,
            'account_holder_type' => 'company',
            'name'                => 'KYC User',
            'email'               => 'kycuser-' . Str::random(6) . '@test.test',
            'password'            => 'secret123',
            'role'                => 'company-manager',
            'is_active'           => true,
            'is_super_admin'      => false,
        ]);
        $user->forceFill([
            'email_verified_at'  => now(),
            'contract_signed_at' => now(),
        ])->save();

        Account::create([
            'company_id'        => $company->id,
            'owner_user_id'     => $user->id,
            'owner_type'        => 'company',
            'type'              => 'primary',
            'account_name'      => 'Conto KYC',
            'currency_code'     => 'KY',
            'status'            => 'active',
            'available_balance' => 0,
        ]);

        return [$user, $company];
    }

    // ── Portal ────────────────────────────────────────────────────────────────

    public function test_company_user_can_view_kyc_page(): void
    {
        [$user] = $this->makeCompanyWithUser('approved');

        $this->actingAs($user)
            ->get(route('portal.kyc'))
            ->assertOk()
            ->assertSee('KYC', false);
    }

    public function test_kyc_page_requires_authentication(): void
    {
        $this->get(route('portal.kyc'))
            ->assertRedirect(route('login'));
    }

    // ── Admin ─────────────────────────────────────────────────────────────────

    public function test_admin_can_view_kyc_list(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->get(route('admin.kyc.index'))
            ->assertOk();
    }

    public function test_admin_can_view_kyc_details_for_company(): void
    {
        $admin              = $this->makeAdmin();
        [, $company] = $this->makeCompanyWithUser('pending');

        $this->actingAs($admin)
            ->get(route('admin.kyc.show', $company))
            ->assertOk();
    }

    public function test_admin_can_approve_company_kyc(): void
    {
        $admin              = $this->makeAdmin();
        [, $company] = $this->makeCompanyWithUser('pending');

        $this->actingAs($admin)
            ->post(route('admin.kyc.approve', $company))
            ->assertRedirect();

        $this->assertSame('approved', $company->fresh()->kyc_status);
    }

    public function test_admin_can_reject_company_kyc(): void
    {
        $admin              = $this->makeAdmin();
        [, $company] = $this->makeCompanyWithUser('pending');

        $this->actingAs($admin)
            ->post(route('admin.kyc.reject', $company), [
                'notes' => 'Documenti non validi sono stati presentati.',
            ])
            ->assertRedirect();

        $this->assertSame('rejected', $company->fresh()->kyc_status);
    }

    public function test_non_admin_cannot_approve_kyc(): void
    {
        [$user]             = $this->makeCompanyWithUser('approved');
        [, $company] = $this->makeCompanyWithUser('pending');

        $this->actingAs($user)
            ->post(route('admin.kyc.approve', $company))
            ->assertForbidden();
    }
}
