<?php

use App\Support\SchemaIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('sector')->nullable()->after('name');
            $table->index('sector');
        });
    }

    public function down(): void
    {
        // `companies_sector_index` puo' essere gia' stato tolto dal down() di
        // 2026_05_26_100000: `dropIndex` secco darebbe 1091 (B7, 31/08).
        SchemaIndex::dropIfExists('companies', 'companies_sector_index');

        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('sector');
        });
    }
};
