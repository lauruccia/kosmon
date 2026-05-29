<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_signatures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('contract_version')->default(1);
            $table->longText('contract_html_snapshot');
            $table->timestamp('signed_at');
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();

            $table->index('user_id');
            $table->index('company_id');
            $table->index('signed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_signatures');
    }
};
