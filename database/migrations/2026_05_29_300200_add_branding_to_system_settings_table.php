<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('system_settings', function (Blueprint $table) {
            $table->string('circuit_name', 80)->default('KMoney')->after('code');
            $table->string('circuit_tagline', 160)->nullable()->after('circuit_name');
            $table->string('contact_email', 120)->nullable()->after('circuit_tagline');
            $table->string('contact_phone', 40)->nullable()->after('contact_email');
            $table->string('website_url', 200)->nullable()->after('contact_phone');
            $table->string('logo_path', 255)->nullable()->after('website_url');   // disk:public
            $table->string('primary_color', 7)->default('#3d5566')->after('logo_path');   // hex
            $table->string('accent_color', 7)->default('#4d7a52')->after('primary_color');
            $table->string('footer_text', 255)->nullable()->after('accent_color');
        });
    }

    public function down(): void
    {
        Schema::table('system_settings', function (Blueprint $table) {
            $table->dropColumn([
                'circuit_name', 'circuit_tagline', 'contact_email',
                'contact_phone', 'website_url', 'logo_path',
                'primary_color', 'accent_color', 'footer_text',
            ]);
        });
    }
};
