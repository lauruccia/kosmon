<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * LE DUE LEVE DIVENTANO DI TUTTE E TRE LE QUOTE (richiesta di Laura del
 * 04/09/2026: «un'unica pagina dove attivare o disattivare le 3 quote,
 * impostare l'importo, metodi di pagamento, fido ed eventuale restituzione in
 * ky per chi paga in euro con importo deciso da me»).
 *
 * Il 04/09 le due leve — quanti KY riceve chi paga in EURO, e se chi paga in
 * KY riceve il fido aggiuntivo — erano nate per la sola quota di apertura
 * conto (2026_09_03_120000). Qui diventano le stesse per tutte e tre, con gli
 * stessi nomi e lo stesso significato, e il motore comune le legge da un posto
 * solo. La quota di apertura conto ha gia' le sue quattro colonne e non le
 * ritrova qui: questa migrazione porta le otto mancanti delle altre due.
 *
 * IL VALORE DI PARTENZA DELLA RESTITUZIONE, ed e' la riga che conta:
 *
 *   - PRIVATI: pari all'importo della quota (3.000 = 30,00). Non e' una scelta
 *     estetica. Fino a oggi il privato che pagava in euro riceveva SEMPRE tanti
 *     KY quanti ne aveva pagati — in euro la quota di iscrizione non e' un
 *     costo, e' un acquisto di KY — e il numero era cablato nel codice
 *     (RegistrationFeeService::settleEuroPayment usava l'importo del
 *     pagamento). Diventando un'impostazione, se partisse da zero il primo
 *     privato che paga dopo il rilascio verserebbe 30 euro senza ricevere
 *     niente. L'UPDATE del BLOCCO 3 lo aggancia all'importo davvero
 *     configurato, che potrebbe non essere piu' 30,00.
 *   - AGENTI: zero. I 480 sono il prezzo della nomina, KNM incassa e il conto
 *     dell'agente non viene toccato: e' il comportamento di oggi, e zero e'
 *     esattamente cio' che lo conserva.
 *
 * DA QUI IN POI LA RESTITUZIONE NON SEGUE PIU' L'IMPORTO DA SOLA. Alzando la
 * quota dei privati a 50,00 la restituzione resta a quello che c'e' scritto
 * finche' non la si cambia anche li'. E' il prezzo di poterla decidere, ed e'
 * la ragione per cui la pagina delle quote lo dice a schermo.
 *
 * IL FIDO nasce ACCESO su tutte e tre: e' come si comportano oggi, e da spento
 * la quota si mangia il fido che l'utente ha gia'.
 *
 * I RIPIEGHI PER SINGOLO UTENTE (`..._override_...`) nascono NULL, e NULL non
 * e' zero: vuol dire «segui il pannello». Scrivere 0 o «no» e' una decisione
 * presa per quella persona, che resta ferma anche se domani il default cambia.
 *
 * NOTA PRODUZIONE: qui le migration non vengono eseguite (nota del 14/08).
 * L'equivalente SQL e' in database/sql/2026_09_04_quote_leve_comuni.sql, e va
 * eseguito PRIMA del codice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('system_settings', function (Blueprint $table): void {
            // Privati. Il default di colonna e' 3.000 per il caso di un
            // database nuovo; sui database esistenti il valore giusto lo
            // scrive l'UPDATE qui sotto, che legge l'importo vero.
            if (! Schema::hasColumn('system_settings', 'registration_fee_ky_credit_cents')) {
                $table->unsignedInteger('registration_fee_ky_credit_cents')->default(3000);
            }
            if (! Schema::hasColumn('system_settings', 'registration_fee_ky_allowance')) {
                $table->boolean('registration_fee_ky_allowance')->default(true);
            }

            // Agenti. Zero: i 480 non hanno mai emesso un KY.
            if (! Schema::hasColumn('system_settings', 'agent_code_fee_ky_credit_cents')) {
                $table->unsignedInteger('agent_code_fee_ky_credit_cents')->default(0);
            }
            if (! Schema::hasColumn('system_settings', 'agent_code_fee_ky_allowance')) {
                $table->boolean('agent_code_fee_ky_allowance')->default(true);
            }
        });

        // La restituzione dei privati agganciata all'importo CONFIGURATO, non
        // ai 30,00 del default: su un database dove Laura ha gia' messo un
        // altro importo, 3.000 sarebbe un numero sbagliato scritto a mano.
        //
        // Solo dove la colonna e' rimasta al default, cosi' rieseguire la
        // migrazione non sovrascrive una scelta gia' fatta dall'admin.
        DB::table('system_settings')
            ->where('registration_fee_ky_credit_cents', 3000)
            ->update([
                'registration_fee_ky_credit_cents' => DB::raw('`registration_fee_amount_cents`'),
            ]);

        Schema::table('users', function (Blueprint $table): void {
            // I ripieghi per singolo utente. NULL = segui il pannello.
            //
            // Da non confondere con `..._ky_allowance_cents`, che esiste gia'
            // su tutte e tre le quote ed e' un'altra cosa: quello e' il fido
            // REALMENTE concesso a chi ha gia' pagato in KY, questi due sono
            // la decisione dell'admin su cosa dare la prossima volta.
            if (! Schema::hasColumn('users', 'registration_fee_ky_credit_override_cents')) {
                $table->unsignedInteger('registration_fee_ky_credit_override_cents')->nullable();
            }
            if (! Schema::hasColumn('users', 'registration_fee_ky_allowance_override')) {
                $table->boolean('registration_fee_ky_allowance_override')->nullable();
            }
            if (! Schema::hasColumn('users', 'agent_code_fee_ky_credit_override_cents')) {
                $table->unsignedInteger('agent_code_fee_ky_credit_override_cents')->nullable();
            }
            if (! Schema::hasColumn('users', 'agent_code_fee_ky_allowance_override')) {
                $table->boolean('agent_code_fee_ky_allowance_override')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn([
                'registration_fee_ky_credit_override_cents',
                'registration_fee_ky_allowance_override',
                'agent_code_fee_ky_credit_override_cents',
                'agent_code_fee_ky_allowance_override',
            ]);
        });

        Schema::table('system_settings', function (Blueprint $table): void {
            $table->dropColumn([
                'registration_fee_ky_credit_cents',
                'registration_fee_ky_allowance',
                'agent_code_fee_ky_credit_cents',
                'agent_code_fee_ky_allowance',
            ]);
        });
    }
};
