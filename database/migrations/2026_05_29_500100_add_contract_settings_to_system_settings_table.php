<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('system_settings', function (Blueprint $table) {
            $table->boolean('contract_force_sign')->default(false)->after('footer_text');
            $table->date('contract_required_from')->nullable()->after('contract_force_sign');
        });
    }

    public function down(): void
    {
        Schema::table('system_settings', function (Blueprint $table) {
            $table->dropColumn(['contract_force_sign', 'contract_required_from']);
        });
    }
};
