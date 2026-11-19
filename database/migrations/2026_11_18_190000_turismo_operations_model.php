<?php

use App\Support\CompanyLogins;
use App\Support\TransportAgencies;
use App\Support\TranslationKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

/**
 * Turismo Cautivo after the presentation (user's points, 2026-11-18):
 *  - no hostesses and no snacks; usually ONE pilot (trips under 12 h): settings.transport_hostesses = 0,
 *    transport_copilot_after_h = 12 (a second pilot only on longer trips), transport_meal_cost = 0;
 *  - ~80 % of the tickets are sold online by Jelaf / PeruBus: transport_tickets.channel (agency / jelaf / perubus / phone),
 *    settings.transport_channel_commission (JSON, % per channel, unknown yet); the demo's tickets get their channel;
 *  - roles: "Operaciones" (Daniel) = agency supervisor + fleet supervisor + quality control, "Administración y Contabilidad"
 *    (Mayte; access login turismo-contabilidad@kcoltech.com until her email is known), "Agente de agencia" (the one person of a
 *    pass-through agency: Chepén, Trujillo);
 *  - permission transport_outlook: the predictive board "Mando operativo".
 * Columns for every company with their old behaviour as default; the rest only for Turismo. Safe to run twice; by name.
 */
return new class extends Migration
{
    private const TEXTS = [
        'transport_outlook' => ['Transport: operations outlook (upcoming departures at risk, marketing focus)', 'Transporte: mando operativo (salidas en riesgo, foco de marketing)'],
        'Transport_Outlook' => ['Operations outlook', 'Mando operativo'],
    ];

    private const ACCOUNTING_ROLE = 'Administración y Contabilidad';

    private const AGENT_ROLE = 'Agente de agencia';

    public function up(): void
    {
        Schema::table('settings', function (Blueprint $t) {
            if (! Schema::hasColumn('settings', 'transport_hostesses')) {
                $t->boolean('transport_hostesses')->default(true);
            }
            if (! Schema::hasColumn('settings', 'transport_copilot_after_h')) {
                $t->decimal('transport_copilot_after_h', 4, 1)->nullable(); // null = every trip has 2 pilots (as before)
            }
            if (! Schema::hasColumn('settings', 'transport_channel_commission')) {
                $t->text('transport_channel_commission')->nullable();
            }
        });
        if (! Schema::hasColumn('transport_tickets', 'channel')) {
            Schema::table('transport_tickets', function (Blueprint $t) {
                $t->string('channel', 20)->nullable()->after('price');
                $t->index(['company_id', 'channel']);
            });
        }

        // A ticket sold by Jelaf / PeruBus is not a sale of ours: no sale behind it.
        $col = DB::selectOne("SHOW COLUMNS FROM transport_tickets LIKE 'sale_id'");
        if ($col && $col->Null === 'NO') {
            DB::statement('ALTER TABLE transport_tickets MODIFY sale_id INT UNSIGNED NULL');
        }

        if (! DB::table('permissions')->where('name', 'transport_outlook')->exists()) {
            DB::table('permissions')->insert(['name' => 'transport_outlook', 'label' => self::TEXTS['transport_outlook'][0], 'created_at' => now(), 'updated_at' => now()]);
        }
        $pid = DB::table('permissions')->where('name', 'transport_outlook')->value('id');
        foreach (DB::table('roles')->whereIn('name', ['IT Admin', 'Manager'])->whereNull('deleted_at')->pluck('id') as $rid) {
            if (! DB::table('permission_role')->where('role_id', $rid)->where('permission_id', $pid)->exists()) {
                DB::table('permission_role')->insert(['role_id' => $rid, 'permission_id' => $pid]);
            }
        }
        // Menu: right before the fleet board, wherever each company has it.
        foreach (DB::table('menu_positions')->where('item_key', '/app/transport/fleet-board')->get(['company_id', 'parent_key', 'sort_order']) as $p) {
            if (DB::table('menu_positions')->where('company_id', $p->company_id)->where('item_key', '/app/transport/outlook')->exists()) {
                continue;
            }
            DB::table('menu_positions')->where('company_id', $p->company_id)->where('parent_key', $p->parent_key)->where('sort_order', '>=', $p->sort_order)->increment('sort_order');
            DB::table('menu_positions')->insert(['company_id' => $p->company_id, 'item_key' => '/app/transport/outlook', 'parent_key' => $p->parent_key,
                'sort_order' => (int) $p->sort_order, 'external' => 0, 'is_custom' => 0, 'hidden' => 0, 'location' => 'sidebar', 'created_at' => now(), 'updated_at' => now()]);
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

        $cid = (int) DB::table('companies')->where('name', 'like', 'Turismo Cautivo%')->whereNull('deleted_at')->value('id');
        if (! $cid) {
            return;
        }
        DB::transaction(function () use ($cid) {
            DB::table('settings')->where('company_id', $cid)->update(['transport_hostesses' => 0, 'transport_copilot_after_h' => 12,
                'transport_meal_cost' => 0, 'updated_at' => now()]);

            // The demo's tickets: ~55 % Jelaf, ~25 % PeruBus, the rest at the agency (the client's own figure: 80 % online).
            DB::statement("UPDATE transport_tickets k JOIN demo_records d ON d.record_type = 'transport_tickets' AND d.record_id = k.id AND d.company_id = k.company_id
                SET k.channel = CASE WHEN k.id % 20 < 11 THEN 'jelaf' WHEN k.id % 20 < 16 THEN 'perubus' ELSE 'agency' END
                WHERE k.company_id = ? AND k.channel IS NULL", [$cid]);
            DB::table('transport_tickets')->where('company_id', $cid)->whereNull('channel')->whereNotNull('sale_id')->update(['channel' => 'agency']);

            TransportAgencies::operations($cid);
            $this->accounting($cid);
            $this->agents($cid);
        });
    }

    /** Mayte: money, accounting, payroll and reports, the departures report first; no configuration, no operating the trips. */
    private function accounting(int $cid): void
    {
        $role = DB::table('roles')->where('company_id', $cid)->where('name', self::ACCOUNTING_ROLE)->whereNull('deleted_at')->value('id');
        if (! $role) {
            $role = DB::table('roles')->insertGetId(['company_id' => $cid, 'name' => self::ACCOUNTING_ROLE, 'label' => self::ACCOUNTING_ROLE,
                'description' => 'Administration and accounting (head office, Chiclayo): the departures report, expenses, payroll, accounting and reports. No configuration.',
                'home_path' => null, 'status' => 1, 'created_at' => now(), 'updated_at' => now()]);
        }
        $manager = DB::table('roles')->where('company_id', $cid)->where('name', 'Manager')->whereNull('deleted_at')->value('id');
        $perms = DB::table('permission_role as pr')->join('permissions as p', 'p.id', '=', 'pr.permission_id')->where('pr.role_id', $manager)
            ->whereNotIn('p.name', ['transport_manage', 'transport_supervise', 'transport_departures_manage', 'transport_boarding', 'transport_tolls',
                'fleet_vehicles_add', 'fleet_vehicles_edit', 'fleet_vehicles_delete', 'transport_report'])
            ->pluck('p.id')->merge(DB::table('permissions')->whereIn('name', ['transport_outlook', 'transport_departures_view'])->pluck('id'))->unique();
        DB::table('permission_role')->where('role_id', $role)->delete();
        DB::table('permission_role')->insert($perms->map(fn ($p) => ['permission_id' => $p, 'role_id' => $role])->values()->all());

        $email = 'turismo-contabilidad@kcoltech.com';
        $uid = DB::table('users')->where('email', $email)->value('id');
        if (! $uid) {
            $username = DB::table('users')->where('username', 'turismo-contabilidad')->exists() ? 'turismo-contabilidad-'.$cid : 'turismo-contabilidad';
            $uid = DB::table('users')->insertGetId(['company_id' => $cid, 'firstname' => 'Mayte', 'lastname' => 'Contabilidad', 'username' => $username,
                'email' => $email, 'password' => Hash::make(CompanyLogins::ACCESS_PASSWORD), 'phone' => '', 'role_id' => $role, 'type' => 'admin',
                'statut' => 1, 'is_all_warehouses' => 1, 'record_view' => 1, 'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('role_user')->where('user_id', $uid)->delete();
        DB::table('role_user')->insert(['user_id' => $uid, 'role_id' => $role]);
        DB::table('users')->where('id', $uid)->update(['role_id' => $role, 'updated_at' => now()]);
    }

    /** The one person of a pass-through agency (Chepén, Trujillo): sells last minute, receives the bus, boards passengers and parcels. */
    private function agents(int $cid): void
    {
        $role = DB::table('roles')->where('company_id', $cid)->where('name', self::AGENT_ROLE)->whereNull('deleted_at')->value('id');
        if (! $role) {
            $role = DB::table('roles')->insertGetId(['company_id' => $cid, 'name' => self::AGENT_ROLE, 'label' => self::AGENT_ROLE,
                'description' => 'The only person of a pass-through agency: last-minute passengers, receives the bus, boarding, parcels, own cash register and attendance.',
                'home_path' => '/pos', 'status' => 1, 'created_at' => now(), 'updated_at' => now()]);
        }
        $perms = array_merge(TransportAgencies::POS_PERMISSIONS, ['transport_boarding', 'transport_agency_view', 'transport_departures_view', 'attendance_self']);
        DB::table('permission_role')->where('role_id', $role)->delete();
        DB::table('permission_role')->insert(DB::table('permissions')->whereIn('name', $perms)->pluck('id')->map(fn ($p) => ['permission_id' => $p, 'role_id' => $role])->all());
        foreach (DB::table('users')->where('company_id', $cid)->whereIn('email', ['turismo-pos-chepen@kcoltech.com', 'turismo-pos-trujillo@kcoltech.com'])->pluck('id') as $uid) {
            DB::table('role_user')->where('user_id', $uid)->delete();
            DB::table('role_user')->insert(['user_id' => $uid, 'role_id' => $role]);
            DB::table('users')->where('id', $uid)->update(['role_id' => $role, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        // Roles and logins stay (people may already use them); the columns stay with their old-behaviour defaults.
    }
};
