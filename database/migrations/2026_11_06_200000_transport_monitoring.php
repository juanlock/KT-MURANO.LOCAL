<?php

use App\Support\TranslationKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Vehicle monitoring (user's request, 2026-11-06): the terminals of a trip are its control points. The crew reports
 * from the phone (arrival with a photo, departure, incidents), the phone's GPS follows the bus while the page is open,
 * and a pilot can't report while the bus is moving. The times on record are the company's; nothing here touches a
 * trip's planned times. Pilots and hostesses now open "My trip". Safe to run twice.
 */
return new class extends Migration
{
    private const PERMS = [
        'transport_monitor' => 'Transport: vehicle monitoring',
        'transport_report' => 'Transport: report from the phone (my trip)',
    ];

    private const TEXTS = [
        'Transport_Monitor' => ['Vehicle monitoring', 'Monitoreo de vehículos'],
        'Transport_MyTrip' => ['My trip', 'Mi viaje'],
        'transport_monitor' => ['Transport: vehicle monitoring', 'Transporte: monitoreo de vehículos'],
        'transport_report' => ['Transport: report from the phone', 'Transporte: reportar desde el celular'],
    ];

    public function up(): void
    {
        $this->schema();

        foreach (self::PERMS as $name => $label) {
            if (! DB::table('permissions')->where('name', $name)->exists()) {
                DB::table('permissions')->insert(['name' => $name, 'label' => $label, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        $grant = function (array $roles, array $perms) {
            $permIds = DB::table('permissions')->whereIn('name', $perms)->pluck('id');
            foreach (DB::table('roles')->whereIn('name', $roles)->whereNull('deleted_at')->pluck('id') as $rid) {
                foreach ($permIds as $pid) {
                    if (! DB::table('permission_role')->where('role_id', $rid)->where('permission_id', $pid)->exists()) {
                        DB::table('permission_role')->insert(['role_id' => $rid, 'permission_id' => $pid]);
                    }
                }
            }
        };
        $grant(['IT Admin', 'Manager', 'Supervisor'], ['transport_monitor']);
        $grant(['Piloto', 'Tripulación'], ['transport_report']);

        // The crew opens its trip, not the list of trips (only a role still on the old home page).
        DB::table('roles')->whereIn('name', ['Piloto', 'Tripulación'])->where('home_path', '/transport/trips')->update(['home_path' => '/transport/my-trip', 'updated_at' => now()]);

        $now = now();
        foreach (DB::table('menu_positions')->where('item_key', 'transport')->pluck('company_id') as $cid) {
            foreach (['/app/transport/monitor' => 2, '/app/transport/my-trip' => 3] as $key => $sort) {
                if (! DB::table('menu_positions')->where('company_id', $cid)->where('item_key', $key)->exists()) {
                    DB::table('menu_positions')->insert(['company_id' => $cid, 'item_key' => $key, 'parent_key' => 'transport', 'sort_order' => $sort,
                        'external' => 0, 'is_custom' => 0, 'hidden' => 0, 'location' => 'sidebar', 'created_at' => $now, 'updated_at' => $now]);
                }
            }
        }
        // Turismo Cautivo's own menu: both go in Flota y Logística, after "Today's departures".
        $cid = (int) DB::table('companies')->where('name', 'like', 'Turismo Cautivo%')->whereNull('deleted_at')->value('id');
        if ($cid && DB::table('menu_positions')->where('company_id', $cid)->where('item_key', 'custom_tc_flota')->exists()) {
            $rows = fn () => DB::table('menu_positions')->where('company_id', $cid);
            if ($rows()->where('item_key', '/app/transport/monitor')->where('parent_key', '!=', 'custom_tc_flota')->exists()) {
                $rows()->where('parent_key', 'custom_tc_flota')->where('sort_order', '>=', 1)->increment('sort_order', 2);
                $rows()->where('item_key', '/app/transport/monitor')->update(['parent_key' => 'custom_tc_flota', 'sort_order' => 1, 'updated_at' => $now]);
                $rows()->where('item_key', '/app/transport/my-trip')->update(['parent_key' => 'custom_tc_flota', 'sort_order' => 2, 'updated_at' => $now]);
            }
        }

        foreach (self::TEXTS as $key => [$en, $es]) {
            TranslationKeys::ensure($key, ['es' => $es]);
            foreach (['en' => $en, 'es' => $es] as $col => $text) {
                DB::table('translations')->whereRaw('`key` = ? COLLATE utf8mb4_bin', [$key])
                    ->where(fn ($q) => $q->whereNull($col)->orWhere($col, '')->orWhere($col, $key))
                    ->update([$col => $text, 'updated_at' => now()]);
            }
        }
        if (function_exists('forget_db_translations')) {
            forget_db_translations();
        }
    }

    private function schema(): void
    {
        if (! Schema::hasTable('transport_trip_checkpoints')) {
            Schema::create('transport_trip_checkpoints', function (Blueprint $t) {
                $t->increments('id');
                $t->integer('company_id')->index();
                $t->unsignedInteger('trip_id');
                $t->unsignedInteger('location_id');
                $t->dateTime('arrived_at')->nullable();
                $t->unsignedInteger('arrived_by')->nullable();
                $t->string('arrive_source', 8)->nullable(); // manual | gps
                $t->decimal('arrive_lat', 10, 6)->nullable();
                $t->decimal('arrive_lng', 10, 6)->nullable();
                $t->unsignedInteger('arrive_off_m')->nullable(); // how far from the terminal's coordinates
                $t->integer('arrive_delay_min')->nullable(); // against the plan; negative = early
                $t->string('photo')->nullable(); // private: files/company_{id}/transport/...
                $t->dateTime('photo_at')->nullable();
                $t->string('note', 500)->nullable();
                $t->dateTime('departed_at')->nullable();
                $t->unsignedInteger('departed_by')->nullable();
                $t->string('depart_source', 8)->nullable();
                $t->integer('depart_delay_min')->nullable();
                $t->dateTime('skipped_at')->nullable(); // the bus did not call there
                $t->unsignedInteger('skipped_by')->nullable();
                $t->timestamps();
                $t->unique(['trip_id', 'location_id']);
            });
        }
        if (! Schema::hasTable('transport_trip_reports')) {
            Schema::create('transport_trip_reports', function (Blueprint $t) {
                $t->increments('id');
                $t->integer('company_id')->index();
                $t->unsignedInteger('trip_id')->index();
                $t->unsignedInteger('user_id');
                $t->string('kind', 20);
                $t->string('note', 1000)->nullable();
                $t->decimal('lat', 10, 6)->nullable();
                $t->decimal('lng', 10, 6)->nullable();
                $t->decimal('speed_kmh', 5, 1)->nullable();
                $t->string('photo')->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('transport_vehicle_positions')) {
            Schema::create('transport_vehicle_positions', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->integer('company_id');
                $t->unsignedInteger('trip_id');
                $t->unsignedInteger('vehicle_id')->nullable();
                $t->unsignedInteger('user_id')->nullable();
                $t->decimal('lat', 10, 6);
                $t->decimal('lng', 10, 6);
                $t->decimal('speed_kmh', 5, 1)->nullable();
                $t->unsignedInteger('accuracy_m')->nullable();
                $t->string('source', 8)->default('phone'); // phone | device, when a bus's own GPS is connected
                $t->dateTime('recorded_at');
                $t->index(['trip_id', 'recorded_at']);
                $t->index('recorded_at'); // transport:prune-positions
            });
        }
    }

    public function down(): void
    {
    }
};
