<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('system_settings', function (Blueprint $table) {
            $table->longText('contract_text')->nullable()->after('contract_required_from');
            $table->unsignedSmallInteger('contract_version')->default(1)->after('contract_text');
        });
    }

    public function down(): void
    {
        Schema::table('system_settings', function (Blueprint $table) {
            $table->dropColumn(['contract_text', 'contract_version']);
        });
    }
};
