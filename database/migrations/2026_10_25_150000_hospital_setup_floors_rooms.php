<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Hospital phase 1 (user's analysis, 2026-10-25): the one-time setup.
 *  - hms_shift_types: the company's shifts with their hours (were fixed in code:
 *    morning 07-15, afternoon 15-23, night 23-07, er24 07-07).
 *  - hms_floors: the building's floors, each with a map grid (cols x rows).
 *  - hms_rooms: rooms, offices, ER, nursing station... placed on the floor map
 *    (grid units); beds go in a room.
 *  - hospital_beds: room_id, map position, status_changed_at (a bed freed by a
 *    discharge goes to "cleaning" -- the time it changed feeds the alerts);
 *    hospital_wards.floor_id.
 *  - permission hms_setup (Hospital -> Configuration) for the roles that manage wards.
 * Existing wards' text floors become hms_floors (by name). Safe to run twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('hms_shift_types')) {
            Schema::create('hms_shift_types', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id')->index();
                $t->string('code', 32);
                $t->string('name', 80);
                $t->string('starts_at', 5);            // HH:MM
                $t->unsignedTinyInteger('hours');      // 1..24
                $t->string('color', 16)->nullable();
                $t->unsignedSmallInteger('sort_order')->default(0);
                $t->boolean('is_active')->default(true);
                $t->timestamps();
                $t->unique(['company_id', 'code']);
            });
        }
        if (! Schema::hasTable('hms_floors')) {
            Schema::create('hms_floors', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id')->index();
                $t->string('name', 80);
                $t->smallInteger('level')->default(1);
                $t->unsignedSmallInteger('map_cols')->default(24);
                $t->unsignedSmallInteger('map_rows')->default(14);
                $t->unsignedSmallInteger('sort_order')->default(0);
                $t->string('notes', 255)->nullable();
                $t->timestamps();
                $t->softDeletes();
            });
        }
        if (! Schema::hasTable('hms_rooms')) {
            Schema::create('hms_rooms', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id')->index();
                $t->unsignedBigInteger('floor_id')->index();
                $t->unsignedBigInteger('ward_id')->nullable()->index();
                $t->string('name', 80);
                $t->string('kind', 24)->default('room'); // room, office, er, nursing, or, lab, imaging, pharmacy, reception, bath, stairs, other
                $t->unsignedSmallInteger('map_x')->nullable();
                $t->unsignedSmallInteger('map_y')->nullable();
                $t->unsignedSmallInteger('map_w')->nullable();
                $t->unsignedSmallInteger('map_h')->nullable();
                $t->string('color', 16)->nullable();
                $t->string('notes', 255)->nullable();
                $t->timestamps();
                $t->softDeletes();
            });
        }
        Schema::table('hospital_beds', function (Blueprint $t) {
            if (! Schema::hasColumn('hospital_beds', 'room_id')) {
                $t->unsignedBigInteger('room_id')->nullable()->after('ward_id')->index();
            }
            if (! Schema::hasColumn('hospital_beds', 'map_x')) {
                $t->unsignedSmallInteger('map_x')->nullable()->after('status');
                $t->unsignedSmallInteger('map_y')->nullable()->after('map_x');
            }
            if (! Schema::hasColumn('hospital_beds', 'status_changed_at')) {
                $t->timestamp('status_changed_at')->nullable()->after('status');
            }
        });
        // The bed status was an ENUM without "cleaning" (MySQL stored '' for it): a plain string now.
        if (DB::getDriverName() === 'mysql' && str_starts_with((string) optional(DB::selectOne("SHOW COLUMNS FROM hospital_beds LIKE 'status'"))->Type, 'enum')) {
            DB::statement("ALTER TABLE hospital_beds MODIFY status VARCHAR(20) NOT NULL DEFAULT 'available'");
        }
        if (! Schema::hasColumn('hospital_wards', 'floor_id')) {
            Schema::table('hospital_wards', fn (Blueprint $t) => $t->unsignedBigInteger('floor_id')->nullable()->after('floor')->index());
        }

        // The wards' text floors -> floors (one per company + name), linked.
        $floorIds = DB::table('hms_floors')->pluck('id')->all();
        foreach (DB::table('hospital_wards')->whereNull('deleted_at')->where(fn ($q) => $q->whereNull('floor_id')->orWhereNotIn('floor_id', $floorIds ?: [0]))->get(['id', 'company_id', 'floor']) as $w) {
            $name = trim((string) $w->floor);
            if ($name === '' || ! $w->company_id) {
                continue;
            }
            $label = ctype_digit($name) ? 'Piso '.$name : $name;
            $fid = DB::table('hms_floors')->where('company_id', $w->company_id)->where('name', $label)->whereNull('deleted_at')->value('id')
                ?? DB::table('hms_floors')->insertGetId(['company_id' => $w->company_id, 'name' => $label,
                    'level' => ctype_digit($name) ? (int) $name : 1, 'sort_order' => ctype_digit($name) ? (int) $name : 0, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('hospital_wards')->where('id', $w->id)->update(['floor_id' => $fid]);
        }

        // Level / order from the name: "Planta baja" / "Ground" = 0, "1er piso" / "1st" = 1, "2do piso" = 2...
        foreach (DB::table('hms_floors')->whereNull('deleted_at')->get(['id', 'name']) as $f) {
            $lvl = preg_match('/baja|ground|lobby/i', $f->name) ? 0 : (preg_match('/(-?\d+)/', $f->name, $m) ? (int) $m[1] : null);
            if ($lvl !== null) {
                DB::table('hms_floors')->where('id', $f->id)->update(['level' => $lvl, 'sort_order' => $lvl + 10]);
            }
        }

        // Permission: Hospital -> Configuration, for every role that manages wards.
        if (Schema::hasTable('permissions')) {
            $pid = DB::table('permissions')->where('name', 'hms_setup')->value('id')
                ?? DB::table('permissions')->insertGetId(['name' => 'hms_setup', 'label' => 'Hospital configuration', 'created_at' => now(), 'updated_at' => now()]);
            $wards = DB::table('permissions')->where('name', 'hms_wards')->value('id');
            foreach ($wards ? DB::table('permission_role')->where('permission_id', $wards)->pluck('role_id') : [] as $rid) {
                if (! DB::table('permission_role')->where('role_id', $rid)->where('permission_id', $pid)->exists()) {
                    DB::table('permission_role')->insert(['role_id' => $rid, 'permission_id' => $pid]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::table('hospital_beds', function (Blueprint $t) {
            foreach (['room_id', 'map_x', 'map_y', 'status_changed_at'] as $c) {
                if (Schema::hasColumn('hospital_beds', $c)) {
                    $t->dropColumn($c);
                }
            }
        });
        if (Schema::hasColumn('hospital_wards', 'floor_id')) {
            Schema::table('hospital_wards', fn (Blueprint $t) => $t->dropColumn('floor_id'));
        }
        Schema::dropIfExists('hms_rooms');
        Schema::dropIfExists('hms_floors');
        Schema::dropIfExists('hms_shift_types');
    }
};
