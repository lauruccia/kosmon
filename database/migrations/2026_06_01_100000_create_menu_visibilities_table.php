<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menu_visibilities', function (Blueprint $table) {
            $table->id();

            // La voce di menu identificata da uno slug (es. 'wallet', 'fido', 'incasso-qr')
            $table->string('menu_item_key', 64)->index();

            // Tipo di scope: 'global' | 'account_type' | 'company' | 'user'
            $table->string('scope_type', 20);

            // Per scope_type = 'account_type': 'private' o 'company'
            $table->string('account_type', 20)->nullable();

            // Per scope_type = 'company': company.id — per scope_type = 'user': user.id
            $table->unsignedBigInteger('scope_id')->nullable()->index();

            // true = visibile, false = nascosta
            $table->boolean('visible')->default(true);

            $table->timestamps();

            // Un solo record per combinazione univoca
            $table->unique(['menu_item_key', 'scope_type', 'account_type', 'scope_id'], 'menu_vis_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_visibilities');
    }
};
