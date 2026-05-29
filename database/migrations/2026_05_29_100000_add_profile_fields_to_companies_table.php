<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('tagline', 160)->nullable()->after('description');
            $table->string('city', 100)->nullable()->after('tagline');
            $table->string('linkedin_url', 255)->nullable()->after('website');
            $table->string('instagram_url', 255)->nullable()->after('linkedin_url');
            $table->string('facebook_url', 255)->nullable()->after('instagram_url');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['tagline', 'city', 'linkedin_url', 'instagram_url', 'facebook_url']);
        });
    }
};
