<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('contract_signed_at')->nullable()->after('remember_token');
            $table->char('contract_otp', 6)->nullable()->after('contract_signed_at');
            $table->timestamp('contract_otp_expires_at')->nullable()->after('contract_otp');
            $table->timestamp('contract_postponed_at')->nullable()->after('contract_otp_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['contract_signed_at', 'contract_otp', 'contract_otp_expires_at', 'contract_postponed_at']);
        });
    }
};
