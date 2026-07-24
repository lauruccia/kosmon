<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->foreignId('plan_id')->nullable()->after('subscription_plan')
                  ->constrained('plans')->nullOnDelete();
        });

        // Backfill: ogni azienda con un subscription_plan valorizzato viene
        // agganciata al piano dinamico corrispondente (stesso slug).
        $plans = DB::table('plans')->pluck('id', 'slug');
        foreach ($plans as $slug => $planId) {
            DB::table('companies')->where('subscription_plan', $slug)->update(['plan_id' => $planId]);
        }

        Schema::table('companies', function (Blueprint $table): void {
            $table->dropColumn('subscription_plan');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->enum('subscription_plan', ['ecommerce', 'vetrina', 'biglietto', 'anagrafica'])
                  ->nullable()->default(null)->after('status');
        });

        $plans = DB::table('plans')->pluck('slug', 'id');
        foreach ($plans as $planId => $slug) {
            DB::table('companies')->where('plan_id', $planId)->update(['subscription_plan' => $slug]);
        }

        Schema::table('companies', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('plan_id');
        });
    }
};
