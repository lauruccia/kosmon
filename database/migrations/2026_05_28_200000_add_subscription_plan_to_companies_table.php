<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->enum('subscription_plan', ['ecommerce', 'vetrina', 'biglietto', 'anagrafica'])
                  ->nullable()
                  ->default(null)
                  ->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropColumn('subscription_plan');
        });
    }
};
