<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

/**
 * One-time (re-runnable) import of the countries/uscities reference data
 * behind the Quick Add Customer Country/City dropdowns. Source files:
 * KT-TOOLS/files/countries.xlsx and KT-TOOLS/files/uscities.xlsx.
 *
 * Re-running truncates and reloads both tables -- safe since neither has
 * anything else pointing at it by foreign key (clients.country/city store
 * the plain text values, not ids).
 */
class ImportGeoData extends Command
{
    protected $signature = 'geo:import {--path=KT-TOOLS/files : Directory containing countries.xlsx and uscities.xlsx}';

    protected $description = 'Import countries.xlsx and uscities.xlsx into the countries/uscities tables';

    public function handle()
    {
        $dir = base_path($this->option('path'));
        $countriesFile = $dir.'/countries.xlsx';
        $citiesFile = $dir.'/uscities.xlsx';

        if (! file_exists($countriesFile) || ! file_exists($citiesFile)) {
            $this->error("Expected both files under {$dir}: countries.xlsx, uscities.xlsx");

            return 1;
        }

        $this->importCountries($countriesFile);
        $this->importUsCities($citiesFile);

        return 0;
    }

    private function toBool($value): bool
    {
        return in_array(strtoupper((string) $value), ['TRUE', '1', 'YES'], true);
    }

    /** The source sheets use a literal "?" for missing numeric values. */
    private function num($value)
    {
        if ($value === null || $value === '' || $value === '?') {
            return null;
        }

        return $value;
    }

    private function importCountries(string $path): void
    {
        $sheet = Excel::toArray([], $path)[0];
        $header = array_shift($sheet);
        $columns = array_flip($header);

        $rows = [];
        $seenIso2 = [];
        $now = now();
        foreach ($sheet as $row) {
            if (empty($row[$columns['iso2']])) {
                continue;
            }
            $iso2 = strtoupper($row[$columns['iso2']]);
            // The source sheet has a handful of duplicate iso2 codes
            // (disputed/dependent territories sharing a code) -- keep the
            // first occurrence only, since iso2 is our unique lookup key.
            if (isset($seenIso2[$iso2])) {
                continue;
            }
            $seenIso2[$iso2] = true;
            $rows[] = [
                'name' => $row[$columns['country']],
                'iso2' => $iso2,
                'demonym' => $row[$columns['demonym']] ?? null,
                'tld' => $row[$columns['tld']] ?? null,
                'currency' => $row[$columns['currency']] ?? null,
                'population' => $this->num($row[$columns['population']] ?? null),
                'density' => $this->num($row[$columns['density']] ?? null),
                'area' => $this->num($row[$columns['area']] ?? null),
                'gdp' => $this->num($row[$columns['gdp']] ?? null),
                'median_age' => $this->num($row[$columns['median_age']] ?? null),
                'language' => $row[$columns['language']] ?? null,
                'website' => $row[$columns['website']] ?? null,
                'calling_code' => $row[$columns['calling_code']] ?? null,
                'driving_side' => $row[$columns['driving_side']] ?? null,
                'continent' => $row[$columns['continent']] ?? null,
                'un_member' => $this->toBool($row[$columns['un_member']] ?? false),
                'religion' => $row[$columns['religion']] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('countries')->truncate();
        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('countries')->insert($chunk);
        }

        $this->info('Imported '.count($rows).' countries.');
    }

    private function importUsCities(string $path): void
    {
        $sheet = Excel::toArray([], $path)[0];
        $header = array_shift($sheet);
        $columns = array_flip($header);

        $now = now();
        $buffer = [];
        $total = 0;

        DB::table('uscities')->truncate();
        foreach ($sheet as $row) {
            if (empty($row[$columns['city']]) || empty($row[$columns['state_id']])) {
                continue;
            }
            $buffer[] = [
                'source_id' => $this->num($row[$columns['id']] ?? null),
                'city' => $row[$columns['city']],
                'city_ascii' => $row[$columns['city_ascii']] ?? $row[$columns['city']],
                'state_id' => strtoupper($row[$columns['state_id']]),
                'state_name' => $row[$columns['state_name']],
                'county_fips' => $this->num($row[$columns['county_fips']] ?? null),
                'county_name' => $row[$columns['county_name']] ?? null,
                'lat' => $this->num($row[$columns['lat']] ?? null),
                'lng' => $this->num($row[$columns['lng']] ?? null),
                'population' => $this->num($row[$columns['population']] ?? null),
                'density' => $this->num($row[$columns['density']] ?? null),
                'military' => $this->toBool($row[$columns['military']] ?? false),
                'incorporated' => $this->toBool($row[$columns['incorporated']] ?? false),
                'timezone' => $row[$columns['timezone']] ?? null,
                'ranking' => $this->num($row[$columns['ranking']] ?? null),
                'zips' => $row[$columns['zips']] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $total++;

            if (count($buffer) >= 1000) {
                DB::table('uscities')->insert($buffer);
                $buffer = [];
            }
        }
        if ($buffer) {
            DB::table('uscities')->insert($buffer);
        }

        $this->info("Imported {$total} US cities.");
    }
}
