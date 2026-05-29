<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cashback_rules', function (Blueprint $table) {
            // 'all' = tutti, 'company' = solo aziende, 'personal' = solo privati, 'specific_user' = utente specifico
            $table->string('target_type', 20)->default('all')->after('applicable_kinds');
            $table->foreignId('target_user_id')->nullable()->after('target_type')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('cashback_rules', function (Blueprint $table) {
            $table->dropForeign(['target_user_id']);
            $table->dropColumn(['target_type', 'target_user_id']);
        });
    }
};
