<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('ky_cards', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');                          // es. "Starter Pack", "Gold 500"
            $table->string('description')->nullable();
            $table->unsignedInteger('price_eur_cents');      // prezzo in centesimi euro (es. 10000 = 100.00 EUR)
            // bonus_type: 'fixed' => ky_amount fisso; 'percentage' => bonus % sul valore nominale
            $table->enum('bonus_type', ['fixed', 'percentage'])->default('fixed');
            // Se fixed: quanti KY riceve il cliente (es. 125 = 125 KY)
            // Se percentage: quanti KY di base (= price_eur_cents / 100) + bonus %
            $table->unsignedBigInteger('ky_base_amount');    // KY nominali (= prezzo EUR in KY, 1:1 di default)
            $table->decimal('bonus_value', 8, 2)->default(0); // se fixed: KY extra fissi; se percentage: % bonus
            $table->boolean('is_active')->default(true);
            $table->string('stripe_price_id')->nullable();   // ID Stripe price per checkout
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ky_cards');
    }
};
