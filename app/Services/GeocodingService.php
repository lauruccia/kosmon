<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Geocodifica indirizzi tramite Nominatim (OpenStreetMap) — nessuna API key,
 * nessun costo di fatturazione. Usato solo on-demand al salvataggio del
 * profilo azienda (non in batch/loop), quindi rispettiamo naturalmente il
 * limite "1 richiesta alla volta" della usage policy Nominatim.
 *
 * @see https://operations.osmfoundation.org/policies/nominatim/
 */
class GeocodingService
{
    private const ENDPOINT = 'https://nominatim.openstreetmap.org/search';

    /**
     * Geocodifica un indirizzo libero (via + citta') in lat/lng.
     *
     * Fallimento silenzioso e non bloccante per design: se l'indirizzo non
     * viene trovato, va in timeout o l'API risponde con errore, ritorna
     * semplicemente null — il salvataggio del profilo azienda deve andare a
     * buon fine comunque, la company resta semplicemente senza pin in mappa.
     *
     * @return array{lat: float, lng: float}|null
     */
    public function geocode(string $address): ?array
    {
        $address = trim($address);
        if ($address === '') {
            return null;
        }

        try {
            $response = Http::withHeaders([
                // Richiesto dalla usage policy Nominatim: User-Agent identificativo.
                'User-Agent' => 'KMoneyApp-DirectoryGeocoder/1.0 (' . config('app.url') . ')',
            ])
                ->timeout(5)
                ->get(self::ENDPOINT, [
                    'q'              => $address,
                    'format'         => 'jsonv2',
                    'limit'          => 1,
                    'addressdetails' => 0,
                ]);

            if ($response->failed()) {
                Log::warning('GeocodingService: risposta non ok da Nominatim', [
                    'address' => $address,
                    'status'  => $response->status(),
                ]);

                return null;
            }

            $results = $response->json();
            if (! is_array($results) || count($results) === 0) {
                return null;
            }

            $first = $results[0];
            if (! isset($first['lat'], $first['lon'])) {
                return null;
            }

            return [
                'lat' => (float) $first['lat'],
                'lng' => (float) $first['lon'],
            ];
        } catch (\Throwable $e) {
            Log::warning('GeocodingService: eccezione durante il geocoding', [
                'address' => $address,
                'error'   => $e->getMessage(),
            ]);

            return null;
        }
    }
}
