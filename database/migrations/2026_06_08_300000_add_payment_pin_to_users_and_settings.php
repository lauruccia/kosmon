<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // PIN di pagamento (SHA-256 del PIN inserito dall'utente, null = PIN non impostato)
        Schema::table('users', function (Blueprint $table) {
            $table->string('payment_pin_hash', 64)->nullable()->after('password');
        });

        // Soglia in centesimi: importi >= soglia richiedono PIN. null = PIN sempre richiesto se impostato.
        Schema::table('system_settings', function (Blueprint $table) {
            $table->unsignedInteger('payment_pin_threshold')->nullable()->after('payment_confirm_totp_threshold');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('payment_pin_hash');
        });
        Schema::table('system_settings', function (Blueprint $table) {
            $table->dropColumn('payment_pin_threshold');
        });
    }
};
