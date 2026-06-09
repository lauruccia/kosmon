<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\CreditLimit;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Test per il PIN di pagamento e la soglia admin.
 *
 * Casi coperti:
 *  1. Admin può salvare payment_pin_threshold
 *  2. payment_pin_threshold appare in defaultsMap()
 *  3. Utente può impostare il proprio PIN
 *  4. Utente può rimuovere il proprio PIN
 *  5. Pagamento sopra soglia SENZA PIN impostato → passa (PIN facoltativo)
 *  6. Pagamento sotto soglia CON PIN impostato → passa senza PIN
 *  7. Pagamento sopra soglia CON PIN impostato, PIN corretto → passa
 *  8. Pagamento sopra soglia CON PIN impostato, PIN mancante → rifiutato
 *  9. Pagamento sopra soglia CON PIN impostato, PIN errato → rifiutato
 * 10. Soglia null → PIN mai richiesto (anche se impostato)
 */
class PaymentPinTest extends TestCase
{
    use RefreshDatabase;

    // SHA-256 di "123456"
    private const PIN_HASH = '8d969eef6ecad3c29a3a629280e686cf0c3f5d5a86aff3ca12020c923adc6c92';
    // SHA-256 di "000000"
    private const WRONG_HASH = 'e4ad93ca07acb8d908a3aa41e920ea4f4ef4f26e7f86cf8291c5db289780a5ae';

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Crea un utente privato verificato con un account attivo e credito sufficiente.
     * Usa il percorso owner_user_id (private) di resolveCurrentContext.
     */
    private function makePortalUser(int $balance = 50000, bool $withPin = false): array
    {
        $user = User::factory()->create([
            'email_verified_at'  => now(),
            'contract_signed_at' => now(),
            'payment_pin_hash'   => $withPin ? self::PIN_HASH : null,
            'role'               => 'private-owner',
            // company_id null → resolveCurrentContext usa owner_user_id
        ]);

        $account = Account::factory()->create([
            'owner_user_id'          => $user->id,
            'owner_type'             => 'private',
            'type'                   => 'primary',
            'status'                 => 'active',
            'currency_code'          => 'KY',
            'available_balance'      => $balance,
            'allow_negative_balance' => true,
        ]);
        CreditLimit::create([
            'account_id'            => $account->id,
            'credit_limit'          => 100000,
            'daily_outgoing_limit'  => 200000,
            'single_transfer_limit' => 100000,
            'status'                => 'active',
        ]);

        return [$user, $account];
    }

    /**
     * Crea un secondo account destinatario.
     */
    private function makeRecipientAccount(): Account
    {
        $company = Company::factory()->create(['kyc_status' => 'approved', 'status' => 'active']);
        return Account::factory()->create([
            'company_id'        => $company->id,
            'owner_type'        => 'company',
            'type'              => 'primary',
            'status'            => 'active',
            'currency_code'     => 'KY',
            'available_balance' => 0,
        ]);
    }

    /**
     * Imposta la soglia PIN nel SystemSetting condiviso.
     */
    private function setThreshold(?int $cents): void
    {
        SystemSetting::userLimitDefaults()->forceFill(['payment_pin_threshold' => $cents])->save();
    }

    // ── Test admin ────────────────────────────────────────────────────────────

    /** Admin può salvare payment_pin_threshold tramite il form limiti. */
    public function test_admin_can_save_payment_pin_threshold(): void
    {
        $this->seed();
        $admin = User::where('email', 'superadmin@kmoney.test')->firstOrFail();

        $this->actingAs($admin)
            ->post('/admin/limits', [
                'payment_pin_threshold' => '10.00',  // 10,00 KY
            ])
            ->assertRedirect();

        $this->assertSame(1000, SystemSetting::userLimitDefaults()->payment_pin_threshold);
    }

    /** payment_pin_threshold è incluso nel defaultsMap(). */
    public function test_payment_pin_threshold_in_defaults_map(): void
    {
        $this->setThreshold(2500);

        $map = SystemSetting::userLimitDefaults()->defaultsMap();

        $this->assertArrayHasKey('payment_pin_threshold', $map);
        $this->assertSame(2500, $map['payment_pin_threshold']);
    }

    /** Admin può azzerare la soglia (null = disabilita PIN). */
    public function test_admin_can_clear_payment_pin_threshold(): void
    {
        $this->seed();
        $admin = User::where('email', 'superadmin@kmoney.test')->firstOrFail();

        // Prima la imposto
        SystemSetting::userLimitDefaults()->forceFill(['payment_pin_threshold' => 5000])->save();

        // Poi la svuoto
        $this->actingAs($admin)
            ->post('/admin/limits', ['payment_pin_threshold' => ''])
            ->assertRedirect();

        $this->assertNull(SystemSetting::userLimitDefaults()->payment_pin_threshold);
    }

    // ── Test gestione PIN utente ───────────────────────────────────────────────

    /** Utente può impostare il proprio PIN tramite POST. */
    public function test_user_can_set_payment_pin(): void
    {
        [$user] = $this->makePortalUser();

        $this->assertNull($user->payment_pin_hash);

        $this->actingAs($user)
            ->post(route('portal.invia.pin.imposta'), ['pin_hash' => self::PIN_HASH])
            ->assertRedirect();

        $this->assertSame(self::PIN_HASH, $user->fresh()->payment_pin_hash);
    }

