<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\Role;
use App\Models\Transfer;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * L'estratto conto come documento da consegnare a terzi (09/09/2026).
 *
 * LA PROPRIETA' CHE CONTA E' UNA: **il documento quadra con se stesso**.
 * Saldo iniziale + entrate - uscite = saldo finale, e l'ultima riga della
 * colonna Saldo coincide col saldo finale del riepilogo. Un estratto conto che
 * non quadra non e' un difetto estetico: e' un documento che il commercialista
 * rimanda indietro.
 *
 * La seconda proprieta': **i tre formati raccontano lo stesso periodo**. PDF,
 * CSV e prima nota partono dallo stesso StatementBuilder proprio per questo, e
 * qui si verifica che il numero di movimenti coincida davvero.
 */
class EstrattoContoTest extends TestCase
{
    use RefreshDatabase;

    private Account $conto;
    private Account $controparte;
    private User $utente;
    private Company $azienda;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->azienda = Company::create([
            'name'       => 'Rossi Srl',
            'slug'       => 'rossi-srl',
            'status'     => 'active',
            'vat_number' => '01234567890',
            // Senza KYC approvata EnsureOnboardingComplete dirotta l'utente
            // aziendale sulla schermata di benvenuto e nessuna pagina del
            // portale risponde 200.
            'kyc_status' => 'approved',
        ]);

        $this->utente = $this->utente('titolare', $this->azienda->id);

        // /movimenti chiede canOperateAccount(), che passa dai permessi
        // payments.send/receive: senza ruolo la pagina risponde 403 e il test
        // sul link all'estratto conto non arriverebbe nemmeno a guardarlo.
        $this->utente->roles()->sync([Role::where('slug', 'company-member')->firstOrFail()->id]);
        $this->utente = $this->utente->fresh();

        $this->conto = Account::create([
            'company_id'        => $this->azienda->id,
            'owner_type'        => 'company',
            'type'              => 'member',
            'status'            => 'active',
            'available_balance' => 12000,
        ]);

        $altra = Company::create([
            'name' => 'Bianchi Spa', 'slug' => 'bianchi-spa',
            'status' => 'active', 'kyc_status' => 'approved',
        ]);

        $this->controparte = Account::create([
            'company_id'        => $altra->id,
            'owner_type'        => 'company',
            'type'              => 'member',
            'status'            => 'active',
            'available_balance' => 0,
        ]);

        // Marzo 2026: +100,00 / -30,00 / +50,00 → saldo 120,00 partendo da zero.
        $this->movimento('2026-03-05', 10000, true, 'Saldo fattura 12', 10000);
        $this->movimento('2026-03-12', 3000, false, 'Acconto fornitore', 7000);
        $this->movimento('2026-03-20', 5000, true, 'Vendita banco', 12000);

