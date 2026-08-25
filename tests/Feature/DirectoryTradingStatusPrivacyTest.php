<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Riservatezza dello stato del conto nella directory /aziende (25/08/2026).
 *
 * Segnalazione di Laura: le card della directory mostravano a TUTTI i clienti
 * il badge "⛔ Massimale" ("non puo' ricevere KY al momento") e la nota sul
 * saldo negativo dietro il badge "⚡ 100% KY". Sono informazioni sul conto di
 * un'altra azienda: le vede l'admin nel backoffice, e ognuno le proprie.
 *
 * Cosa NON cambia (scelta di Laura):
 * - il badge con la percentuale Kmoney resta visibile a tutti come prima,
 *   compresa la forzatura a 100% per i conti sottozero;
 * - "Paga" resta visibile anche verso un'azienda al massimale (il tetto non
 *   blocca gli incassi nel motore, e il pulsante mancante era gia' una spia).
 */
class DirectoryTradingStatusPrivacyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: Company, 2: Account}
     */
    private function makeCompanyUser(int $balance = 0, ?int $maxBalance = null, bool $onMap = false): array
    {
        $slug = 'dirpriv-' . Str::random(6);

        $company = Company::create([
            'name'          => 'DirPriv ' . Str::random(4),
            'slug'          => $slug,
            'email'         => $slug . '@test.test',
            'status'        => 'active',
            'kyc_status'    => 'approved',
            'currency_code' => 'KY',
            'sector'        => 'informatica',
            'description'   => 'Test',
            'latitude'      => $onMap ? 45.4642 : null,
            'longitude'     => $onMap ? 9.19 : null,
        ]);

        $user = User::create([
            'company_id'          => $company->id,
            'account_holder_type' => 'company',
            'name'                => 'DirPriv User',
            'email'               => 'dirpriv-' . Str::random(8) . '@test.test',
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
            'account_name'      => 'Conto DirPriv',
            'currency_code'     => 'KY',
            'status'            => 'active',
            'available_balance' => $balance,
            'max_balance'       => $maxBalance,
        ]);

        return [$user, $company, $account];
    }

    // ── Quello che i clienti NON devono piu' vedere ───────────────────────────

    public function test_client_does_not_see_ceiling_badge_of_another_company(): void
    {
        [$viewer] = $this->makeCompanyUser();
        $this->makeCompanyUser(balance: 50000, maxBalance: 50000);

        // Le classi ky-badge--* compaiono comunque nel <style> della pagina e nel
        // renderer JS dei pin: il badge davvero renderizzato su una card e' l'unico
        // che porta anche l'attributo title, ed e' quello che qui non deve esserci.
        $this->actingAs($viewer)->get(route('portal.companies'))
            ->assertOk()
            ->assertDontSee('ky-badge--ceil" title=', false)
            ->assertDontSee('al massimale: non pu', false);
    }

    public function test_client_does_not_see_the_negative_balance_note_of_another_company(): void
    {
        [$viewer] = $this->makeCompanyUser();
        $this->makeCompanyUser(balance: -5000);

        $response = $this->actingAs($viewer)->get(route('portal.companies'))->assertOk();

        // Il conto sottozero e' forzato al 100%: il badge dorato si vede ancora
        // (scelta di Laura), ma senza piu' la spiegazione sul saldo negativo.
        $response->assertSee('ky-badge--gold" title=', false)
            ->assertDontSee('ky-badge--debit" title=', false)
            ->assertDontSee('sottozero: puoi accettare', false)
            ->assertDontSee('Conto sottozero', false);
    }

    public function test_map_payload_never_carries_the_trading_status_of_others(): void
    {
        [$viewer] = $this->makeCompanyUser();
        $this->makeCompanyUser(balance: 50000, maxBalance: 50000, onMap: true);
        $this->makeCompanyUser(balance: -5000, onMap: true);

        $html = $this->actingAs($viewer)->get(route('portal.companies'))->assertOk()->getContent();

        // Il dataset della mappa finisce in chiaro nel sorgente della pagina:
        // e' il motivo per cui i flag vengono azzerati nel controller.
        $this->assertStringNotContainsString('"is_at_ceiling":true', $html);
        $this->assertStringNotContainsString('"is_in_debit":true', $html);
    }

    public function test_pay_button_stays_visible_towards_a_company_at_ceiling(): void
    {
        [$viewer] = $this->makeCompanyUser();
        [, , $account] = $this->makeCompanyUser(balance: 50000, maxBalance: 50000);

        $this->actingAs($viewer)->get(route('portal.companies'))
            ->assertOk()
            ->assertSee('?to=' . $account->id, false);
    }

    public function test_map_pin_of_a_company_at_ceiling_still_offers_the_pay_link(): void
    {
        [$viewer] = $this->makeCompanyUser();
        [, , $account] = $this->makeCompanyUser(balance: 50000, maxBalance: 50000, onMap: true);

        $html = $this->actingAs($viewer)->get(route('portal.companies'))->assertOk()->getContent();

        $this->assertStringContainsString('to=' . $account->id, $html);
        $this->assertStringNotContainsString('"pay_url":null', $html);
    }

    // ── Quello che ognuno continua a vedere di se' ────────────────────────────

    public function test_a_company_still_sees_its_own_ceiling_badge(): void
    {
        [$viewer] = $this->makeCompanyUser(balance: 50000, maxBalance: 50000);

        $this->actingAs($viewer)->get(route('portal.companies'))
            ->assertOk()
            ->assertSee('ky-badge--ceil" title=', false)
            ->assertSee('⛔ Massimale', false)
            ->assertSee('Il tuo conto è al massimale', false);
    }

    public function test_a_company_still_sees_its_own_negative_balance_badge(): void
    {
        [$viewer] = $this->makeCompanyUser(balance: -5000);

        $this->actingAs($viewer)->get(route('portal.companies'))
            ->assertOk()
            ->assertSee('ky-badge--debit" title=', false)
            ->assertSee('Il tuo conto è sottozero', false);
    }

    public function test_the_ceiling_badge_of_a_third_company_stays_hidden_even_to_a_company_at_ceiling(): void
    {
        [$viewer] = $this->makeCompanyUser(balance: 50000, maxBalance: 50000);
        $this->makeCompanyUser(balance: 80000, maxBalance: 80000);

        $html = $this->actingAs($viewer)->get(route('portal.companies'))->assertOk()->getContent();

        // Una sola card con il badge "Massimale": la propria.
        $this->assertSame(1, substr_count($html, 'ky-badge--ceil" title='));
    }

    // ── Il backoffice invece li vede tutti ────────────────────────────────────

    public function test_admin_company_list_shows_the_trading_status(): void
    {
        $admin = User::create([
            'name'                => 'Admin',
            'email'               => 'admin-' . Str::random(8) . '@test.test',
            'password'            => 'secret123',
            'role'                => 'admin',
            'account_holder_type' => 'company',
            'is_active'           => true,
            'is_super_admin'      => true,
        ]);
        $admin->forceFill(['email_verified_at' => now()])->save();

        $this->makeCompanyUser(balance: 50000, maxBalance: 50000);
        $this->makeCompanyUser(balance: -5000);

        $this->actingAs($admin)->get(route('admin.companies.index'))
            ->assertOk()
            ->assertSee('Massimale')
            ->assertSee('Sottozero');
    }
}
