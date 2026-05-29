<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('ky_card_purchases', function (Blueprint $table) {
            // Metodo di pagamento scelto dal cliente
            $table->enum('payment_method', ['stripe', 'paypal', 'bank_transfer'])
                  ->default('stripe')
                  ->after('status');

            // PayPal order ID per capture
            $table->string('paypal_order_id')->nullable()->after('stripe_payment_intent_id');

            // Note admin per bonifici (es. "Pagamento verificato il 28/05/2026")
            $table->text('admin_notes')->nullable()->after('paypal_order_id');

            // Chi ha confermato il bonifico (admin user_id)
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete()->after('admin_notes');
        });

        // Aggiunge pending_bank_transfer al ENUM status (solo MySQL)
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE ky_card_purchases MODIFY COLUMN status ENUM('pending','pending_bank_transfer','completed','failed','refunded') NOT NULL DEFAULT 'pending'");
        }
    }

    public function down(): void
    {
        Schema::table('ky_card_purchases', function (Blueprint $table) {
            $table->dropColumn(['payment_method', 'paypal_order_id', 'admin_notes']);
            $table->dropConstrainedForeignId('confirmed_by');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE ky_card_purchases MODIFY COLUMN status ENUM('pending','completed','failed','refunded') NOT NULL DEFAULT 'pending'");
        }
    }
};
