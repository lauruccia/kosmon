<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scheduled_payments', function (Blueprint $table) {
            // UUID condiviso da tutte le rate dello stesso gruppo ricorrente
            $table->string('recurrence_group', 36)->nullable()->index()->after('created_by');
            // Indice rata (1, 2, 3...) e totale rate del gruppo
            $table->unsignedTinyInteger('recurrence_index')->nullable()->after('recurrence_group');
            $table->unsignedTinyInteger('recurrence_total')->nullable()->after('recurrence_index');
            // Frequenza: monthly | weekly | biweekly
            $table->string('recurrence_type', 20)->nullable()->after('recurrence_total');
        });
    }

    public function down(): void
    {
        Schema::table('scheduled_payments', function (Blueprint $table) {
            $table->dropIndex(['recurrence_group']);
            $table->dropColumn(['recurrence_group', 'recurrence_index', 'recurrence_total', 'recurrence_type']);
        });
    }
};
