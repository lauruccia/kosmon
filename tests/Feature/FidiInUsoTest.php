<?php

namespace Tests\Feature;

use App\Models\Account;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * I KY in mano ai membri hanno due origini, non una: quelli emessi dalla Cassa
 * (che lasciano il segno sul suo saldo negativo) e quelli creati da un membro
 * che paga andando sotto zero — moneta vera, gia' spesa, che dalla Cassa non
 * passa mai. Il backoffice mostrava solo la prima e chiamava "KY in
 * circolazione" un numero piu' piccolo del vero.
 *
 * L'invariante che tiene insieme le due:
 *     circolante = |saldo Cassa| + fidi in uso
 * ed e' una conseguenza diretta della somma zero del circuito chiuso.
 */
class FidiInUsoTest extends TestCase
{
    use RefreshDatabase;

    /** Un conto grezzo, senza passare dalle factory di azienda. */
    private function conto(int $saldo, bool $sistema = false): Account
    {
        return Account::factory()->create([
            'available_balance' => $saldo,
            'is_system_account' => $sistema,
        ]);
    }

    public function test_fidi_in_uso_somma_i_saldi_negativi_dei_membri(): void
    {
        $this->conto(-300000, sistema: true);   // Cassa: ha emesso 3.000,00
        $this->conto(500000);    // membro a credito
        $this->conto(-200000);   // membro che usa il fido
        $this->conto(0);         // conto fermo: non va contato

        $fidi = Account::fidiInUso();

        $this->assertSame(200000, $fidi['totale']);
        $this->assertSame(1, $fidi['conti']);
    }

    public function test_il_conto_sistema_non_e_un_fido_in_uso(): void
    {
        $this->conto(-300000, sistema: true);
        $this->conto(300000);

        $fidi = Account::fidiInUso();

        $this->assertSame(0, $fidi['totale'], 'Il debito della Cassa e\' emissione, non fido.');
        $this->assertSame(0, $fidi['conti']);
    }

    public function test_circolante_uguale_emissione_piu_fidi_in_uso(): void
    {
        // Somma zero: -3.000 (Cassa) + 5.000 + (-2.000) = 0
        $cassa = $this->conto(-300000, sistema: true);
        $this->conto(500000);
        $this->conto(-200000);

        $this->assertSame(0, (int) Account::query()->sum('available_balance'));

        $emissione  = abs((int) $cassa->available_balance);
        $fidi       = Account::fidiInUso()['totale'];
        $circolante = Account::kyInCircolazione()['totale'];

        $this->assertSame(300000, $emissione);
        $this->assertSame(200000, $fidi);
        $this->assertSame(500000, $circolante);
        $this->assertSame($emissione + $fidi, $circolante);
    }

    public function test_circolante_conta_i_conti_a_credito(): void
    {
        $this->conto(-400000, sistema: true);
        $this->conto(250000);
        $this->conto(150000);
        $this->conto(0);

        $circolante = Account::kyInCircolazione();

        $this->assertSame(400000, $circolante['totale']);
        $this->assertSame(2, $circolante['conti']);
    }

    public function test_fido_utilizzato_del_singolo_conto(): void
    {
        $this->assertSame(45050, $this->conto(-45050)->fidoUtilizzato());
        $this->assertSame(0, $this->conto(120000)->fidoUtilizzato(), 'Chi e\' a credito non usa fido.');
        $this->assertSame(0, $this->conto(0)->fidoUtilizzato());
    }

    public function test_senza_nessuno_sotto_zero_i_fidi_in_uso_sono_zero(): void
    {
        $this->conto(-100000, sistema: true);
        $this->conto(100000);

        $this->assertSame(['totale' => 0, 'conti' => 0], Account::fidiInUso());
        $this->assertSame(100000, Account::kyInCircolazione()['totale']);
    }
}
