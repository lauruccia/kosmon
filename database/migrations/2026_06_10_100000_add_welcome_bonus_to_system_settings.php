<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('system_settings', function (Blueprint $table) {
            $table->unsignedBigInteger('welcome_bonus_amount')
                ->default(0)
                ->after('payment_pin_threshold')
                ->comment('Bonus benvenuto in centesimi KY erogato dopo approvazione KYC. 0 = disabilitato.');
        });
    }

    public function down(): void
    {
        Schema::table('system_settings', function (Blueprint $table) {
            $table->dropColumn('welcome_bonus_amount');
        });
    }
};
