<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La Content-Security-Policy del portale (01/09/2026).
 *
 * PERCHE' QUESTO FILE ESISTE. Per settimane il pagamento con carta non e'
 * mai partito — ne' per la quota di iscrizione ne' per la ricarica KYCard —
 * e nessuno se n'e' accorto perche' non lasciava traccia da nessuna parte:
 * la sessione Stripe veniva creata regolarmente, il server rispondeva "302
 * vai su checkout.stripe.com", e il BROWSER buttava via quel salto perche'
 * `form-action 'self'` vale anche sui redirect innescati da un form. Nessun
 * errore a video, niente nei log, la pagina che resta ferma.
 *
 * Una policy si scrive in un attimo e si rompe in silenzio: da qui in poi
 * chi la stringe di nuovo trova questo test rosso invece di scoprirlo fra
 * sei mesi da un utente che non riesce a pagare.
 */
class ContentSecurityPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_il_form_puo_portare_l_utente_al_checkout_di_stripe(): void
    {
        $header = $this->get('/')->headers->get('Content-Security-Policy');

        $this->assertNotNull($header, 'La CSP deve esserci su tutte le risposte web.');

        preg_match('/form-action ([^;]+)/', $header, $trovato);
        $this->assertNotEmpty($trovato, 'form-action deve restare nella policy.');

        $this->assertStringContainsString('checkout.stripe.com', $trovato[1]);
        // 'self' non si toglie: e' quello che impedisce a un form iniettato
        // in pagina di spedire dati verso un sito qualunque.
        $this->assertStringContainsString("'self'", $trovato[1]);
    }

    public function test_la_policy_non_si_apre_a_destinazioni_qualunque(): void
    {
        $header = $this->get('/')->headers->get('Content-Security-Policy');

        preg_match('/form-action ([^;]+)/', $header, $trovato);

        // Un jolly qui vorrebbe dire che qualsiasi form della pagina puo'
        // spedire dove vuole: e' la difesa contro il furto di dati da un
        // form manomesso, e non va barattata per far funzionare un incasso.
        $this->assertStringNotContainsString('*;', $trovato[1] . ';');
        $this->assertStringNotContainsString("'unsafe", $trovato[1]);
    }

    public function test_le_altre_difese_di_base_restano_in_piedi(): void
    {
        $header = $this->get('/')->headers->get('Content-Security-Policy');

        foreach (["frame-ancestors 'none'", "object-src 'none'", "base-uri 'self'", "default-src 'self'"] as $direttiva) {
            $this->assertStringContainsString($direttiva, $header);
        }
    }
}
