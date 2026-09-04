<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * QUOTA DI APERTURA CONTO PER LE AZIENDE (richiesta di Laura del 03/09/2026):
 * chi si registra come azienda paga una quota una tantum — 600,00 EUR come
 * importo di partenza — per poter operare con il conto.
 *
 * E' la TERZA quota del circuito, dopo quella di iscrizione dei privati
 * (2026_08_31_140000) e quella per il codice agente (2026_08_31_150000), ed e'
 * costruita sullo stesso motore: AbstractFeeService + FeeDefinition. Le
 * colonne sono separate, come lo sono per le altre due, perche' le tre quote
 * sono distinte e hanno stati indipendenti.
 *
 * LE QUATTRO DECISIONI DI LAURA (03/09/2026), che spiegano i default qui sotto:
 *
 *   1. In EURO non si emette nessun KY. I 600 sono il prezzo dell'apertura del
 *      conto, non una ricarica: KNM incassa e il conto dell'azienda non viene
 *      toccato. Stessa regola dei 480 del codice agente.
 *   2. La quota NON BLOCCA IL CONTO. L'azienda che non ha ancora saldato
 *      continua a pagare, incassare e comprare: vede il banner e riceve il
 *      sollecito, e nient'altro. E' il motivo per cui questa quota non compare
 *      in EnsureRegistrationFeePaid, e non e' una dimenticanza.
 *   3. Solo le aziende che si registrano DA QUANDO la quota e' accesa. Le
 *      ~1.200 anagrafiche importate dal vecchio sito hanno la colonna a NULL e
 *      non la devono; l'admin puo' metterla in carico a una alla volta dalla
 *      scheda utente.
 *   5. (04/09/2026) COSA RICEVE IN CAMBIO LO DECIDE L'ADMIN, e sono due leve
 *      distinte, ciascuna con un default globale qui e un ripiego per singola
 *      azienda su `users`:
 *        - chi paga in EURO riceve N KY sul conto, N deciso dall'admin
 *          (`company_account_fee_ky_credit_cents`; zero = niente). E' la
 *          correzione del punto 1: il circuito emette KY se e solo se quel
 *          numero e' maggiore di zero.
 *        - chi paga in KY va sotto, e l'admin decide se dargli il fido
 *          aggiuntivo pari alla quota (`company_account_fee_ky_allowance`,
 *          acceso di fabbrica) oppure fargliela mangiare dal fido che ha gia'.
 *
 *   4. Il pagamento con il SALDO KY nasce SPENTO: e' una concessione che
 *      l'admin accende dal backoffice quando vuole accettarlo, non un default.
 *      E' l'unica differenza nei default rispetto alle altre due quote.
 *
 * NOTA PRODUZIONE: qui le migration non vengono eseguite (nota del 14/08).
 * L'equivalente SQL e' in database/sql/2026_09_03_company_account_fee.sql.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('system_settings', function (Blueprint $table): void {
            if (! Schema::hasColumn('system_settings', 'company_account_fee_enabled')) {
                $table->boolean('company_account_fee_enabled')->default(false);
            }
            if (! Schema::hasColumn('system_settings', 'company_account_fee_amount_cents')) {
                $table->unsignedInteger('company_account_fee_amount_cents')->default(60000);
            }
            if (! Schema::hasColumn('system_settings', 'company_account_fee_stripe_enabled')) {
                $table->boolean('company_account_fee_stripe_enabled')->default(true);
            }
            if (! Schema::hasColumn('system_settings', 'company_account_fee_paypal_enabled')) {
                $table->boolean('company_account_fee_paypal_enabled')->default(true);
            }
            if (! Schema::hasColumn('system_settings', 'company_account_fee_bank_transfer_enabled')) {
                $table->boolean('company_account_fee_bank_transfer_enabled')->default(true);
            }
            // Unico default diverso dalle altre due quote, e voluto: il
            // pagamento in KY dell'apertura conto e' una concessione che
            // l'admin accende, non una strada aperta da sola.
            if (! Schema::hasColumn('system_settings', 'company_account_fee_ky_enabled')) {
                $table->boolean('company_account_fee_ky_enabled')->default(false);
            }
            // Quanti KY riceve chi paga in EURO. Zero = niente, ed e' il
            // valore di partenza: nessun KY si conia finche' non lo scrive
            // qualcuno.
            if (! Schema::hasColumn('system_settings', 'company_account_fee_ky_credit_cents')) {
                $table->unsignedInteger('company_account_fee_ky_credit_cents')->default(0);
            }
            // Chi paga in KY riceve il fido aggiuntivo pari alla quota? Acceso
            // di fabbrica: e' come si comportano le altre due quote, e da
            // spento la quota si mangia il fido che l'azienda ha gia'.
            if (! Schema::hasColumn('system_settings', 'company_account_fee_ky_allowance')) {
                $table->boolean('company_account_fee_ky_allowance')->default(true);
            }
        });

        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'company_account_fee_due_cents')) {
                $table->unsignedInteger('company_account_fee_due_cents')->nullable();
            }
            if (! Schema::hasColumn('users', 'company_account_fee_paid_at')) {
                $table->timestamp('company_account_fee_paid_at')->nullable();
            }
            if (! Schema::hasColumn('users', 'company_account_fee_ky_allowance_cents')) {
                $table->unsignedInteger('company_account_fee_ky_allowance_cents')->default(0);
            }
            // I due ripieghi per singola azienda (04/09/2026). NULL = segui il
            // default del pannello; un valore = per questa azienda vale questo.
            // Sono colonne separate da `..._ky_allowance_cents` qui sopra, che
            // e' un'altra cosa: quello e' il fido REALMENTE concesso a chi ha
            // gia' pagato in KY, questi due sono la decisione dell'admin su
            // cosa dare.
            if (! Schema::hasColumn('users', 'company_account_fee_ky_credit_override_cents')) {
                $table->unsignedInteger('company_account_fee_ky_credit_override_cents')->nullable();
            }
            if (! Schema::hasColumn('users', 'company_account_fee_ky_allowance_override')) {
                $table->boolean('company_account_fee_ky_allowance_override')->nullable();
            }
        });

        if (! Schema::hasTable('company_account_fee_payments')) {
            Schema::create('company_account_fee_payments', function (Blueprint $table): void {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->unsignedBigInteger('account_id')->nullable();
                $table->unsignedInteger('amount_eur_cents');
                $table->unsignedInteger('ky_amount');
                $table->string('status', 32)->default('pending');
                $table->string('payment_method', 32);
                $table->string('stripe_checkout_session_id')->nullable();
                $table->string('stripe_payment_intent_id')->nullable();
                $table->string('paypal_order_id')->nullable();
                $table->unsignedBigInteger('transfer_id')->nullable();
                $table->text('admin_notes')->nullable();
                $table->unsignedBigInteger('confirmed_by')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();

                $table->index(['user_id', 'status']);
                $table->index('status');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('company_account_fee_payments');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn([
                'company_account_fee_due_cents',
                'company_account_fee_paid_at',
                'company_account_fee_ky_allowance_cents',
                'company_account_fee_ky_credit_override_cents',
                'company_account_fee_ky_allowance_override',
            ]);
        });

        Schema::table('system_settings', function (Blueprint $table): void {
            $table->dropColumn([
                'company_account_fee_enabled',
                'company_account_fee_amount_cents',
                'company_account_fee_stripe_enabled',
                'company_account_fee_paypal_enabled',
                'company_account_fee_bank_transfer_enabled',
                'company_account_fee_ky_enabled',
                'company_account_fee_ky_credit_cents',
                'company_account_fee_ky_allowance',
            ]);
        });
    }
};
