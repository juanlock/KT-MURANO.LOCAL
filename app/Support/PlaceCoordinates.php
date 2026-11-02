<?php

namespace App\Support;

/**
 * Approximate coordinates of the towns / stations the Transport module uses (2026-11-01),
 * so the dashboard map can draw terminals, their agencies and the routes without the
 * user typing latitudes. Looked up by the location's city, else its name (accents,
 * case and "terminal / agencia" words ignored). Unknown = null: the user can type
 * them in Transport -> Routes and locations.
 */
class PlaceCoordinates
{
    private const PLACES = [
        // Peru: cities
        'lima' => [-12.0464, -77.0428], 'chiclayo' => [-6.7714, -79.8409], 'chepen' => [-7.2272, -79.4297],
        'trujillo' => [-8.1116, -79.0288], 'ica' => [-14.0678, -75.7286], 'nasca' => [-14.8309, -74.9381], 'nazca' => [-14.8309, -74.9381],
        'arequipa' => [-16.4090, -71.5375], 'cusco' => [-13.5320, -71.9675], 'puno' => [-15.8402, -70.0219],
        'piura' => [-5.1945, -80.6328], 'tumbes' => [-3.5669, -80.4515], 'chimbote' => [-9.0745, -78.5936], 'huaraz' => [-9.5278, -77.5278],
        'cajamarca' => [-7.1638, -78.5003], 'tacna' => [-18.0146, -70.2536], 'ayacucho' => [-13.1588, -74.2239], 'huancayo' => [-12.0651, -75.2049],
        'pisco' => [-13.7100, -76.2033], 'paracas' => [-13.8333, -76.2500], 'mancora' => [-4.1050, -81.0461],
        // Peru: north coast towns (Turismo Cautivo's agencies)
        'monsefu' => [-6.8775, -79.8714], 'pacasmayo' => [-7.4006, -79.5714], 'motupe' => [-6.1519, -79.7142], 'eten' => [-6.9075, -79.8636],
        'reque' => [-6.8650, -79.8183], 'pacanguilla' => [-7.1667, -79.4333], 'san pedro' => [-7.4294, -79.5050], 'san pedro de lloc' => [-7.4294, -79.5050],
        'batan grande' => [-6.4500, -79.7667], 'tucume' => [-6.5106, -79.8572], 'lambayeque' => [-6.7011, -79.9061], 'lambayeqye' => [-6.7011, -79.9061],'viru' => [-8.4144, -78.7522],
        'jose l ortiz' => [-6.7569, -79.8364], 'jose leonardo ortiz' => [-6.7569, -79.8364], 'bolognesi' => [-6.7750, -79.8450],
        'nuevo chiclayo' => [-6.7600, -79.8300], 'movil chiclayo' => [-6.7790, -79.8380],
        // Lima: districts and stations
        'plaza lima norte' => [-11.9946, -77.0614], 'plaza norte' => [-11.9946, -77.0614], 'terrapuerto pn' => [-11.9960, -77.0590], 'movil pnorte' => [-11.9935, -77.0625],
        'puente piedra' => [-11.8667, -77.0750], 'los olivos' => [-11.9917, -77.0706], 'ventanilla' => [-11.8750, -77.1278],
        'atocongo' => [-12.1500, -76.9800], '28 de julio' => [-12.0650, -77.0300], 'cavassa' => [-12.0700, -77.0200], 'ormeno' => [-12.0900, -77.0150],
        'fiori' => [-12.0080, -77.0610], 'infanta' => [-11.9640, -77.0700], 'alfredo mendiola' => [-11.9850, -77.0640], 'santa rosa' => [-11.8000, -77.1667],
        'encalada' => [-12.1050, -76.9700], 'ensenada' => [-11.8800, -77.0600], 'miraflores' => [-12.1211, -77.0297], 'la victoria' => [-12.0700, -77.0170],
        // USA (the English demo)
        'new york' => [40.7128, -74.0060], 'philadelphia' => [39.9526, -75.1652], 'washington' => [38.9072, -77.0369],
        'boston' => [42.3601, -71.0589], 'baltimore' => [39.2904, -76.6122], 'miami' => [25.7617, -80.1918],
    ];

    /** [lat, lng] of a city / station name, or null. */
    public static function find(?string ...$names): ?array
    {
        foreach ($names as $name) {
            $k = self::key((string) $name);
            if ($k === '') {
                continue;
            }
            if (isset(self::PLACES[$k])) {
                return self::PLACES[$k];
            }
            // "MONSEFU 2", "PUENTE PIEDRA 2", "LAMBAYEQYE 2"...
            $k2 = trim(preg_replace('/\s+\d+$/', '', $k));
            if (isset(self::PLACES[$k2])) {
                return self::PLACES[$k2];
            }
        }

        return null;
    }

    /** Straight-line km between two points. */
    public static function km(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = fn ($d) => $d * M_PI / 180;
        $a = sin($r($lat2 - $lat1) / 2) ** 2 + cos($r($lat1)) * cos($r($lat2)) * sin($r($lng2 - $lng1) / 2) ** 2;

        return 6371 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    private static function key(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', 'ü' => 'u', '.' => ' ']);
        $s = preg_replace('/\b(terminal|terminal terrestre|agencia|agency|ter|oficina)\b/', ' ', $s);

        return trim(preg_replace('/\s+/', ' ', $s));
    }
}
