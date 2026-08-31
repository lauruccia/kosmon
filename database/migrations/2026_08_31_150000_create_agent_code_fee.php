<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quota per il CODICE AGENTE (richiesta di Laura del 31/08/2026): chi vuole
 * operare come agente KNM paga una quota una tantum prima di poter firmare il
 * contratto di nomina.
 *
 * DELIBERATAMENTE SEPARATA dalla quota di iscrizione dei privati, che pure le
 * somiglia molto (vedi 2026_08_31_140000_create_registration_fee). Scelta di
 * Laura: quella e' gia' pronta per la produzione e non si tocca. Le due quote
 * saranno ATTIVE INSIEME, quindi un privato che diventa agente ne paga due,
 * distinte, con due stati indipendenti — chi ha gia' saldato i 30 non ha
 * saldato niente dei 480.
 *
 * Le differenze vere rispetto alla quota privati, non cosmetiche:
 *   - il debito nasce all'APPROVAZIONE della richiesta agente, non alla
 *     registrazione, e da tutte e tre le porte che portano ad "approved";
 *   - chi paga in EURO non riceve KY in cambio (decisione di Laura): i 480
 *     sono il prezzo del codice, non una ricarica. Chi paga in KY invece va
 *     sotto, esattamente come per i 30.
 *
 * NOTA PRODUZIONE: qui le migration non vengono eseguite (nota del 14/08).
 * L'equivalente SQL e' in database/sql/2026_08_31_agent_code_fee.sql.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('system_settings', function (Blueprint $table): void {
            if (! Schema::hasColumn('system_settings', 'agent_code_fee_enabled')) {
                $table->boolean('agent_code_fee_enabled')->default(false);
            }
            if (! Schema::hasColumn('system_settings', 'agent_code_fee_amount_cents')) {
                $table->unsignedInteger('agent_code_fee_amount_cents')->default(48000);
            }
            if (! Schema::hasColumn('system_settings', 'agent_code_fee_stripe_enabled')) {
                $table->boolean('agent_code_fee_stripe_enabled')->default(true);
            }
            if (! Schema::hasColumn('system_settings', 'agent_code_fee_paypal_enabled')) {
                $table->boolean('agent_code_fee_paypal_enabled')->default(true);
            }
            if (! Schema::hasColumn('system_settings', 'agent_code_fee_bank_transfer_enabled')) {
                $table->boolean('agent_code_fee_bank_transfer_enabled')->default(true);
            }
            if (! Schema::hasColumn('system_settings', 'agent_code_fee_ky_enabled')) {
                $table->boolean('agent_code_fee_ky_enabled')->default(true);
            }
        });

        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'agent_code_fee_due_cents')) {
                $table->unsignedInteger('agent_code_fee_due_cents')->nullable();
            }
            if (! Schema::hasColumn('users', 'agent_code_fee_paid_at')) {
                $table->timestamp('agent_code_fee_paid_at')->nullable();
            }
            if (! Schema::hasColumn('users', 'agent_code_fee_ky_allowance_cents')) {
                $table->unsignedInteger('agent_code_fee_ky_allowance_cents')->default(0);
            }
        });

        if (! Schema::hasTable('agent_code_fee_payments')) {
            Schema::create('agent_code_fee_payments', function (Blueprint $table): void {
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
        Schema::dropIfExists('agent_code_fee_payments');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn([
                'agent_code_fee_due_cents',
                'agent_code_fee_paid_at',
                'agent_code_fee_ky_allowance_cents',
            ]);
        });

        Schema::table('system_settings', function (Blueprint $table): void {
            $table->dropColumn([
                'agent_code_fee_enabled',
                'agent_code_fee_amount_cents',
                'agent_code_fee_stripe_enabled',
                'agent_code_fee_paypal_enabled',
                'agent_code_fee_bank_transfer_enabled',
                'agent_code_fee_ky_enabled',
            ]);
        });
    }
};
