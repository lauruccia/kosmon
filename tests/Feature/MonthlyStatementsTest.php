<?php

namespace Tests\Feature;

use App\Jobs\SendMonthlyStatements;
use App\Models\Account;
use App\Models\Company;
use App\Models\User;
use App\Notifications\MonthlyStatementNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Il resoconto mensile e il freno agli invii (01/09/2026).
 *
 * Questi test non guardano il contenuto dell'email — guardano il RITMO, che
 * e' l'unica cosa che il 1 luglio 2026 ha fatto fallire 1060 invii su 1068:
 * mille notifiche disponibili nello stesso istante, e il server di posta che
 * le respinge in blocco perche' l'hosting ha un tetto orario.
 *
 * La proprieta' da difendere e' una sola: **due email non partono mai nello
 * stesso momento**, e la distanza fra loro dipende dal tetto configurato.
 */
class MonthlyStatementsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->freezeTime();
    }

    public function test_le_email_partono_diluite_e_non_tutte_insieme(): void
    {
        config(['kmoney.mail_max_per_hour' => 2]); // una ogni mezz'ora

        $utenti = [$this->contoConIntestatario(), $this->contoConIntestatario(), $this->contoConIntestatario()];

        (new SendMonthlyStatements())->handle();

        $attese = $this->atteseInSecondi($utenti);

        $this->assertSame([0, 1800, 3600], $attese);
    }

    public function test_col_freno_spento_partono_tutte_subito(): void
    {
        // Zero = nessun tetto. Resta possibile per chi ha un server di posta
        // che regge, ma e' una scelta esplicita, non il valore di default.
        config(['kmoney.mail_max_per_hour' => 0]);

        $utenti = [$this->contoConIntestatario(), $this->contoConIntestatario()];

        (new SendMonthlyStatements())->handle();

        $this->assertSame([0, 0], $this->atteseInSecondi($utenti));
    }

    public function test_chi_ha_spento_il_resoconto_non_lascia_un_buco_nella_cadenza(): void
    {
        config(['kmoney.mail_max_per_hour' => 2]);

        $primo   = $this->contoConIntestatario();
        $saltato = $this->contoConIntestatario();
        $ultimo  = $this->contoConIntestatario();

        // Ha disattivato il resoconto: non riceve niente, e soprattutto non
        // deve "consumare" il suo posto nella cadenza — altrimenti con meta'
        // degli utenti che hanno spento le notifiche il freno diluirebbe su
        // ore vuote e l'ultima email arriverebbe il giorno dopo.
        $saltato->forceFill(['notification_preferences' => ['monthly_statement' => []]])->save();

        (new SendMonthlyStatements())->handle();

        Notification::assertNothingSentTo($saltato);
        $this->assertSame([0, 1800], $this->atteseInSecondi([$primo, $ultimo]));
    }

    public function test_il_conto_senza_intestatario_non_fa_fallire_il_resto(): void
    {
        config(['kmoney.mail_max_per_hour' => 0]);

        $orfano = Account::create([
            'company_id'        => $this->azienda()->id,
            'owner_type'        => 'company',
            'type'              => 'member',
            'status'            => 'active',
            'available_balance' => 0,
        ]);

        $buono = $this->contoConIntestatario();

        (new SendMonthlyStatements())->handle();

        Notification::assertSentTo($buono, MonthlyStatementNotification::class);
        $this->assertNotNull($orfano->fresh(), 'Il conto orfano resta li\', semplicemente non riceve niente.');
    }

    // ─── Chi lo riceve (01/09/2026: tutti) ──────────────────────────────────

    public function test_anche_i_privati_ricevono_il_resoconto(): void
    {
        config(['kmoney.mail_max_per_hour' => 0]);

        // Il caso che questi test esistono per difendere: fino al 01/09 un
        // filtro sull'azienda teneva fuori TUTTI i conti dei privati, cioe' la
        // maggior parte del circuito, e il resoconto mensile non lo vedevano
        // mai. Non se n'era accorto nessuno perche' dal 1 luglio non partiva
        // comunque niente.
        $privato = $this->contoPrivato();

        (new SendMonthlyStatements())->handle();

        Notification::assertSentTo($privato, MonthlyStatementNotification::class);
    }

    public function test_l_azienda_col_kyc_non_approvato_resta_fuori(): void
    {
        config(['kmoney.mail_max_per_hour' => 0]);

        $ammessa = $this->contoConIntestatario();
        $esclusa = $this->contoConIntestatario(kyc: 'pending');

        (new SendMonthlyStatements())->handle();

        Notification::assertSentTo($ammessa, MonthlyStatementNotification::class);
        Notification::assertNothingSentTo($esclusa);
    }

    public function test_il_conto_chiuso_non_riceve_niente(): void
    {
        config(['kmoney.mail_max_per_hour' => 0]);

        $chiuso = $this->contoPrivato();
        Account::where('owner_user_id', $chiuso->id)->update(['status' => 'closed']);

        (new SendMonthlyStatements())->handle();

        Notification::assertNothingSentTo($chiuso);
    }

    public function test_l_utente_disattivato_non_riceve_niente(): void
    {
        config(['kmoney.mail_max_per_hour' => 0]);

        // Fra i privati importati ce ne sono di spenti: ogni email a un
        // indirizzo morto e' un rimbalzo che pesa sulla reputazione del
        // dominio, e con qualche migliaio di invii al mese non e' un dettaglio.
        $spento = $this->contoPrivato();
        $spento->forceFill(['is_active' => false])->save();

        (new SendMonthlyStatements())->handle();

        Notification::assertNothingSentTo($spento);
    }

    // ─── Aiutanti ───────────────────────────────────────────────────────────

    /**
     * I secondi di ritardo con cui e' stata messa in coda l'email di ognuno,
     * nell'ordine in cui sono passati gli utenti.
     *
     * @param  list<User>  $utenti
     * @return list<int>
     */
    private function atteseInSecondi(array $utenti): array
    {
        return array_map(function (User $utente): int {
            $secondi = null;

            Notification::assertSentTo(
                $utente,
                MonthlyStatementNotification::class,
                function (MonthlyStatementNotification $notifica) use (&$secondi): bool {
                    $secondi = $notifica->delay === null
                        ? 0
                        : (int) round(now()->diffInSeconds($notifica->delay, absolute: true));

                    return true;
                }
            );

            return $secondi ?? 0;
        }, $utenti);
    }

    private function contoPrivato(): User
    {
        $utente = User::create([
            'name'                => 'Privato ' . Str::random(4),
            'email'               => 'privato-' . Str::random(8) . '@test.test',
            'password'            => 'secret123',
            'account_holder_type' => 'private',
            'company_id'          => null,
            'role'                => 'private-member',
            'is_active'           => true,
            'is_super_admin'      => false,
            'email_verified_at'   => now(),
        ]);

        Account::create([
            'owner_user_id'     => $utente->id,
            'owner_type'        => 'private',
            'type'              => 'member',
            'status'            => 'active',
            'available_balance' => 5000,
        ]);

        return $utente;
    }

    private function contoConIntestatario(string $kyc = 'approved'): User
    {
        $azienda = $this->azienda($kyc);

        $utente = User::create([
            'name'                => 'Titolare ' . Str::random(4),
            'email'               => 'titolare-' . Str::random(8) . '@test.test',
            'password'            => 'secret123',
            'account_holder_type' => 'company',
            'company_id'          => $azienda->id,
            'role'                => 'owner',
            'is_active'           => true,
            'is_super_admin'      => false,
            'email_verified_at'   => now(),
        ]);

        Account::create([
            'company_id'        => $azienda->id,
            'owner_user_id'     => $utente->id,
            'owner_type'        => 'company',
            'type'              => 'member',
            'status'            => 'active',
            'available_balance' => 10000,
        ]);

        return $utente;
    }

    private function azienda(string $kyc = 'approved'): Company
    {
        $slug = 'azienda-' . Str::random(8);

        return Company::create([
            'name'          => 'Azienda ' . Str::random(4),
            'slug'          => $slug,
            'email'         => $slug . '@test.test',
            'status'        => 'active',
            'kyc_status'    => $kyc,
            'currency_code' => 'KY',
            'sector'        => 'servizi',
            'description'   => 'Azienda di test',
        ]);
    }
}
