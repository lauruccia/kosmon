<?php

namespace Tests\Feature;

use App\Services\PayPalOrderVerifier;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * PayPalOrderVerifier (02/09/2026), gemello di StripeCheckoutVerifier.
 *
 * Le cose che deve dimostrare sono le stesse tre su cui, senza di lui, si
 * perdono soldi o si regalano accrediti:
 *
 *  1. APPROVED non basta: vuol dire che l'utente ha detto di si', non che i
 *     soldi si sono mossi. Solo COMPLETED e' un incasso;
 *  2. un ordine pagato per un acquisto non ne salda un altro (custom_id);
 *  3. l'importo deve coincidere al centesimo — e' il controllo che prima non
 *     c'era affatto, perche' il ripescaggio dei privati si accontentava dello
 *     stato.
 */
class PayPalOrderVerifierTest extends TestCase
{
    private const IMPORTO = 48000; // 480,00 EUR
    private const UUID    = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.paypal.client_id' => 'client-finto',
            'services.paypal.secret'    => 'segreto-finto',
            'services.paypal.mode'      => 'sandbox',
        ]);
    }

    public function test_un_ordine_completed_dell_importo_giusto_passa(): void
    {
        $this->fingiOrdine($this->ordine());

        $this->assertTrue($this->verifica('PAY-123'));
    }

    public function test_un_ordine_solo_approvato_non_passa(): void
    {
        // APPROVED = l'utente ha detto di si' e basta. Nessun soldo si e'
        // mosso, e accreditare qui vorrebbe dire regalare la quota.
        $this->fingiOrdine($this->ordine(status: 'APPROVED'));

        $this->assertFalse($this->verifica('PAY-123'));
    }

    public function test_un_ordine_riferito_a_un_altro_acquisto_non_passa(): void
    {
        $this->fingiOrdine($this->ordine(customId: 'un-altro-uuid'));

        $this->assertFalse($this->verifica('PAY-123'));
    }

    public function test_un_importo_diverso_non_passa(): void
    {
        $this->fingiOrdine($this->ordine(valore: '30.00'));

        $this->assertFalse($this->verifica('PAY-123'));
    }

    public function test_una_valuta_diversa_da_euro_non_passa(): void
    {
        $this->fingiOrdine($this->ordine(valuta: 'USD'));

        $this->assertFalse($this->verifica('PAY-123'));
    }

    /**
     * Senza ordine salvato non si interroga nessuno: il pagamento non e' mai
     * partito. E' la stessa regola di Stripe — l'identificativo si legge dalla
     * colonna, mai dalla richiesta.
     */
    public function test_senza_ordine_salvato_non_si_chiede_niente_a_paypal(): void
    {
        Http::fake();

        $this->assertFalse($this->verifica(null));
        $this->assertFalse($this->verifica(''));

        Http::assertNothingSent();
    }

    public function test_senza_credenziali_non_si_passa(): void
    {
        config(['services.paypal.client_id' => null]);
        Http::fake();

        $this->assertFalse($this->verifica('PAY-123'));

        Http::assertNothingSent();
    }

    /** PayPal irraggiungibile: non si accredita per fiducia. */
    public function test_se_paypal_non_risponde_non_si_passa(): void
    {
        Http::fake(fn () => throw new \RuntimeException('rete giù'));

        $this->assertFalse($this->verifica('PAY-123'));
    }

    // ─── Aiutanti ───────────────────────────────────────────────────────────

    private function verifica(?string $orderId): bool
    {
        return app(PayPalOrderVerifier::class)
            ->isCompletedFor($orderId, self::IMPORTO, self::UUID, 'test');
    }

    /** @return array<string,mixed> */
    private function ordine(
        string $status = 'COMPLETED',
        string $valore = '480.00',
        string $valuta = 'EUR',
        string $customId = self::UUID,
    ): array {
        return [
            'id'             => 'PAY-123',
            'status'         => $status,
            'purchase_units' => [[
                'custom_id' => $customId,
                'amount'    => ['currency_code' => $valuta, 'value' => $valore],
            ]],
        ];
    }

    /** @param array<string,mixed> $ordine */
    private function fingiOrdine(array $ordine): void
    {
        Http::fake([
            '*/v1/oauth2/token'      => Http::response(['access_token' => 'token-finto']),
            '*/v2/checkout/orders/*' => Http::response($ordine),
        ]);
    }
}
