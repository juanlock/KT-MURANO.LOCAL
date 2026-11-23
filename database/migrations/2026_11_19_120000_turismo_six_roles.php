<?php

use App\Support\CompanyLogins;
use App\Support\UserAccess;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

/**
 * Turismo Cautivo, simplified implementation (user's rule, 2026-11-19: the client was overwhelmed; "nos vamos a focalizar en lo que
 * tiene ahorita"). Six roles, each with its own dashboard:
 *  - Director (Julio, turismo-director@kcoltech.com): every board;
 *  - Manager (Mayte, turismo-manager@kcoltech.com): departures report, business summary, expenses;
 *  - Operaciones (Daniel): control board, operations outlook, complaints, transport board;
 *  - Agencia (ticket office, parcels and boarding of each agency; was Terminal POS + Agente de agencia): home "Mi parada";
 *  - Piloto: "Mi viaje";
 *  - IT Admin: configures the transport module (routes, trips, tolls, staff, products).
 * roles.dashboard_tabs (new): the dashboard tabs the role sees, null = every tab its permissions allow.
 * Retired at Turismo (logins disabled, role removed): Administración y Contabilidad (-> Manager), Terminal POS (-> Agencia),
 * Tripulación, Mecánico, Info, Helpdesk, Website, Sales Agent, Online Store; the per-agency supervisor and quality access logins.
 * Module Email Office off (Suppliers stays: the workshops are suppliers). Menu: "Módulos adicionales" hidden (Forms moved to
 * Back Office), Today's departures / fuel / assignments / fleet reports hidden; products and the transport setup screens only
 * for the IT Admin (menu_hide on the other roles). Safe to run twice; by name.
 */
