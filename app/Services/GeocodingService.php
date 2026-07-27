<?php

namespace App\Services;

use App\Models\Company;
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

    /**
     * Ricalcola latitude/longitude/geocoded_at di una Company se address o
     * city sono stati modificati (isDirty) ma non ancora salvati — va
     * chiamato DOPO fill() e PRIMA di save(). Usato sia dal profilo
     * self-service dell'azienda sia dal form admin equivalente, per non
     * duplicare la stessa logica nei due controller.
     *
     * Ritorna un messaggio di avviso (indirizzo non trovato) da accodare al
     * messaggio di successo del form, oppure null se non c'e' nulla da
     * segnalare (nessuna modifica, indirizzo vuoto, o geocoding riuscito).
     */
    public function syncCompanyCoordinates(Company $company): ?string
    {
        if (! $company->isDirty('address') && ! $company->isDirty('city')) {
            return null;
        }

        $fullAddress = trim(trim((string) $company->address) . ', ' . trim((string) $company->city), ', ');

        if ($fullAddress === '') {
            $company->latitude = null;
            $company->longitude = null;
            $company->geocoded_at = null;

            return null;
        }

        $coords = $this->geocode($fullAddress);

        if ($coords === null) {
            $company->latitude = null;
            $company->longitude = null;
            $company->geocoded_at = null;

            return 'L\'indirizzo non è stato trovato sulla mappa: verifica che sia scritto correttamente '
                . '(es. "Via Roma 10" con città "Milano").';
        }

        $company->latitude = $coords['lat'];
        $company->longitude = $coords['lng'];
        $company->geocoded_at = now();

        return null;
    }
}
