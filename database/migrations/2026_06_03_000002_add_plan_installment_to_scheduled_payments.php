<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scheduled_payments', function (Blueprint $table) {
            // Collega la scheduled payment a una rata di un piano rateale.
            // Se valorizzato, l'esecuzione è delegata a PaymentPlanService::processInstallment().
            $table->foreignId('payment_plan_installment_id')
                  ->nullable()
                  ->after('recurrence_type')
                  ->constrained('payment_plan_installments')
                  ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('scheduled_payments', function (Blueprint $table) {
            $table->dropForeign(['payment_plan_installment_id']);
            $table->dropColumn('payment_plan_installment_id');
        });
    }
};
