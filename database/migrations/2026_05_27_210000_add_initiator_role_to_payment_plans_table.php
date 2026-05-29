<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_plans', function (Blueprint $table) {
            // Chi ha proposto il piano:
            //   'debtor'   = l'acquirente/pagante ha chiesto di pagare a rate (Logica B)
            //   'creditor' = il venditore/creditore ha offerto le rate (Logica A)
            $table->string('initiator_role', 10)->default('debtor')->after('initiated_by');
        });
    }

    public function down(): void
    {
        Schema::table('payment_plans', function (Blueprint $table) {
            $table->dropColumn('initiator_role');
        });
    }
};
