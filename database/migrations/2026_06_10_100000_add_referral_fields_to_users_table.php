<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('referral_code', 12)->nullable()->unique()->after('id');
            $table->unsignedBigInteger('referred_by_user_id')->nullable()->after('referral_code');
            $table->foreign('referred_by_user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropForeign(['referred_by_user_id']);
            $table->dropColumn(['referral_code', 'referred_by_user_id']);
        });
    }
};
