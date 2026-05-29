<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('balance_alerts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();

            // Soglia in centesimi KY (es. 5000 = 50.00 KY)
            $table->unsignedBigInteger('threshold_amount');

            // Canali di notifica
            $table->boolean('notify_email')->default(true);
            $table->boolean('notify_inapp')->default(true);

            // Cooldown: evita spam (default 24h)
            $table->unsignedTinyInteger('cooldown_hours')->default(24);

            // Ultima volta che l'alert ha sparato
            $table->timestamp('last_triggered_at')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('balance_alerts');
    }
};
