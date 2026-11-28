<?php

use App\Support\TranslationKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * RRHH: the daily attendance sheet and the absence calendar (the user, 2026-11-28, taken from HTD-IT: "es un método práctico
 * de registrar la asistencia" / "un calendario que permite ver las ausencias programadas de cada personal").
 *  - attendances gains the absence's leave type, half day, reason and its source (sheet = the daily sheet, leave = an
 *    approved leave written by the sheet; null = the clock in / out of before);
 *  - attendance_days: one row per company and day whose sheet was saved (who, when) -- what makes a day "taken";
 *  - settings.attendance_sheet (JSON): group_by location | department, workdays (0 = Sunday ... 6), exclude_staff_types;
 *    Turismo Cautivo: by agency, every day, pilots left out (their attendance is their trips);
 *  - permissions attendance_sheet / absence_calendar for every company's IT Admin and Manager, and Turismo's Director;
 *  - Turismo's menu: both screens in RRHH, after Asistencia.
 * Safe to run twice; by names.
 */
return new class extends Migration
{
    private const PERMS = [
        'attendance_sheet' => ['Daily attendance sheet: mark everyone present / absent', 'Asistencia del día: marcar a todos presente / ausente'],
        'absence_calendar' => ['Absence calendar: planned leaves, absences and holidays', 'Calendario de ausencias: permisos programados, ausencias y feriados'],
    ];

    private const TEXTS = [
        'Attendance_Sheet' => ['Daily attendance', 'Asistencia del día'],
        'Absence_Calendar' => ['Absence calendar', 'Calendario de ausencias'],
        'Perm_Attendance_Sheet' => ['Daily attendance sheet: mark everyone present / absent', 'Asistencia del día: marcar a todos presente / ausente'],
        'Perm_Absence_Calendar' => ['Absence calendar: planned leaves, absences and holidays', 'Calendario de ausencias: permisos programados, ausencias y feriados'],
    ];

    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $t) {
            if (! Schema::hasColumn('attendances', 'leave_type_id')) {
                $t->unsignedInteger('leave_type_id')->nullable()->after('status');
            }
            if (! Schema::hasColumn('attendances', 'half_day')) {
                $t->boolean('half_day')->default(false)->after('leave_type_id');
            }
            if (! Schema::hasColumn('attendances', 'reason')) {
                $t->string('reason', 255)->nullable()->after('half_day');
            }
            if (! Schema::hasColumn('attendances', 'source')) {
                $t->string('source', 10)->nullable()->after('reason');
            }
        });
        if (! collect(DB::select('SHOW INDEX FROM attendances'))->contains('Key_name', 'attendances_company_date')) {
            Schema::table('attendances', fn (Blueprint $t) => $t->index(['company_id', 'date'], 'attendances_company_date'));
        }

        if (! Schema::hasTable('attendance_days')) {
            Schema::create('attendance_days', function (Blueprint $t) {
                $t->id();
                $t->unsignedInteger('company_id');
                $t->date('date');
                $t->unsignedInteger('saved_by')->nullable();
                $t->timestamp('saved_at')->nullable();
                $t->timestamps();
                $t->unique(['company_id', 'date']);
            });
        }

        if (! Schema::hasColumn('settings', 'attendance_sheet')) {
            Schema::table('settings', fn (Blueprint $t) => $t->text('attendance_sheet')->nullable());
        }

        foreach (self::PERMS as $name => [$en]) {
            if (! DB::table('permissions')->where('name', $name)->exists()) {
                DB::table('permissions')->insert(['name' => $name, 'label' => $en, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        $pids = DB::table('permissions')->whereIn('name', array_keys(self::PERMS))->pluck('id');
        $cid = (int) DB::table('companies')->where('name', 'like', 'Turismo Cautivo%')->whereNull('deleted_at')->value('id');
        $roles = DB::table('roles')->whereNull('deleted_at')
            ->where(fn ($q) => $q->whereIn('name', ['IT Admin', 'Manager'])->orWhere(fn ($q) => $q->where('company_id', $cid)->where('name', 'Director')))
            ->pluck('id');
        foreach ($roles as $rid) {
            foreach ($pids as $pid) {
                if (! DB::table('permission_role')->where('role_id', $rid)->where('permission_id', $pid)->exists()) {
                    DB::table('permission_role')->insert(['role_id' => $rid, 'permission_id' => $pid]);
                }
            }
        }

        foreach (self::PERMS + self::TEXTS as $key => [$en, $es]) {
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

        if (! $cid) {
            return;
        }
        DB::table('settings')->where('company_id', $cid)->whereNull('deleted_at')->whereNull('attendance_sheet')
            ->update(['attendance_sheet' => json_encode(['group_by' => 'location', 'workdays' => [0, 1, 2, 3, 4, 5, 6], 'exclude_staff_types' => ['pilot']])]);

        $parent = DB::table('menu_positions')->where('company_id', $cid)->where('item_key', '/app/hrm/attendance')->value('parent_key') ?: 'hrm';
        $after = (int) DB::table('menu_positions')->where('company_id', $cid)->where('item_key', '/app/hrm/attendance')->value('sort_order');
        foreach (['/app/hrm/attendance_sheet', '/app/hrm/absence_calendar'] as $i => $item) {
            if (DB::table('menu_positions')->where('company_id', $cid)->where('item_key', $item)->exists()) {
                continue;
            }
            DB::table('menu_positions')->where('company_id', $cid)->where('parent_key', $parent)->where('sort_order', '>', $after + $i)->increment('sort_order');
            DB::table('menu_positions')->insert(['company_id' => $cid, 'item_key' => $item, 'parent_key' => $parent, 'sort_order' => $after + $i + 1,
                'external' => 0, 'is_custom' => 0, 'hidden' => 0, 'location' => 'sidebar', 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        DB::table('menu_positions')->whereIn('item_key', ['/app/hrm/attendance_sheet', '/app/hrm/absence_calendar'])->delete();
        $pids = DB::table('permissions')->whereIn('name', array_keys(self::PERMS))->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $pids)->delete();
        DB::table('permissions')->whereIn('id', $pids)->delete();
        Schema::dropIfExists('attendance_days');
        if (Schema::hasColumn('settings', 'attendance_sheet')) {
            Schema::table('settings', fn (Blueprint $t) => $t->dropColumn('attendance_sheet'));
        }
        // attendances keeps its new columns (rows written by the sheet still need them).
    }
};