    /** Utente può rimuovere il proprio PIN. */
    public function test_user_can_remove_payment_pin(): void
    {
        [$user] = $this->makePortalUser(withPin: true);

        $this->assertNotNull($user->payment_pin_hash);

        $this->actingAs($user)
            ->post(route('portal.invia.pin.rimuovi'))
            ->assertRedirect();

        $this->assertNull($user->fresh()->payment_pin_hash);
    }

    /** PIN deve essere esattamente 64 caratteri hex. */
    public function test_set_pin_rejects_invalid_hash(): void
    {
        [$user] = $this->makePortalUser();

        $this->actingAs($user)
            ->post(route('portal.invia.pin.imposta'), ['pin_hash' => 'troppo-corto'])
            ->assertSessionHasErrors('pin_hash');

        $this->assertNull($user->fresh()->payment_pin_hash);
    }

    // ── Test flusso pagamento ─────────────────────────────────────────────────

    /** Sopra soglia ma utente senza PIN → pagamento eseguito normalmente. */
    public function test_payment_above_threshold_without_pin_set_succeeds(): void
    {
        [$user, $fromAccount] = $this->makePortalUser();
        $toAccount = $this->makeRecipientAccount();
        $this->setThreshold(1000); // soglia 10 KY

        $this->actingAs($user)
            ->post(route('portal.invia.esegui'), [
                'to_account_id' => $toAccount->id,
                'amount'        => '20.00',   // 20 KY > soglia
            ])
            ->assertRedirect()
            ->assertSessionMissing('portal_error');
    }

    /** Sotto soglia con PIN impostato → pagamento eseguito senza PIN. */
    public function test_payment_below_threshold_with_pin_skips_pin_check(): void
    {
        [$user, $fromAccount] = $this->makePortalUser(withPin: true);
        $toAccount = $this->makeRecipientAccount();
        $this->setThreshold(2000); // soglia 20 KY

        $this->actingAs($user)
            ->post(route('portal.invia.esegui'), [
                'to_account_id' => $toAccount->id,
                'amount'        => '5.00',   // 5 KY < soglia, nessun PIN inviato
            ])
            ->assertRedirect()
            ->assertSessionMissing('portal_error');
    }

    /** Sopra soglia con PIN impostato e PIN corretto → pagamento eseguito. */
    public function test_payment_above_threshold_with_correct_pin_succeeds(): void
    {
        [$user, $fromAccount] = $this->makePortalUser(withPin: true);
        $toAccount = $this->makeRecipientAccount();
        $this->setThreshold(1000); // soglia 10 KY

        $this->actingAs($user)
            ->post(route('portal.invia.esegui'), [
                'to_account_id' => $toAccount->id,
                'amount'        => '20.00',
                'pin_hash'      => self::PIN_HASH,
            ])
            ->assertRedirect()
            ->assertSessionMissing('portal_error');
    }

    /** Sopra soglia con PIN impostato ma PIN mancante → rifiutato. */
    public function test_payment_above_threshold_without_pin_is_rejected(): void
    {
        [$user, $fromAccount] = $this->makePortalUser(withPin: true);
        $toAccount = $this->makeRecipientAccount();
        $this->setThreshold(1000); // soglia 10 KY

        $this->actingAs($user)
            ->post(route('portal.invia.esegui'), [
                'to_account_id' => $toAccount->id,
                'amount'        => '20.00',
                // nessun pin_hash
            ])
            ->assertRedirect()
            ->assertSessionHas('portal_error');
    }

    /** Sopra soglia con PIN impostato ma PIN errato → rifiutato. */
    public function test_payment_above_threshold_with_wrong_pin_is_rejected(): void
    {
        [$user, $fromAccount] = $this->makePortalUser(withPin: true);
        $toAccount = $this->makeRecipientAccount();
        $this->setThreshold(1000); // soglia 10 KY

        $this->actingAs($user)
            ->post(route('portal.invia.esegui'), [
                'to_account_id' => $toAccount->id,
                'amount'        => '20.00',
                'pin_hash'      => self::WRONG_HASH,
            ])
            ->assertRedirect()
            ->assertSessionHas('portal_error');
    }

    /** Soglia null → PIN mai richiesto, anche se l'utente ce l'ha impostato. */
    public function test_payment_with_null_threshold_never_requires_pin(): void
    {
        [$user, $fromAccount] = $this->makePortalUser(withPin: true);
        $toAccount = $this->makeRecipientAccount();
        $this->setThreshold(null); // PIN disabilitato globalmente

        $this->actingAs($user)
            ->post(route('portal.invia.esegui'), [
                'to_account_id' => $toAccount->id,
                'amount'        => '500.00', // importo altissimo, nessun PIN inviato
            ])
            ->assertRedirect()
            ->assertSessionMissing('portal_error');
    }

    /** Pagamento esattamente sulla soglia → PIN richiesto (>= threshold). */
    public function test_payment_exactly_at_threshold_requires_pin(): void
    {
        [$user, $fromAccount] = $this->makePortalUser(withPin: true);
        $toAccount = $this->makeRecipientAccount();
        $this->setThreshold(1000); // soglia 10 KY

        // Esattamente 10 KY = 1000 centesimi, deve richiedere PIN
        $this->actingAs($user)
            ->post(route('portal.invia.esegui'), [
                'to_account_id' => $toAccount->id,
                'amount'        => '10.00',
                // nessun pin_hash
            ])
            ->assertRedirect()
            ->assertSessionHas('portal_error');
    }
}
