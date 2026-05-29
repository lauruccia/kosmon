<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transfers', function (Blueprint $table) {
            $table->foreignId('reversed_transfer_id')->nullable()->after('idempotency_key')->constrained('transfers')->nullOnDelete();
            $table->timestamp('refunded_at')->nullable()->after('reversed_transfer_id');
            $table->string('admin_action')->nullable()->after('refunded_at');
            $table->index(['reversed_transfer_id']);
        });
    }

    public function down(): void
    {
        Schema::table('transfers', function (Blueprint $table) {
            $table->dropIndex(['reversed_transfer_id']);
            $table->dropConstrainedForeignId('reversed_transfer_id');
            $table->dropColumn(['refunded_at', 'admin_action']);
        });
    }
};
