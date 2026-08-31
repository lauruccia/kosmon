<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Niente ->after('approved_at'): `approved_at` e' una colonna di
            // COMPANIES, non di users, e su MySQL questo diventa un errore 1054
            // che ferma la migrate. SQLite ignora del tutto ->after(), quindi
            // in dev e nei test non e' mai emerso (B7, 31/08). La posizione
            // della colonna e' comunque cosmetica.
            $table->timestamp('tutorial_shown_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('tutorial_shown_at');
        });
    }
};