return new class extends Migration
{
    private const TABS = [
        'Manager' => ['transport_departures', 'transport_owner', 'transport_expenses'],
        'Operaciones' => ['transport_monitor', 'transport_outlook', 'transport_claims', 'transport'],
        'IT Admin' => ['transport_monitor', 'transport'],
    ];

    private const RETIRED = ['Administración y Contabilidad', 'Terminal POS', 'Tripulación', 'Mecánico', 'Info', 'Helpdesk', 'Website', 'Sales Agent', 'Online Store'];

    private const SETUP_SCREENS = ['/app/transport/routes', '/app/transport/trips', '/app/transport/tolls', '/app/transport/staff',
        '/app/products/store', '/app/products/list', '/app/products/import', '/app/products/import-update', '/app/products/opening_stock_import',
        '/app/products/barcode', '/app/products/count_stock', '/app/products/Categories', '/app/products/SubCategories', '/app/products/Brands',
        '/app/products/SizeGuides', '/app/products/Units', '/app/products/vehicle_fitment', '/app/products/Batches'];

    private const HIDDEN_MENU = ['custom_menul1', '/app/transport/today', '/app/fleet/fuel-logs', '/app/fleet/assignments', '/app/fleet/reports'];

    public function up(): void
    {
        if (! Schema::hasColumn('roles', 'dashboard_tabs')) {
            Schema::table('roles', function (Blueprint $t) {
                $t->text('dashboard_tabs')->nullable()->after('home_path');
            });
        }
        $cid = (int) DB::table('companies')->where('name', 'like', 'Turismo Cautivo%')->whereNull('deleted_at')->value('id');
        if (! $cid) {
            return;
        }
        $role = fn (string $name) => DB::table('roles')->where('company_id', $cid)->where('name', $name)->whereNull('deleted_at')->value('id');
        $assign = function (int $uid, int $roleId) {
            DB::table('role_user')->where('user_id', $uid)->delete();
            DB::table('role_user')->insert(['user_id' => $uid, 'role_id' => $roleId]);
            DB::table('users')->where('id', $uid)->update(['role_id' => $roleId, 'updated_at' => now()]);
        };
        $grant = function (int $p, int $r) {
            if ($p && ! DB::table('permission_role')->where('permission_id', $p)->where('role_id', $r)->exists()) {
                DB::table('permission_role')->insert(['permission_id' => $p, 'role_id' => $r]);
            }
        };
        $manager = (int) $role('Manager');
        if (! $manager) {
            return;
        }

        // Director: the Manager's permissions, every board.
        $director = (int) $role('Director');
        if (! $director) {
            $director = (int) DB::table('roles')->insertGetId(['company_id' => $cid, 'name' => 'Director', 'label' => 'Director',
                'description' => 'The owner: every board and every module of the business, no configuration.',
                'home_path' => null, 'status' => 1, 'created_at' => now(), 'updated_at' => now()]);
        }
        $perms = DB::table('permission_role as pr')->join('permissions as p', 'p.id', '=', 'pr.permission_id')->where('pr.role_id', $manager)
            ->where('p.name', 'not like', 'menu\_hide:%')->pluck('p.id')
            ->merge(DB::table('permissions')->whereIn('name', ['transport_outlook', 'transport_profit', 'dashboard'])->pluck('id'))->unique();
        DB::table('permission_role')->where('role_id', $director)->delete();
        DB::table('permission_role')->insert($perms->map(fn ($p) => ['permission_id' => $p, 'role_id' => $director])->values()->all());

        $email = 'turismo-director@kcoltech.com';
        $uid = (int) DB::table('users')->where('email', $email)->value('id');
        if (! $uid) {
            $username = DB::table('users')->where('username', 'turismo-director')->exists() ? 'turismo-director-'.$cid : 'turismo-director';
            $uid = (int) DB::table('users')->insertGetId(['company_id' => $cid, 'firstname' => 'Julio', 'lastname' => 'Valladares', 'username' => $username,
                'email' => $email, 'password' => Hash::make(CompanyLogins::ACCESS_PASSWORD), 'phone' => '', 'role_id' => $director, 'type' => 'admin',
                'statut' => 1, 'is_all_warehouses' => 1, 'record_view' => 1, 'created_at' => now(), 'updated_at' => now()]);
        }
        $assign($uid, $director);

        // Manager = Mayte.
        DB::table('users')->where('email', 'turismo-manager@kcoltech.com')->update(['firstname' => 'Mayte', 'lastname' => 'Administración', 'updated_at' => now()]);

        // Agencia: the pass-through agent's role renamed; the ticket offices of Lima and Chiclayo join it.
        $agency = (int) ($role('Agencia') ?: $role('Agente de agencia'));
        if ($agency) {
            DB::table('roles')->where('id', $agency)->update(['name' => 'Agencia', 'label' => 'Agencia', 'home_path' => '/transport/stop',
                'description' => 'Staff of one agency: tickets on the POS, parcels, boarding, the buses arriving and leaving, own cash register and attendance.',
                'updated_at' => now()]);
            $pos = $role('Terminal POS');
            if ($pos) {
                foreach (DB::table('users')->where('role_id', $pos)->whereNull('deleted_at')->pluck('id') as $u) {
                    $assign((int) $u, $agency);
                }
            }
            $add = DB::table('permissions')->whereIn('name', ['transport_stop', 'transport_boarding', 'transport_parcels', 'transport_agency_view', 'transport_departures_view', 'attendance_self'])->pluck('id');
            foreach ($add as $p) {
                $grant((int) $p, $agency);
            }
        }

        // Operaciones: its own dashboard (needs the dashboard permission); the extra access logins go.
        $ops = (int) $role('Operaciones');
        if ($ops) {
            DB::table('roles')->where('id', $ops)->update(['home_path' => null, 'updated_at' => now()]);
            $grant((int) DB::table('permissions')->where('name', 'dashboard')->value('id'), $ops);
            foreach (DB::table('users')->where('company_id', $cid)->where(fn ($q) => $q->where('email', 'like', 'turismo-super-%@kcoltech.com')
                ->orWhere('email', 'turismo-calidad@kcoltech.com'))->where('statut', 1)->pluck('id') as $u) {
                UserAccess::disable((int) $u);
            }
        }

        // Dashboard tabs per role.
        foreach (self::TABS as $name => $tabs) {
            DB::table('roles')->where('company_id', $cid)->where('name', $name)->whereNull('deleted_at')->update(['dashboard_tabs' => json_encode($tabs)]);
        }

        // Retired roles: their logins disabled, the role removed.
        foreach (self::RETIRED as $name) {
            $id = $role($name);
            if (! $id) {
                continue;
            }
            foreach (DB::table('users')->where('role_id', $id)->where('statut', 1)->pluck('id') as $u) {
                UserAccess::disable((int) $u);
            }
            DB::table('roles')->where('id', $id)->update(['deleted_at' => now(), 'updated_at' => now()]);
        }

        // Modules: Email Office off.
        $raw = DB::table('settings')->where('company_id', $cid)->whereNull('deleted_at')->value('module_flags');
        $flags = is_string($raw) ? (json_decode($raw, true) ?: []) : [];
        $flags['email_office'] = false;
        DB::table('settings')->where('company_id', $cid)->update(['module_flags' => json_encode($flags)]);

        // Menu: Forms to Back Office, then the extra modules group and the screens nobody uses hidden.
        $back = DB::table('menu_positions')->where('company_id', $cid)->where('item_key', 'custom_tc_backoffice')->exists();
        if ($back) {
            $max = (int) DB::table('menu_positions')->where('company_id', $cid)->where('parent_key', 'custom_tc_backoffice')->max('sort_order');
            DB::table('menu_positions')->where('company_id', $cid)->where('item_key', 'forms')->where('parent_key', '!=', 'custom_tc_backoffice')
                ->update(['parent_key' => 'custom_tc_backoffice', 'sort_order' => $max + 1, 'hidden' => 0, 'updated_at' => now()]);
        }
        DB::table('menu_positions')->where('company_id', $cid)->whereIn('item_key', self::HIDDEN_MENU)->update(['hidden' => 1, 'updated_at' => now()]);

        // Products and the transport setup screens: only the IT Admin.
        foreach (['Director', 'Manager', 'Operaciones'] as $name) {
            $id = $role($name);
            if (! $id) {
                continue;
            }
            foreach (self::SETUP_SCREENS as $path) {
                $p = DB::table('permissions')->where('name', 'menu_hide:'.$path)->value('id')
                    ?: DB::table('permissions')->insertGetId(['name' => 'menu_hide:'.$path, 'created_at' => now(), 'updated_at' => now()]);
                $grant((int) $p, (int) $id);
            }
        }
    }

    public function down(): void
    {
        // People may already use the new roles; nothing is put back.
    }
};
