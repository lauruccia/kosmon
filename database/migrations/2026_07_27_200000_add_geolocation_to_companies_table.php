<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Aggiunge indirizzo e coordinate geografiche alla company, per la vista
 * mappa della directory /aziende (pin stile Monetica). Il geocoding da
 * indirizzo a lat/lng avviene lato applicativo (GeocodingService, Nominatim)
 * al salvataggio del profilo — qui aggiungiamo solo le colonne.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('address', 255)->nullable()->after('city');
            $table->decimal('latitude', 10, 7)->nullable()->after('address');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            $table->timestamp('geocoded_at')->nullable()->after('longitude');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['address', 'latitude', 'longitude', 'geocoded_at']);
        });
    }
};
