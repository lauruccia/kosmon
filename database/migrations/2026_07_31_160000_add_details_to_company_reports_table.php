<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Estensione feature "segnalazione azienda" (richiesta di Laura, 31/07/2026):
 * oltre a nome/città/note, chi segnala ora indica anche il settore/categoria
 * dell'azienda e il proprio grado di conoscenza della stessa (entrambi
 * obbligatori lato form, vedi CompanyReportController::store()), più un
 * referente aziendale facoltativo (nome/telefono/email) — tutti questi dati
 * sono visibili all'admin nel pannello "Segnalazioni aziende" (vedi
 * admin/mlm/company-reports.blade.php).
 *
 * Colonne nullable a livello DB (anche quelle "obbligatorie" nel form) per
 * restare compatibili con le chiamate dirette a CompanyReportService::submit()
 * usate dai test esistenti, che non passano questi campi — l'obbligatorietà
 * è applicata solo nella validazione HTTP del controller.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_reports', function (Blueprint $table) {
            $table->string('company_sector')->nullable()->after('company_city');
            $table->string('knowledge_level')->nullable()->after('company_sector');
            $table->string('contact_name')->nullable()->after('company_notes');
            $table->string('contact_phone', 40)->nullable()->after('contact_name');
            $table->string('contact_email')->nullable()->after('contact_phone');
        });
    }

    public function down(): void
    {
        Schema::table('company_reports', function (Blueprint $table) {
            $table->dropColumn(['company_sector', 'knowledge_level', 'contact_name', 'contact_phone', 'contact_email']);
        });
    }
};
