<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_requests', function (Blueprint $table): void {
            $table->timestamp('reminder_24h_sent_at')->nullable()->after('paid_at');
            $table->timestamp('reminder_1h_sent_at')->nullable()->after('reminder_24h_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('payment_requests', function (Blueprint $table): void {
            $table->dropColumn(['reminder_24h_sent_at', 'reminder_1h_sent_at']);
        });
    }
};
