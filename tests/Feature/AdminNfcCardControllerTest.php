<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\NfcCard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminNfcCardControllerTest extends TestCase
{
    use RefreshDatabase;

    private function makeAdmin(): User
    {
        $user = User::create([
            'name'                => 'Admin NFC',
            'email'               => 'admin-nfc-' . Str::random(6) . '@test.test',
            'password'            => 'secret123',
            'account_holder_type' => 'private',
            'company_id'          => null,
            'is_active'           => true,
            'is_super_admin'      => true,
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();
        return $user;
    }

    private function makeCompany(): Company
    {
        return Company::create([
            'name'          => 'NFC Company ' . Str::random(3),
            'slug'          => 'nfc-co-' . Str::random(4),
            'email'         => 'nfc@test.test',
            'status'        => 'active',
            'kyc_status'    => 'approved',
            'currency_code' => 'KY',
        ]);
    }

    public function test_index_requires_authentication(): void
    {
        $this->get(route('admin.nfc-cards.index'))
            ->assertRedirect(route('login'));
    }

    public function test_admin_can_view_nfc_cards_index(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->get(route('admin.nfc-cards.index'))
            ->assertOk()
            ->assertSee('Card NFC', false);
    }

    public function test_admin_can_view_create_nfc_form(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->get(route('admin.nfc-cards.create'))
            ->assertOk();
    }

    public function test_admin_can_create_nfc_card(): void
    {
        $admin   = $this->makeAdmin();
        $company = $this->makeCompany();

        $this->actingAs($admin)
            ->post(route('admin.nfc-cards.store'), [
                'participant' => 'company:' . $company->id,
                'notes'       => 'Carta per il titolare',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('nfc_cards', [
            'company_id'    => $company->id,
            'owner_user_id' => null,
            'status'        => 'pending',
        ]);
    }

    public function test_admin_can_create_nfc_card_for_private_user(): void
    {
        $admin = $this->makeAdmin();

        $privateUser = User::create([
            'name'                => 'Mario Rossi',
            'email'               => 'mario-' . Str::random(6) . '@test.test',
            'password'            => 'secret123',
            'account_holder_type' => 'private',
            'company_id'          => null,
            'is_active'           => true,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.nfc-cards.store'), [
                'participant' => 'user:' . $privateUser->id,
                'notes'       => 'Carta per privato',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('nfc_cards', [
            'company_id'    => null,
            'owner_user_id' => $privateUser->id,
            'status'        => 'pending',
        ]);
    }

    public function test_store_validates_participant_required(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->post(route('admin.nfc-cards.store'), [])
            ->assertSessionHasErrors('participant');
    }

    public function test_admin_can_view_nfc_card_detail(): void
    {
        $admin   = $this->makeAdmin();
        $company = $this->makeCompany();

        $card = NfcCard::create([
            'company_id'    => $company->id,
            'issued_by'     => $admin->id,
            'serial_number' => NfcCard::generateSerial(),
            'status'        => 'pending',
            'nfc_payload'   => NfcCard::buildPayload(Str::uuid()),
        ]);

        $this->actingAs($admin)
            ->get(route('admin.nfc-cards.show', $card))
            ->assertOk();
    }
}