        // Fuori periodo: non deve comparire nell'estratto di marzo.
        $this->movimento('2026-04-03', 9900, true, 'Movimento di aprile', 21900);
    }

    // ── La pagina ───────────────────────────────────────────────────────────

    public function test_la_pagina_mostra_le_scelte_rapide(): void
    {
        $response = $this->actingAs($this->utente)->get(route('portal.statement'));

        $response->assertOk();
        foreach (['Mese corrente', 'Mese scorso', 'Trimestre corrente', 'Anno in corso', 'Anno precedente'] as $etichetta) {
            $response->assertSee($etichetta);
        }
        $response->assertSee('name="periodo"', false);
        $response->assertSee('value="prima-nota"', false);
    }

    public function test_la_pagina_dei_movimenti_porta_all_estratto_conto(): void
    {
        // Prima del 09/09/2026 /estratto-conto non era linkata da nessuna
        // pagina del portale: esisteva solo digitando l'indirizzo.
        $this->actingAs($this->utente)
            ->get(route('portal.movements'))
            ->assertOk()
            ->assertSee(route('portal.statement'), false);
    }

    // ── Il PDF ──────────────────────────────────────────────────────────────

    public function test_il_pdf_si_genera_e_il_nome_del_file_dice_periodo_e_azienda(): void
    {
        $response = $this->scarica(['periodo' => 'mese', 'mese' => '2026-03']);

        $response->assertOk();
        $this->assertStringContainsString('pdf', strtolower($response->headers->get('Content-Type') ?? ''));
        $this->assertStringContainsString(
            'estratto-conto-rossi-srl-2026-03.pdf',
            (string) $response->headers->get('Content-Disposition'),
        );
    }

    public function test_il_pdf_annuale_si_chiama_come_l_anno(): void
    {
        $response = $this->scarica(['periodo' => 'anno', 'anno' => '2026']);

        $response->assertOk();
        $this->assertStringContainsString(
            'estratto-conto-rossi-srl-2026.pdf',
            (string) $response->headers->get('Content-Disposition'),
        );
    }

    public function test_un_periodo_scritto_male_non_rompe_il_download(): void
    {
        $this->scarica(['periodo' => 'non-esiste', 'mese' => '2026-99'])->assertOk();
    }

    // ── Il CSV ──────────────────────────────────────────────────────────────

    public function test_il_csv_quadra_e_contiene_solo_i_movimenti_del_periodo(): void
    {
        $csv = $this->contenuto($this->scarica(['periodo' => 'mese', 'mese' => '2026-03', 'formato' => 'csv']));

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);

        $meta = $this->intestazione($csv);

        // Senza queste righe il file allegato a una mail non dice piu' di chi
        // e di quando sia.
        $this->assertSame('Rossi Srl', $meta['Intestatario']);
        $this->assertSame('Marzo 2026', $meta['Periodo']);

        // Saldo iniziale + entrate - uscite = saldo finale.
        $this->assertSame('0,00', $meta['Saldo iniziale (KY)']);
        $this->assertSame('150,00', $meta['Totale entrate (KY)']);
        $this->assertSame('30,00', $meta['Totale uscite (KY)']);
        $this->assertSame('120,00', $meta['Saldo finale (KY)']);

        $this->assertStringContainsString('Saldo fattura 12', $csv);
        $this->assertStringContainsString('Acconto fornitore', $csv);
        $this->assertStringNotContainsString('Movimento di aprile', $csv);
    }

    public function test_il_saldo_progressivo_arriva_al_saldo_finale(): void
    {
        $righe = $this->righeMovimenti($this->contenuto(
            $this->scarica(['periodo' => 'mese', 'mese' => '2026-03', 'formato' => 'csv']),
        ));

        $this->assertCount(3, $righe);

        // Ultima colonna = saldo progressivo: 100,00 → 70,00 → 120,00.
        $saldi = array_map(fn (array $r) => end($r), $righe);
        $this->assertSame(['100,00', '70,00', '120,00'], $saldi);
    }

    public function test_il_csv_distingue_entrate_e_uscite_in_due_colonne(): void
    {
        $righe = $this->righeMovimenti($this->contenuto(
            $this->scarica(['periodo' => 'mese', 'mese' => '2026-03', 'formato' => 'csv']),
        ));

        // Colonne: Data;Riferimento;Tipologia;Controparte;Causale;Entrate;Uscite;Saldo
        $this->assertSame('100,00', $righe[0][5]);
        $this->assertSame('', $righe[0][6]);
        $this->assertSame('', $righe[1][5]);
        $this->assertSame('30,00', $righe[1][6]);
    }

    // ── La prima nota ───────────────────────────────────────────────────────

    public function test_la_prima_nota_e_in_partita_doppia(): void
    {
        $csv = $this->contenuto($this->scarica([
            'periodo' => 'mese', 'mese' => '2026-03', 'formato' => 'prima-nota',
        ]));

        $intestazione = str_getcsv(explode("\n", str_replace("\xEF\xBB\xBF", '', $csv))[0], ';', '"', '\\');
        $this->assertSame(
            ['Data', 'N. Documento', 'Descrizione / Causale', 'Conto Dare', 'Importo Dare (KY)', 'Conto Avere', 'Importo Avere (KY)', 'Tipo operazione'],
            $intestazione,
        );
        $this->assertStringContainsString('Cassa KY', $csv);
        $this->assertStringContainsString('Clienti - Bianchi Spa', $csv);
        $this->assertStringContainsString('Fornitori - Bianchi Spa', $csv);
        $this->assertStringNotContainsString('Movimento di aprile', $csv);
    }

    public function test_i_tre_formati_coprono_gli_stessi_movimenti(): void
    {
        $parametri = ['periodo' => 'trimestre', 'trimestre' => '2026-T1'];

        $csv       = $this->righeMovimenti($this->contenuto($this->scarica($parametri + ['formato' => 'csv'])));
        $primaNota = $this->contenuto($this->scarica($parametri + ['formato' => 'prima-nota']));

        // Prima nota: una riga per movimento, piu' l'intestazione.
        $righePrimaNota = array_filter(explode("\n", trim($primaNota)));

        $this->assertCount(3, $csv);
        $this->assertCount(4, $righePrimaNota);
        $this->scarica($parametri)->assertOk();
    }

    // ── Chi puo' scaricare cosa ─────────────────────────────────────────────

    public function test_un_conto_altrui_passato_in_query_non_cambia_l_estratto(): void
    {
        // ?conto= si accetta solo se e' il proprio conto o un suo sottoconto.
        $response = $this->scarica([
            'periodo' => 'mese', 'mese' => '2026-03', 'conto' => $this->controparte->id,
        ]);

        $response->assertOk();
        $this->assertStringContainsString(
            'estratto-conto-rossi-srl-',
            (string) $response->headers->get('Content-Disposition'),
        );
    }

    public function test_l_admin_scarica_l_estratto_di_qualsiasi_conto_in_ogni_formato(): void
    {
        $admin = $this->utente('admin');
        $admin->forceFill(['is_super_admin' => true])->save();

        foreach (['pdf', 'csv', 'prima-nota'] as $formato) {
            $this->actingAs($admin)
                ->get(route('admin.accounts.statement', [
                    'account' => $this->conto->id,
                    'periodo' => 'mese',
                    'mese'    => '2026-03',
                    'formato' => $formato,
                ]))
                ->assertOk();
        }
    }

    public function test_chi_non_e_admin_non_usa_la_rotta_admin(): void
    {
        $this->actingAs($this->utente)
            ->get(route('admin.accounts.statement', ['account' => $this->conto->id]))
            ->assertForbidden();
    }

    public function test_il_broker_scarica_l_estratto_del_cliente_che_segue(): void
    {
        $broker = $this->utente('broker');
        $broker->forceFill(['role' => 'broker'])->save();
        $this->azienda->forceFill(['broker_user_id' => $broker->id])->save();

        $response = $this->actingAs($broker)->get(route('broker.clients.statement', [
            'company' => $this->azienda->id,
            'periodo' => 'mese',
            'mese'    => '2026-03',
        ]));

        $response->assertOk();
        // La prova che non e' l'estratto del broker: il file e' intestato al cliente.
        $this->assertStringContainsString(
            'estratto-conto-rossi-srl-2026-03.pdf',
            (string) $response->headers->get('Content-Disposition'),
        );
    }

    public function test_il_broker_non_scarica_l_estratto_di_un_azienda_che_non_segue(): void
    {
        $broker = $this->utente('altro-broker');
        $broker->forceFill(['role' => 'broker'])->save();

        $this->actingAs($broker)
            ->get(route('broker.clients.statement', ['company' => $this->azienda->id]))
            ->assertForbidden();
    }

    public function test_chi_non_e_broker_non_passa_dalla_rotta_broker(): void
    {
        $this->actingAs($this->utente)
            ->get(route('broker.clients.statement', ['company' => $this->azienda->id]))
            ->assertForbidden();
    }

    public function test_senza_autenticazione_si_finisce_al_login(): void
    {
        $this->get(route('portal.statement'))->assertRedirect(route('login'));
        $this->get(route('portal.statement.download'))->assertRedirect(route('login'));
    }

    // ── Helper ──────────────────────────────────────────────────────────────

    private function scarica(array $parametri): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->utente)->get(route('portal.statement.download', $parametri));
    }

    private function contenuto(\Illuminate\Testing\TestResponse $response): string
    {
        return $response->streamedContent();
    }

    /** Solo le righe dei movimenti: salta preambolo e intestazione di colonna. */
    private function righeMovimenti(string $csv): array
    {
        $righe  = explode("\n", str_replace("\r", '', trim($csv)));
        $inizio = null;

        foreach ($righe as $i => $riga) {
            if (str_starts_with($riga, 'Data;')) {
                $inizio = $i + 1;
                break;
            }
        }

        $this->assertNotNull($inizio, 'Intestazione di colonna non trovata nel CSV.');

        return array_values(array_map(
            fn (string $riga) => str_getcsv($riga, ';', '"', '\\'),
            array_filter(array_slice($righe, $inizio), fn (string $r) => trim($r) !== ''),
        ));
    }

    /** Le righe di preambolo del CSV (etichetta => valore), fino alla riga vuota. */
    private function intestazione(string $csv): array
    {
        $out = [];

        foreach (explode("\n", str_replace(["\r", "\xEF\xBB\xBF"], '', $csv)) as $riga) {
            if (trim($riga) === '' || str_starts_with($riga, 'Data;')) {
                break;
            }

            $campi = str_getcsv($riga, ';', '"', '\\');

            if (count($campi) >= 2) {
                $out[$campi[0]] = $campi[1];
            }
        }

        return $out;
    }

    private function utente(string $prefisso, ?int $companyId = null): User
    {
        return User::create([
            'name'                => ucfirst($prefisso),
            'email'               => $prefisso . '-' . Str::random(8) . '@test.test',
            'password'            => 'secret123',
            'account_holder_type' => $companyId ? 'company' : 'private',
            'company_id'          => $companyId,
            'is_active'           => true,
            'is_super_admin'      => false,
            'email_verified_at'   => now(),
            'contract_signed_at'  => now(),
        ]);
    }

    private function movimento(string $giorno, int $importo, bool $entrata, string $causale, int $saldoDopo): void
    {
        $transfer = Transfer::create([
            'from_account_id' => $entrata ? $this->controparte->id : $this->conto->id,
            'to_account_id'   => $entrata ? $this->conto->id : $this->controparte->id,
            'amount'          => $importo,
            'status'          => 'booked',
            'kind'            => 'trade_payment',
            'description'     => $causale,
            'booked_at'       => $giorno . ' 10:00:00',
        ]);

        LedgerEntry::create([
            'transfer_id'   => $transfer->id,
            'account_id'    => $this->conto->id,
            'direction'     => $entrata ? 'credit' : 'debit',
            'amount'        => $importo,
            'balance_after' => $saldoDopo,
            'posted_at'     => $giorno . ' 10:00:00',
        ]);
    }
}
