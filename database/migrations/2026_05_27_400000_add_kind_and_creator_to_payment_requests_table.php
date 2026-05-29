<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_requests', function (Blueprint $table) {
            // 'qr_dynamic' = QR da punto cassa (scade in 10 min)
            // 'link'       = Link condivisibile (scade in 7 giorni)
            $table->string('kind', 20)->default('qr_dynamic')->after('token');

            // Chi ha generato il link (nullable per retrocompatibilità)
            $table->foreignId('created_by_user_id')
                ->nullable()
                ->after('kind')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payment_requests', function (Blueprint $table) {
            $table->dropForeign(['created_by_user_id']);
            $table->dropColumn(['kind', 'created_by_user_id']);
        });
    }
};
