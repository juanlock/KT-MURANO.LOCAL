<?php

use App\Support\TranslationKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Programación de buses (user's request, 2026-11-25, Turismo Cautivo's head of operations): normal days follow a fixed
 * timetable; July (Fiestas Patrias) and December are planned in Excel, day by day, per Lima terminal (Cavassa, Plaza Norte,
 * Olivos), with the service tag (EJEC, PREM, TRUX...), the bus, the prices of the day per seat class and how many buses
 * each city has; then typed into the ticket system. This replaces those workbooks:
 *  - transport_schedule_plans: a plan = the base timetable (kind base: weekday / sunday) or a season (kind season: dates);
 *    buses available, notes, the buses per city and day ({date: {lima, chiclayo}});
 *  - transport_schedule_slots: one departure of a plan: day (or day type), side (lima / chiclayo), terminal, time, route,
 *    tag, bus, note;
 *  - transport_schedule_prices: a day's price per route key (CIX / TRUX) and seat class (SEMICAMA / SOFACAMA);
 *  - permission transport_schedule for IT Admin, Manager, Director and Operaciones of the transport companies;
 *  - menu: /app/transport/schedule in Turismo's "Flota y Logística" group, right after Salidas del día.
 * Safe to run twice; by name.
 */
return new class extends Migration
{
    private const PERM = 'transport_schedule';

    private const ITEM = '/app/transport/schedule';

    private const TEXTS = [
        'Transport_Schedule' => ['Bus programming', 'Programación de buses'],
        'transport_schedule' => ['Transport: bus programming (timetable and seasons)', 'Transporte: programación de buses (horario y temporadas)'],
        'Perm_Transport_Schedule' => ['Bus programming: base timetable and season plans', 'Programación de buses: horario base y temporadas'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('transport_schedule_plans')) {
            Schema::create('transport_schedule_plans', function (Blueprint $t) {
                $t->id();
                $t->unsignedInteger('company_id')->index();
                $t->string('name', 120);
                $t->string('kind', 10)->default('season');   // base | season
                $t->date('date_from')->nullable();
                $t->date('date_to')->nullable();
                $t->unsignedSmallInteger('buses')->nullable(); // buses the plan counts on ("en base de 15 buses")
                $t->json('fleet')->nullable();                 // {Y-m-d: {lima: "8+3", chiclayo: "9"}}
                $t->text('notes')->nullable();
                $t->string('source_file')->nullable();         // the workbook it was imported from
                $t->unsignedInteger('created_by')->nullable();
                $t->timestamps();
                $t->softDeletes();
            });
        }
        if (! Schema::hasTable('transport_schedule_slots')) {
            Schema::create('transport_schedule_slots', function (Blueprint $t) {
                $t->id();
                $t->unsignedInteger('company_id');
                $t->unsignedBigInteger('plan_id')->index();
                $t->date('day')->nullable();                   // season plans
                $t->string('day_type', 10)->nullable();        // base plans: weekday | sunday
                $t->string('side', 10)->default('lima');       // lima | chiclayo (which end it leaves from)
                $t->string('terminal', 40);                    // CAVASSA, PZ NORTE, OLIVOS, CIX, LAMBAYEQUE...
                $t->unsignedInteger('terminal_id')->nullable();// transport_locations.id when known
                $t->time('time');
                $t->unsignedInteger('route_id')->nullable();
                $t->string('tag', 30)->nullable();             // EJEC, PREM, TRUX, R, OPC, MASTER...
                $t->unsignedInteger('vehicle_id')->nullable();
                $t->string('note', 255)->nullable();
                // The same bus boards again at other terminals: [{terminal, time}] (a row of the Excel with two times).
                $t->json('boardings')->nullable();
                $t->timestamps();
                $t->index(['plan_id', 'day']);
            });
        }
        if (! Schema::hasColumn('transport_schedule_slots', 'boardings')) {
            Schema::table('transport_schedule_slots', fn (Blueprint $t) => $t->json('boardings')->nullable()->after('note'));
        }
        if (! Schema::hasTable('transport_schedule_prices')) {
            Schema::create('transport_schedule_prices', function (Blueprint $t) {
                $t->id();
                $t->unsignedInteger('company_id');
                $t->unsignedBigInteger('plan_id');
                $t->date('day')->nullable();
                $t->string('day_type', 10)->nullable();
                $t->string('route_key', 20);                   // CIX | TRUX
                $t->string('seat_class', 20);                  // SEMICAMA | SOFACAMA
                $t->decimal('price', 10, 2)->nullable();
                $t->string('price_text', 40)->nullable();      // "S/120 - S/100" when it is not one number
                $t->timestamps();
                $t->index(['plan_id', 'day']);
            });
        }

        if (! DB::table('permissions')->where('name', self::PERM)->exists()) {
            DB::table('permissions')->insert(['name' => self::PERM, 'label' => self::TEXTS[self::PERM][0], 'created_at' => now(), 'updated_at' => now()]);
        }
        $pid = DB::table('permissions')->where('name', self::PERM)->value('id');
        $transportCompanies = DB::table('transport_locations')->whereNull('deleted_at')->distinct()->pluck('company_id');
        $roles = DB::table('roles')->whereIn('company_id', $transportCompanies)->whereIn('name', ['IT Admin', 'Manager', 'Director', 'Operaciones'])
            ->whereNull('deleted_at')->pluck('id');
        foreach ($roles as $rid) {
            if (! DB::table('permission_role')->where('role_id', $rid)->where('permission_id', $pid)->exists()) {
                DB::table('permission_role')->insert(['role_id' => $rid, 'permission_id' => $pid]);
            }
        }

        // Turismo Cautivo's own menu: in "Flota y Logística", right after Salidas del día.
        $now = now();
        foreach (DB::table('menu_positions')->where('item_key', 'custom_tc_flota')->pluck('company_id')->unique() as $cid) {
            $rows = fn () => DB::table('menu_positions')->where('company_id', $cid)->where('parent_key', 'custom_tc_flota');
            if ($rows()->where('item_key', self::ITEM)->exists()) {
                continue;
            }
            $daily = $rows()->where('item_key', '/app/transport/daily')->value('sort_order');
            $sort = $daily !== null ? (int) $daily + 1 : (int) $rows()->max('sort_order') + 1;
            $rows()->where('sort_order', '>=', $sort)->increment('sort_order');
            DB::table('menu_positions')->insert([
                'company_id' => $cid, 'item_key' => self::ITEM, 'parent_key' => 'custom_tc_flota', 'sort_order' => $sort,
                'external' => 0, 'is_custom' => 0, 'hidden' => 0, 'location' => 'sidebar', 'created_at' => $now, 'updated_at' => $now,
            ]);
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

    public function down(): void
    {
        DB::table('menu_positions')->where('item_key', self::ITEM)->delete();
        $pid = DB::table('permissions')->where('name', self::PERM)->value('id');
        if ($pid) {
            DB::table('permission_role')->where('permission_id', $pid)->delete();
            DB::table('permissions')->where('id', $pid)->delete();
        }
        Schema::dropIfExists('transport_schedule_prices');
        Schema::dropIfExists('transport_schedule_slots');
        Schema::dropIfExists('transport_schedule_plans');
    }
};
