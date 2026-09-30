<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Bookings wording:
 * - "Tray" is called "Station" (labels only -- the trays table, tray_id,
 *   routes and the `trays` permission keep their names). QZ_Tray_Printer is
 *   the QZ Tray print app, not this, and is left alone.
 * - Booking form: Group Service -> Service dropdowns.
 */
return new class extends Migration
{
    private array $station = [
        'Tray' => ['Tray', 'Station', 'Estación'],
        'Trays' => ['Trays', 'Stations', 'Estaciones'],
        'Choose_Tray' => ['Choose Tray', 'Choose Station', 'Elegir estación'],
        'Enter_Name_Tray' => ['Enter Tray Name', 'Enter Station Name', 'Ingrese el nombre de la estación'],
        'TrayName' => ['Tray Name', 'Station Name', 'Nombre de la estación'],
    ];

    private array $new = [
        'Group_Service' => ['Group Service', 'Grupo de servicio'],
        'Choose_Group_Service' => ['Choose Group Service', 'Elegir grupo de servicio'],
        'Choose_Service' => ['Choose Service', 'Elegir servicio'],
    ];

    public function up(): void
    {
        foreach ($this->station as $key => [, $en, $es]) {
            DB::table('translations')->where('key', $key)->update(['en' => $en, 'es' => $es, 'updated_at' => now()]);
        }

        foreach ($this->new as $key => [$en, $es]) {
            if (DB::table('translations')->where('key', $key)->exists()) {
                continue;
            }
            DB::table('translations')->insert([
                'key' => $key, 'is_default' => 1, 'en' => $en, 'es' => $es,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        forget_db_translations();
    }

    public function down(): void
    {
        foreach ($this->station as $key => [$en]) {
            DB::table('translations')->where('key', $key)->update(['en' => $en, 'es' => null, 'updated_at' => now()]);
        }
        DB::table('translations')->whereIn('key', array_keys($this->new))->delete();

        forget_db_translations();
    }
};
