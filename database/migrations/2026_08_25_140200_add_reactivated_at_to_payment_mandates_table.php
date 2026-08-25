<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Riattiva" — fase 2b.
 *
 * L'antifurto sospende il mandato da solo dopo dieci addebiti in un'ora. Fino a
 * ieri da lì non si usciva: l'utente poteva solo revocare e ridare
 * l'autorizzazione da capo, cioè rifare tutta la cerimonia con lo step-up per
 * un allarme che magari aveva fatto scattare lui stesso comprando otto regali
 * di Natale.
 *
 * Ora c'è un bottone. E serve questa colonna, non per registrare l'evento (per
 * quello c'è l'AuditLog) ma per una ragione pratica: **la finestra
 * dell'antifurto riparte da qui**. Senza, riattivare non servirebbe a niente —
 * i dieci addebiti dell'ultima ora sono ancora lì, e il primo acquisto
 * successivo farebbe scattare di nuovo la sospensione un secondo dopo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_mandates', function (Blueprint $table) {
            $table->timestamp('reactivated_at')->nullable()->after('revoked_at');
        });
    }

    public function down(): void
    {
        Schema::table('payment_mandates', function (Blueprint $table) {
            $table->dropColumn('reactivated_at');
        });
    }
};
