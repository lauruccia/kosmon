<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dati anagrafici necessari a compilare il "modulo di adesione" /
 * contratto di nomina ad Incaricato di Vendita (2026-07-31, richiesta di
 * Laura: "quando un agente registra un agente sotto di lui, vorrei che
 * inserisse per l'agente che deve registrare tutti i dati utili al
 * contratto"). Il campo `fiscal_code` esiste già dalla migration originale
 * degli users (usato finora solo in fase di registrazione pubblica): qui
 * aggiungiamo solo i campi mancanti (nascita e residenza), richiesti dal
 * documento "Condizioni Generali per l'Incaricato di Vendita" (art. 6:
 * maggiore età e residenza in Italia).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->date('birth_date')->nullable()->after('fiscal_code');
            $table->string('birth_place', 100)->nullable()->after('birth_date');
            $table->string('residence_address', 190)->nullable()->after('birth_place');
            $table->string('residence_zip', 10)->nullable()->after('residence_address');
            $table->string('residence_city', 100)->nullable()->after('residence_zip');
            $table->string('residence_province', 2)->nullable()->after('residence_city');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn([
                'birth_date',
                'birth_place',
                'residence_address',
                'residence_zip',
                'residence_city',
                'residence_province',
            ]);
        });
    }
};
