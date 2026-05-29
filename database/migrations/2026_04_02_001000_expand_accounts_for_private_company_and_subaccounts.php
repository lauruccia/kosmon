<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'type']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('account_holder_type')->default('company')->after('company_id');
            $table->foreignId('managed_account_id')->nullable()->after('account_holder_type')->constrained('accounts')->nullOnDelete();
            $table->string('phone')->nullable()->after('email');
            $table->string('fiscal_code')->nullable()->after('phone');
        });

        Schema::table('accounts', function (Blueprint $table) {
            $table->foreignId('owner_user_id')->nullable()->after('company_id')->constrained('users')->nullOnDelete();
            $table->string('owner_type')->default('company')->after('owner_user_id');
            $table->foreignId('parent_account_id')->nullable()->after('owner_type')->constrained('accounts')->nullOnDelete();
            $table->foreignId('assigned_by_user_id')->nullable()->after('parent_account_id')->constrained('users')->nullOnDelete();
            $table->string('account_name')->nullable()->after('type');
            $table->bigInteger('spending_limit')->nullable()->after('pending_balance');
            $table->bigInteger('daily_outgoing_limit')->nullable()->after('spending_limit');
        });

        Schema::table('accounts', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->change();
            $table->index(['owner_type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropIndex(['owner_type', 'status']);
            $table->foreignId('company_id')->nullable(false)->change();
            $table->dropConstrainedForeignId('assigned_by_user_id');
            $table->dropConstrainedForeignId('parent_account_id');
            $table->dropConstrainedForeignId('owner_user_id');
            $table->dropColumn(['owner_type', 'account_name', 'spending_limit', 'daily_outgoing_limit']);
            $table->unique(['company_id', 'type']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('managed_account_id');
            $table->dropColumn(['account_holder_type', 'phone', 'fiscal_code']);
        });
    }
};
