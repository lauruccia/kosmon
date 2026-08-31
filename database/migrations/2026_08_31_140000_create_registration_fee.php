<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quota di iscrizione per i privati (richiesta di Laura del 31/08/2026).
 *
 * Chi si registra come privato paga una quota una tantum al circuito:
 *   - in EURO (Stripe, PayPal, bonifico): i soldi veri vanno a KNM e
 *     l'utente riceve l'equivalente in KY sul proprio conto — e' di fatto
 *     una ricarica obbligatoria, identica all'acquisto di una KYCard.
 *   - in KY: il conto va SOTTO di quell'importo e i KY finiscono sul conto
 *     di sistema (KNM). Si "recupera" invitando qualcuno, con i bonus
 *     segnalazione che gia' esistono (vedi ReferralBonusService).
 *
 * TRE SCELTE DI SCHEMA, tutte volute:
 *
 * 1. `users.registration_fee_due_cents` e' uno SNAPSHOT, non un puntatore
 *    all'impostazione. Serve perche' la quota vale solo per i NUOVI privati
 *    (decisione di Laura): NULL = questo utente non deve niente, ed e' il
 *    valore che si portano dietro tutti quelli che ci sono gia'. E perche'
 *    se domani l'admin porta la quota da 30 a 50, chi si e' registrato a 30
 *    deve continuare a dovere 30.
 *
 * 2. `users.registration_fee_ky_allowance_cents` e' il fido AGGIUNTIVO
 *    concesso a chi paga in KY. Senza, il -30 mangerebbe il fido concesso
 *    dall'admin (e per un privato nuovo, che di fido ne ha zero, l'addebito
 *    sarebbe proprio rifiutato dal motore). Decisione di Laura: il conto
 *    resta utilizzabile e il fido si somma al debito — fido 50 => -80.
 *
 * 3. I quattro metodi di pagamento sono quattro colonne booleane e non un
 *    JSON: kmoney gira su MariaDB e kosmopay su MySQL (vedi la nota del
 *    27/08 sui due database) e le colonne semplici sono l'unica cosa che si
 *    comporta identica su entrambi.
 *
 * NOTA PRODUZIONE: qui le migration non vengono eseguite (nota del 14/08).
 * L'equivalente SQL ri-eseguibile e' in
 * database/sql/2026_08_31_registration_fee.sql.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('system_settings', function (Blueprint $table): void {
            if (! Schema::hasColumn('system_settings', 'registration_fee_enabled')) {
                $table->boolean('registration_fee_enabled')->default(false);
            }
            if (! Schema::hasColumn('system_settings', 'registration_fee_amount_cents')) {
                $table->unsignedInteger('registration_fee_amount_cents')->default(3000);
            }
            if (! Schema::hasColumn('system_settings', 'registration_fee_stripe_enabled')) {
                $table->boolean('registration_fee_stripe_enabled')->default(true);
            }
            if (! Schema::hasColumn('system_settings', 'registration_fee_paypal_enabled')) {
                $table->boolean('registration_fee_paypal_enabled')->default(true);
            }
            if (! Schema::hasColumn('system_settings', 'registration_fee_bank_transfer_enabled')) {
                $table->boolean('registration_fee_bank_transfer_enabled')->default(true);
            }
            if (! Schema::hasColumn('system_settings', 'registration_fee_ky_enabled')) {
                $table->boolean('registration_fee_ky_enabled')->default(true);
            }
        });

        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'registration_fee_due_cents')) {
                $table->unsignedInteger('registration_fee_due_cents')->nullable();
            }
            if (! Schema::hasColumn('users', 'registration_fee_paid_at')) {
                $table->timestamp('registration_fee_paid_at')->nullable();
            }
            if (! Schema::hasColumn('users', 'registration_fee_ky_allowance_cents')) {
                $table->unsignedInteger('registration_fee_ky_allowance_cents')->default(0);
            }
        });

        if (! Schema::hasTable('registration_fee_payments')) {
            Schema::create('registration_fee_payments', function (Blueprint $table): void {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->unsignedBigInteger('account_id')->nullable();
                // Stesso importo per i due mondi: la quota e' alla pari
                // (30 EUR <-> 30 KY). Due colonne perche' se un giorno il
                // cambio non fosse piu' 1:1 il dato storico resta leggibile.
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
        Schema::dropIfExists('registration_fee_payments');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn([
                'registration_fee_due_cents',
                'registration_fee_paid_at',
                'registration_fee_ky_allowance_cents',
            ]);
        });

        Schema::table('system_settings', function (Blueprint $table): void {
            $table->dropColumn([
                'registration_fee_enabled',
                'registration_fee_amount_cents',
                'registration_fee_stripe_enabled',
                'registration_fee_paypal_enabled',
                'registration_fee_bank_transfer_enabled',
                'registration_fee_ky_enabled',
            ]);
        });
    }
};
