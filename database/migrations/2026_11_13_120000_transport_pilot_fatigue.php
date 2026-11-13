<?php

use App\Support\TranslationKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Pilots' fatigue and the fleet supervisor (Turismo Cautivo, user's request 2026-11-13):
 *  - transport_trip_swaps: when the pilot handed the wheel to the copilot on a long trip ("Mi viaje" / control board);
 *  - settings.transport_fatigue: the company's limits (App\Services\PilotFatigue::DEFAULTS when empty);
 *  - permission transport_fleet_board + page "Tablero de flota" (/transport/fleet-board): pilots' alerts, control board,
 *    transport board -- for the IT Admin, the Manager and the new role "Supervisor de flota" (keeps the fleet running:
 *    maintenance, the pilots' reports and their hours; no money), with its login {slug}-flota@kcoltech.com in every
 *    company with transport agencies (App\Support\TransportAgencies::fleetSupervisor).
 * Safe to run twice.
 */
return new class extends Migration
{
    private const PERM = 'transport_fleet_board';

    private const ITEM = '/app/transport/fleet-board';

    private const TEXTS = [
        'Transport_FleetBoard' => ['Fleet board', 'Tablero de flota'],
        'transport_fleet_board' => ['Transport: fleet board (pilots\' alerts, control board)', 'Transporte: tablero de flota (alertas de pilotos, tablero de control)'],
        'Perm_Transport_FleetBoard' => ['Fleet board: pilots\' hours and alerts, control board', 'Tablero de flota: horas y alertas de los pilotos, tablero de control'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('transport_trip_swaps')) {
            Schema::create('transport_trip_swaps', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id')->index();
                $t->unsignedBigInteger('trip_id')->index();
                $t->unsignedBigInteger('user_id')->nullable();
                $t->dateTime('swapped_at');
                $t->decimal('lat', 10, 7)->nullable();
                $t->decimal('lng', 10, 7)->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasColumn('settings', 'transport_fatigue')) {
            Schema::table('settings', fn (Blueprint $t) => $t->text('transport_fatigue')->nullable());
        }

        if (! DB::table('permissions')->where('name', self::PERM)->exists()) {
            DB::table('permissions')->insert(['name' => self::PERM, 'label' => self::TEXTS[self::PERM][0], 'created_at' => now(), 'updated_at' => now()]);
        }
        $pid = DB::table('permissions')->where('name', self::PERM)->value('id');
        foreach (DB::table('roles')->whereIn('name', ['IT Admin', 'Manager'])->whereNull('deleted_at')->pluck('id') as $rid) {
            if (! DB::table('permission_role')->where('role_id', $rid)->where('permission_id', $pid)->exists()) {
                DB::table('permission_role')->insert(['role_id' => $rid, 'permission_id' => $pid]);
            }
        }

        // Menu: right before the control board, wherever each company has it.
        $now = now();
        foreach (DB::table('menu_positions')->where('item_key', '/app/transport/monitor')->get(['company_id', 'parent_key', 'sort_order']) as $p) {
            if (DB::table('menu_positions')->where('company_id', $p->company_id)->where('item_key', self::ITEM)->exists()) {
                continue;
            }
            DB::table('menu_positions')->where('company_id', $p->company_id)->where('parent_key', $p->parent_key)->where('sort_order', '>=', $p->sort_order)->increment('sort_order');
            DB::table('menu_positions')->insert([
                'company_id' => $p->company_id, 'item_key' => self::ITEM, 'parent_key' => $p->parent_key, 'sort_order' => (int) $p->sort_order,
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

        // The fleet supervisor of every company with transport agencies.
        $companies = DB::table('transport_locations')->where('kind', 'agency')->whereNull('deleted_at')->distinct()->pluck('company_id');
        foreach ($companies as $cid) {
            \App\Support\TransportAgencies::fleetSupervisor((int) $cid);
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
        if (Schema::hasColumn('settings', 'transport_fatigue')) {
            Schema::table('settings', fn (Blueprint $t) => $t->dropColumn('transport_fatigue'));
        }
        Schema::dropIfExists('transport_trip_swaps');
        // The fleet supervisor's role and login stay: people may already use them.
    }
};
