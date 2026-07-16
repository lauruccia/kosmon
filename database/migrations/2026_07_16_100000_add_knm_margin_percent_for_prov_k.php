<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Prov K" — compenso KNM (2026-07-16, slide "Esempio compensi").
     *
     * Le tabelle delle slide applicano le percentuali del reddito residuale
     * NON all'importo mensile pieno del cliente, ma a "Prov K" = importo
     * mensile x margine KNM (30% negli esempi principali, 10% in uno: e' un
     * parametro). Anche la commissione diretta e' "fino al 40% del compenso
     * KNM sulle vendite dirette".
     *
     *  - system_settings.mlm_knm_margin_percent: margine KNM globale
     *    configurabile da admin (/admin/mlm-impostazioni). NULL = default 30.
     *  - mlm_commission_base_ledger.knm_margin_percent: snapshot del margine
     *    al momento del deposito — un futuro cambio del margine non riscrive
     *    retroattivamente la base dei depositi gia' fatti. NULL (righe
     *    storiche pre-2026-07-16) = fallback al valore corrente del setting.
     */
    public function up(): void
    {
        Schema::table('system_settings', function (Blueprint $table): void {
            $table->unsignedTinyInteger('mlm_knm_margin_percent')->nullable()->after('mlm_points_validity_override_minutes');
        });

        Schema::table('mlm_commission_base_ledger', function (Blueprint $table): void {
            $table->unsignedTinyInteger('knm_margin_percent')->nullable()->after('monthly_amount_eur_cents');
        });
    }

    public function down(): void
    {
        Schema::table('system_settings', function (Blueprint $table): void {
            $table->dropColumn('mlm_knm_margin_percent');
        });

        Schema::table('mlm_commission_base_ledger', function (Blueprint $table): void {
            $table->dropColumn('knm_margin_percent');
        });
    }
};
