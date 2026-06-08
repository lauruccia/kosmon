<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nfc_cards', function (Blueprint $table) {
            $table->string('tracking_code')->nullable()->after('notes');
            $table->string('shipping_carrier')->nullable()->after('tracking_code'); // es. BRT, GLS, Poste
            $table->timestamp('shipped_at')->nullable()->after('shipping_carrier');
        });
    }

    public function down(): void
    {
        Schema::table('nfc_cards', function (Blueprint $table) {
            $table->dropColumn(['tracking_code', 'shipping_carrier', 'shipped_at']);
        });
    }
};
