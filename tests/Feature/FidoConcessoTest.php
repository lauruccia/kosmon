<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\CreditLimit;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Il fido concesso e il margine ancora aperto — cioe' quanta moneta puo'
 * nascere domani senza che nessuno autorizzi niente.
 *
 * Il punto delicato sono le DUE fonti dello scoperto: una riga attiva in
 * `credit_limits`, oppure `users.negative_balance_limit`. In produzione c'e'
 * chi va sotto zero solo grazie alla seconda, senza nessuna riga di fido:
 * guardando i soli `credit_limits` quel permesso sparisce dai conti.
 */
class FidoConcessoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Nessuno scoperto implicito per tutti, se non dove lo dice il test.
        SystemSetting::userLimitDefaults()->update(['default_negative_balance_limit' => null]);
    }

    /** Conto con intestatario, cosi' la seconda fonte di scoperto e' raggiungibile. */
    private function conto(int $saldo, ?int $limiteUtente = null, bool $usaDefault = true): Account
    {
        $utente = User::factory()->create([
            'negative_balance_limit' => $limiteUtente,
            'transfer_limits_use_defaults' => $usaDefault,
        ]);

        return Account::factory()->create([
            'available_balance' => $saldo,
            'owner_user_id' => $utente->id,
        ]);
    }

    public function test_conta_il_fido_da_credit_limits(): void
    {
        $conto = $this->conto(-200000);
        CreditLimit::factory()->forAccount($conto->id)->withLimit(500000)->create();

        $fido = Account::fidoConcesso();

        $this->assertSame(500000, $fido['concesso']);
        $this->assertSame(200000, $fido['usato']);
        $this->assertSame(300000, $fido['margine']);
        $this->assertSame(1, $fido['conti']);
    }

    /**
     * Il caso Mercorelli: sotto zero senza nessuna riga in credit_limits.
     * Se questa seconda fonte non venisse contata, il suo permesso sarebbe
     * invisibile e il margine aperto risulterebbe piu' basso del vero.
     */
    public function test_conta_anche_il_limite_dell_intestatario_senza_riga_di_fido(): void
    {
        $this->conto(-49765, limiteUtente: 100000, usaDefault: false);

        $fido = Account::fidoConcesso();

        $this->assertSame(100000, $fido['concesso']);
        $this->assertSame(49765, $fido['usato']);
        $this->assertSame(50235, $fido['margine']);
        $this->assertSame(1, $fido['conti']);
    }

    public function test_fra_le_due_fonti_vince_la_piu_alta(): void
    {
        $conto = $this->conto(0, limiteUtente: 800000, usaDefault: false);
        CreditLimit::factory()->forAccount($conto->id)->withLimit(300000)->create();

        // Non si sommano: sono due permessi per lo stesso scoperto, come massimale().
        $this->assertSame(800000, Account::fidoConcesso()['concesso']);
    }

    public function test_il_fido_del_conto_sistema_non_e_credito_concesso_a_nessuno(): void
    {
        $cassa = Account::factory()->create([
            'available_balance' => -300000,
            'is_system_account' => true,
        ]);
        CreditLimit::factory()->forAccount($cassa->id)->withLimit(10000000)->create();

        $this->assertSame(['concesso' => 0, 'usato' => 0, 'margine' => 0, 'conti' => 0], Account::fidoConcesso());
    }

    /**
     * Chi e' sotto zero oltre il proprio tetto (dati vecchi, o tetto abbassato
     * dopo) non deve regalare margine agli altri: il margine si somma conto
     * per conto, non come differenza fra i due totali.
     */
    public function test_chi_sfora_il_proprio_tetto_non_regala_margine(): void
    {
        $sforato = $this->conto(-500000);
        CreditLimit::factory()->forAccount($sforato->id)->withLimit(300000)->create();

        $regolare = $this->conto(0);
        CreditLimit::factory()->forAccount($regolare->id)->withLimit(300000)->create();

        $fido = Account::fidoConcesso();

        $this->assertSame(600000, $fido['concesso']);
        $this->assertSame(500000, $fido['usato']);
        // La differenza dei totali direbbe 100.000. Il vero margine e' 300.000:
        // lo sforamento del primo non e' capienza del secondo.
        $this->assertSame(300000, $fido['margine']);
    }

    public function test_senza_nessun_fido_il_conto_non_viene_contato(): void
    {
        $this->conto(150000);

        $this->assertSame(['concesso' => 0, 'usato' => 0, 'margine' => 0, 'conti' => 0], Account::fidoConcesso());
    }

    /**
     * Se l'admin mette uno scoperto predefinito di sistema, quello vale per
     * tutti quelli che seguono i default — ed e' moneta che puo' nascere.
     */
    public function test_lo_scoperto_predefinito_di_sistema_vale_per_chi_segue_i_default(): void
    {
        SystemSetting::userLimitDefaults()->update(['default_negative_balance_limit' => 70000]);

        $this->conto(0);                              // segue i default
        $this->conto(0, limiteUtente: 0, usaDefault: false); // limiti propri, nessuno scoperto

        $fido = Account::fidoConcesso();

        $this->assertSame(70000, $fido['concesso']);
        $this->assertSame(70000, $fido['margine']);
        $this->assertSame(1, $fido['conti']);
    }
}
