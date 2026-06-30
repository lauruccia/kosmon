<?php

namespace Tests\Feature;

use App\Http\Controllers\KyCardController;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\KyCard;
use App\Models\KyCardPurchase;
use App\Models\Transfer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Copre l'accredito KY delle KYCard (emissione dal conto madre verso l'utente):
 * correttezza dei saldi e doppia partita, idempotenza e gestione della corsa
 * webhook Stripe + pagina success (transfer gia' esistente).
 */
class KyCardCreditTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Il conto sistema (Cassa Circuito) è già creato da una migration:
     * recuperalo e impostane il saldo iniziale, invece di crearne un secondo
     * (Account::systemAccount() restituirebbe il primo e falserebbe i saldi).
     */
    private function makeSystemAccount(int $balance = 0): Account
    {
        $account = Account::systemAccount();
        $this->assertNotNull($account, 'Conto sistema non creato dalle migration.');
        $account->forceFill(['available_balance' => $balance])->save();
        return $account;
    }

    private function makeAdmin(): User
    {
        $user = User::create([
            'name'                => 'Admin KyCard',
            'email'               => 'admin-credit-' . Str::random(6) . '@test.test',
            'password'            => 'secret123',
            'account_holder_type' => 'private',
            'company_id'          => null,
            'is_active'           => true,
            'is_super_admin'      => true,
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();
        return $user;
    }

    /** @return array{0: User, 1: Account} */
    private function makeBuyer(int $balance = 0): array
    {
        $slug = 'kycredit-' . Str::random(4);

        $company = Company::create([
            'name'          => 'KyCredit Co',
            'slug'          => $slug,
            'email'         => $slug . '@test.test',
            'status'        => 'active',
            'kyc_status'    => 'approved',
            'currency_code' => 'KY',
            'sector'        => 'informatica',
            'description'   => 'Test',
        ]);

        $user = User::create([
            'company_id'          => $company->id,
            'account_holder_type' => 'company',
            'name'                => 'KyCredit Buyer',
            'email'               => 'buyer-' . Str::random(6) . '@test.test',
            'password'            => 'secret123',
            'role'                => 'company-manager',
            'is_active'           => true,
            'is_super_admin'      => false,
        ]);
        $user->forceFill([
            'email_verified_at'  => now(),
            'contract_signed_at' => now(),
        ])->save();

        $account = Account::create([
            'company_id'        => $company->id,
            'owner_user_id'     => $user->id,
            'owner_type'        => 'company',
            'type'              => 'primary',
            'account_name'      => 'Conto Buyer',
            'currency_code'     => 'KY',
            'status'            => 'active',
            'available_balance' => $balance,
        ]);

        return [$user, $account];
    }

    private function makeCard(): KyCard
    {
        // 120,00 € (12000 cent) -> 150,00 KY (15000 cent) con bonus +25%.
        return KyCard::create([
            'name'            => 'Ricarica 120',
            'ky_base_amount'  => 12000,
            'price_eur_cents' => 12000,
            'bonus_type'      => 'percentage',
            'bonus_value'     => 25,
            'is_active'       => true,
        ]);
    }

    private function makePurchase(Account $account, User $user, KyCard $card, string $status): KyCardPurchase
    {
        return KyCardPurchase::create([
            'ky_card_id'      => $card->id,
            'account_id'      => $account->id,
            'user_id'         => $user->id,
            'price_eur_cents' => $card->price_eur_cents,
            'ky_amount'       => $card->ky_total, // 15000
            'status'          => $status,
            'payment_method'  => $status === 'pending_bank_transfer' ? 'bank_transfer' : 'stripe',
        ]);
    }

    private function callCreditKy(KyCardPurchase $purchase): void
    {
        $controller = app(KyCardController::class);
        $method = new \ReflectionMethod($controller, 'creditKy');
        $method->setAccessible(true);
        $method->invoke($controller, $purchase);
    }

    public function test_admin_confirm_bank_transfer_credits_ky_from_system_account(): void
    {
        $system = $this->makeSystemAccount(0);
        $admin  = $this->makeAdmin();
        [$buyer, $account] = $this->makeBuyer(0);
        $card = $this->makeCard();
        $purchase = $this->makePurchase($account, $buyer, $card, 'pending_bank_transfer');

        $this->assertSame(15000, (int) $purchase->ky_amount);

        $this->actingAs($admin)
            ->post(route('admin.ky-cards.confirm-transfer', $purchase))
            ->assertRedirect(route('admin.ky-cards.pending-transfers'))
            ->assertSessionHas('success');

        $purchase->refresh();
        $this->assertTrue($purchase->isCompleted());
        $this->assertNotNull($purchase->transfer_id);
        $this->assertSame($admin->id, (int) $purchase->confirmed_by);

        // Saldi: utente +15000, conto madre -15000 (emissione sovrana, va in negativo).
        $this->assertSame(15000, (int) $account->fresh()->available_balance);
        $this->assertSame(-15000, (int) $system->fresh()->available_balance);

        // Transfer + doppia partita.
        $transfer = Transfer::find($purchase->transfer_id);
        $this->assertSame('kycard_topup', $transfer->kind);
        $this->assertSame('booked', $transfer->status);
        $this->assertSame(15000, (int) $transfer->amount);
        $this->assertSame($system->id, (int) $transfer->from_account_id);
        $this->assertSame($account->id, (int) $transfer->to_account_id);
        $this->assertSame('kycard_' . $purchase->uuid, $transfer->idempotency_key);
        $this->assertCount(2, $transfer->ledgerEntries);

        // AuditLog dell'emissione.
        $this->assertDatabaseHas('audit_logs', [
            'event'          => 'kycard.credited',
            'auditable_type' => Transfer::class,
            'auditable_id'   => $transfer->id,
        ]);

        // Invariante circuito chiuso: somma saldi = 0.
        $this->assertSame(0, (int) Account::sum('available_balance'));
    }

    public function test_credit_is_idempotent_and_does_not_double_credit(): void
    {
        $system = $this->makeSystemAccount(0);
        [$buyer, $account] = $this->makeBuyer(0);
        $card = $this->makeCard();
        $purchase = $this->makePurchase($account, $buyer, $card, 'pending_bank_transfer');

        $this->callCreditKy($purchase);

        $purchase->refresh();
        $this->assertTrue($purchase->isCompleted());
        $this->assertSame(15000, (int) $account->fresh()->available_balance);
        $this->assertSame(-15000, (int) $system->fresh()->available_balance);

        // Seconda invocazione: il guard deve impedire ogni nuovo movimento.
        $this->callCreditKy($purchase);

        $this->assertSame(1, Transfer::where('kind', 'kycard_topup')->count());
        $this->assertSame(1, AuditLog::where('event', 'kycard.credited')->count());
        $this->assertSame(15000, (int) $account->fresh()->available_balance);
        $this->assertSame(-15000, (int) $system->fresh()->available_balance);
    }

    public function test_credit_aligns_to_existing_transfer_without_rebooking(): void
    {
        // Simula la corsa webhook Stripe + pagina success: il transfer e' gia'
        // stato registrato (saldi gia' mossi) ma il purchase e' ancora pending.
        $system = $this->makeSystemAccount(-15000);
        [$buyer, $account] = $this->makeBuyer(15000);
        $card = $this->makeCard();
        $purchase = $this->makePurchase($account, $buyer, $card, 'pending');

        $existing = Transfer::create([
            'initiated_by'    => $buyer->id,
            'from_account_id' => $system->id,
            'to_account_id'   => $account->id,
            'amount'          => 15000,
            'currency_code'   => 'KY',
            'status'          => 'booked',
            'kind'            => 'kycard_topup',
            'idempotency_key' => 'kycard_' . $purchase->uuid,
            'booked_at'       => now(),
        ]);

        $this->callCreditKy($purchase);

        $purchase->refresh();
        $this->assertTrue($purchase->isCompleted());
        $this->assertSame($existing->id, (int) $purchase->transfer_id);

        // Nessun secondo accredito: un solo transfer, saldi invariati.
        $this->assertSame(1, Transfer::where('kind', 'kycard_topup')->count());
        $this->assertSame(15000, (int) $account->fresh()->available_balance);
        $this->assertSame(-15000, (int) $system->fresh()->available_balance);
    }
}
