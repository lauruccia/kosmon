<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\SubAccountInvitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SubAccountInvitationControllerTest extends TestCase
{
    use RefreshDatabase;

    private function makeOwnerAndSubaccount(): array
    {
        $company = Company::create([
            'name'          => 'Invitation Co',
            'slug'          => 'inv-co-' . Str::random(4),
            'email'         => 'inv@test.test',
            'status'        => 'active',
            'kyc_status'    => 'approved',
            'currency_code' => 'KY',
        ]);

        $owner = User::create([
            'company_id'          => $company->id,
            'account_holder_type' => 'company',
            'name'                => 'Owner',
            'email'               => 'owner-inv-' . Str::random(6) . '@test.test',
            'password'            => 'secret123',
            'role'                => 'company-manager',
            'is_active'           => true,
            'is_super_admin'      => false,
        ]);
        $owner->forceFill(['email_verified_at' => now()])->save();

        $root = Account::create([
            'company_id'        => $company->id,
            'owner_user_id'     => $owner->id,
            'owner_type'        => 'company',
            'type'              => 'primary',
            'account_name'      => 'Root',
            'currency_code'     => 'KY',
            'status'            => 'active',
            'available_balance' => 0,
        ]);

        $sub = Account::create([
            'company_id'        => $company->id,
            'owner_user_id'     => $owner->id,
            'owner_type'        => 'company',
            'parent_account_id' => $root->id,
            'type'              => 'subaccount',
            'account_name'      => 'Sottoconto Test',
            'currency_code'     => 'KY',
            'status'            => 'active',
            'available_balance' => 0,
        ]);

        $invitation = SubAccountInvitation::create([
            'account_id' => $sub->id,
            'invited_by' => $owner->id,
            'email'      => 'newuser@test.test',
        ]);

        return [$owner, $root, $sub, $invitation];
    }

    public function test_invitation_show_page_is_publicly_accessible(): void
    {
        [, , , $invitation] = $this->makeOwnerAndSubaccount();

        $this->get(route('subaccount.invitation.show', $invitation->token))
            ->assertOk()
            ->assertSee('invito', false);
    }

    public function test_expired_invitation_returns_gone(): void
    {
        [, , , $invitation] = $this->makeOwnerAndSubaccount();
        $invitation->update(['expires_at' => now()->subDay()]);

        $this->get(route('subaccount.invitation.show', $invitation->token))
            ->assertStatus(410);
    }

    public function test_invalid_token_returns_404(): void
    {
        $this->get(route('subaccount.invitation.show', 'invalid-token-xyz'))
            ->assertNotFound();
    }

    public function test_register_form_is_accessible_for_valid_token(): void
    {
        [, , , $invitation] = $this->makeOwnerAndSubaccount();

        $this->get(route('subaccount.invitation.register', $invitation->token))
            ->assertOk();
    }
}
