<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Service jobs of two kinds, split in the Service menu for every company:
 *  - service: work at the customer (landscaping, IT visits...) -- no device;
 *  - device: a device / product repair (brand, model, IMEI, accessories...).
 * Existing jobs: device when they carry any device detail, else service.
 * Plus the new Service menu labels (en/es).
 */
return new class extends Migration
{
    private const KEYS = [
        'Todays_Jobs' => ["Today's Jobs", 'Trabajos de hoy'],
        'Service_Jobs_Group' => ['Service Jobs', 'Trabajos de servicio'],
        'All_Service_Jobs' => ['All Service Jobs', 'Todos los trabajos de servicio'],
        'Device_Repairs' => ['Device Repairs', 'Reparaciones de equipos'],
        'All_Device_Repairs' => ['All Device Repairs', 'Todas las reparaciones'],
        'All_Jobs' => ['All Jobs', 'Todos los trabajos'],
        'Service_Setup' => ['Setup', 'Configuración'],
    ];

    public function up(): void
    {
        if (! Schema::hasColumn('service_jobs', 'job_kind')) {
            Schema::table('service_jobs', function (Blueprint $t) {
                $t->string('job_kind', 10)->default('service')->index()->after('job_type');
            });
        }
        DB::table('service_jobs')->where(function ($q) {
            foreach (['device_brand', 'device_model', 'device_serial', 'device_imei', 'device_color', 'device_password', 'condition_on_arrival'] as $c) {
                $q->orWhere(fn ($w) => $w->whereNotNull($c)->where($c, '!=', ''));
            }
            $q->orWhere(fn ($w) => $w->whereNotNull('accessories')->whereNotIn('accessories', ['', '[]', 'null']));
        })->update(['job_kind' => 'device']);

        foreach (self::KEYS as $key => [$en, $es]) {
            if (! DB::table('translations')->where('key', $key)->exists()) {
                DB::table('translations')->insert(['key' => $key, 'is_default' => 1, 'en' => $en, 'es' => $es, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        forget_db_translations();
    }

    public function down(): void
    {
        if (Schema::hasColumn('service_jobs', 'job_kind')) {
            Schema::table('service_jobs', function (Blueprint $t) {
                $t->dropIndex(['job_kind']);
                $t->dropColumn('job_kind');
            });
        }
        DB::table('translations')->whereIn('key', array_keys(self::KEYS))->delete();
        forget_db_translations();
    }
};
