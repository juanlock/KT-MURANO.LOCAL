<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * A transport company's agencies are its Locations (user's rule, 2026-11-03):
 * each agency (transport_locations, kind agency) has its warehouse
 * (transport_locations.warehouse_id) -- sales, cash registers and users per
 * agency --, and its ticket office sells on the POS with a login of role
 * "Terminal POS" assigned to that agency only. Only logins with every location
 * (the Manager, the IT Admin) see all the agencies together.
 */
class TransportAgencies
{
    public const POS_ROLE = 'Terminal POS';

    /** What a ticket office does: the POS, its own sales, customers (passengers) and its cash register. */
    public const POS_PERMISSIONS = ['Pos_view', 'Sales_view', 'Sales_add', 'Customers_view', 'Customers_add', 'register_open_close', 'transport_parcels'];

    public const SUPERVISOR_ROLE = 'Supervisor';

    /** The agency's supervisor (2026-11-04): decides the day's departures, registers parcels and tolls. */
    public const SUPERVISOR_PERMISSIONS = ['transport_supervise', 'transport_parcels', 'transport_tolls', 'transport_departures_view', 'transport_departures_manage', 'transport_agency_view'];

    /**
     * Each active agency gets its warehouse (the first one takes the company's
     * default location when no agency has it yet), and the company gets the
     * Terminal POS role. Safe to run twice. Returns [agency id => warehouse id].
     */
    public static function sync(int $companyId): array
    {
        $agencies = DB::table('transport_locations')->where('company_id', $companyId)->where('kind', 'agency')
            ->whereNull('deleted_at')->where('active', 1)->orderBy('id')->get();
        if ($agencies->isEmpty()) {
            return [];
        }
        $es = str_starts_with((string) DB::table('settings')->where('company_id', $companyId)->whereNull('deleted_at')->value('default_language'), 'es');
        $default = (int) DB::table('settings')->where('company_id', $companyId)->whereNull('deleted_at')->value('warehouse_id');
        $map = [];
        foreach ($agencies as $a) {
            $wid = (int) $a->warehouse_id;
            if (! $wid || ! DB::table('warehouses')->where('id', $wid)->whereNull('deleted_at')->exists()) {
                $name = ($es ? 'Agencia ' : 'Agency ').$a->name;
                $taken = DB::table('transport_locations')->where('company_id', $companyId)->where('warehouse_id', $default)->exists();
                if ($default && ! $taken && ! $map) {
                    $wid = $default; // the main location becomes the first agency
                    DB::table('warehouses')->where('id', $wid)->update(['name' => $name, 'city' => $a->city, 'updated_at' => now()]);
                } else {
                    $wid = (int) DB::table('warehouses')->insertGetId([
                        'company_id' => $companyId, 'name' => $name, 'city' => $a->city, 'mobile' => $a->phone,
                        'country' => DB::table('companies')->where('id', $companyId)->value('country'),
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
                DB::table('transport_locations')->where('id', $a->id)->update(['warehouse_id' => $wid, 'updated_at' => now()]);
            }
            $map[(int) $a->id] = $wid;
        }
        self::ticketOffice($companyId, self::posRole($companyId), array_values($map));
        self::staffRoles($companyId);
        self::supervisors($companyId, $map);

        return $map;
    }

    /**
     * One supervisor per agency (user's request, 2026-11-04): an employee with the login
     * {slug}-super-{agency}@kcoltech.com, role Supervisor, that agency only, home page "Today's
     * departures", the standard employee password (changed at the first sign-in).
     * Made once: a login already there is left as it is.
     */
    public static function supervisors(int $companyId, array $map): void
    {
        $role = DB::table('roles')->where('company_id', $companyId)->where('name', self::SUPERVISOR_ROLE)->whereNull('deleted_at')->value('id');
        if (! $role) {
            $role = DB::table('roles')->insertGetId(['company_id' => $companyId, 'name' => self::SUPERVISOR_ROLE, 'label' => self::SUPERVISOR_ROLE,
                'description' => 'Supervisor of one agency: decides whether each departure runs, is cancelled or is rescheduled; registers parcels and tolls.',
                // Home: "Mi agencia" (user's rule, 2026-11-11).
                'home_path' => '/transport/agency', 'status' => 1, 'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('permission_role')->where('role_id', $role)->delete();
        DB::table('permission_role')->insert(DB::table('permissions')->whereIn('name', self::SUPERVISOR_PERMISSIONS)->pluck('id')
            ->map(fn ($p) => ['permission_id' => $p, 'role_id' => $role])->all());

        $dept = DB::table('departments')->where('company_id', $companyId)->whereNull('deleted_at')->orderBy('id')->value('id');
        $shift = DB::table('office_shifts')->where('company_id', $companyId)->whereNull('deleted_at')->orderBy('id')->value('id');
        if (! $dept || ! $shift) {
            return; // no HR frame yet: the login comes with the next sync
        }
        $designation = DB::table('designations')->where('company_id', $companyId)->whereNull('deleted_at')->where('designation', 'Supervisor')->value('id')
            ?: DB::table('designations')->insertGetId(['company_id' => $companyId, 'department_id' => $dept, 'designation' => 'Supervisor', 'created_at' => now(), 'updated_at' => now()]);
        $slug = CompanyLogins::slug($companyId);
        foreach ($map as $agencyId => $wid) {
            $agency = (string) DB::table('transport_locations')->where('id', $agencyId)->value('name');
            $email = $slug.'-super-'.\Illuminate\Support\Str::slug($agency, '').'@kcoltech.com';
            if (DB::table('users')->where('email', $email)->exists()) {
                continue;
            }
            $employee = \App\Models\Employee::create([
                'company_id' => $companyId, 'firstname' => 'Supervisor', 'lastname' => $agency, 'username' => 'Supervisor '.$agency, 'email' => $email,
                'gender' => 'other', 'avatar' => 'no_avatar.png', 'department_id' => $dept, 'designation_id' => $designation, 'office_shift_id' => $shift,
                'joining_date' => now()->toDateString(), 'employment_type' => 'full_time', 'total_leave' => 0, 'remaining_leave' => 0, 'basic_salary' => 0,
            ]);
            $userId = (int) DB::table('employees')->where('id', $employee->id)->value('user_id');
            if (! $userId) {
                continue;
            }
            DB::table('users')->where('id', $userId)->update(['role_id' => $role, 'email' => $email, 'statut' => 1, 'is_all_warehouses' => 0, 'record_view' => 0,
                'password' => \Illuminate\Support\Facades\Hash::make(CompanyLogins::PASSWORDS['employee']), 'updated_at' => now()]);
            DB::table('role_user')->where('user_id', $userId)->delete();
            DB::table('role_user')->insert(['user_id' => $userId, 'role_id' => $role]);
            DB::table('user_warehouse')->where('user_id', $userId)->delete();
            DB::table('user_warehouse')->insert(['user_id' => $userId, 'warehouse_id' => $wid]);
        }
    }

    /**
     * The field staff's roles (2026-11-03): each opens its own work screen, not its HR file.
     * designation names => [role, description, home page, permissions].
     */
    public const STAFF_ROLES = [
        'pilot' => [['Piloto', 'Pilot'], 'Piloto', 'Pilot: their trips (route, time, bus, crew), reports from the phone and the buses.', '/transport/my-trip', ['transport_view', 'transport_report', 'fleet_vehicles_view']],
        'hostess' => [['Azafata', 'Hostess'], 'Tripulación', 'Crew: their trips (route, time, bus, crew) and reports from the phone.', '/transport/my-trip', ['transport_view', 'transport_report']],
        'mechanic' => [['Mecánico', 'Mecanico', 'Mechanic'], 'Mecánico', 'Mechanic: the buses and their maintenance.', '/fleet/maintenance', ['fleet_vehicles_view', 'fleet_maintenance']],
    ];

    /** Pilots, hostesses and mechanics still on the generic Employee role get their own role (made when missing). */
    public static function staffRoles(int $companyId): void
    {
        $employeeRole = DB::table('roles')->where('company_id', $companyId)->where('name', 'Employee')->whereNull('deleted_at')->value('id');
        if (! $employeeRole) {
            return;
        }
        foreach (self::STAFF_ROLES as [$designations, $name, $description, $home, $perms]) {
            $users = DB::table('employees as e')->join('designations as d', 'd.id', '=', 'e.designation_id')
                ->join('role_user as ru', 'ru.user_id', '=', 'e.user_id')
                ->where('e.company_id', $companyId)->whereNull('e.deleted_at')->whereNotNull('e.user_id')
                ->whereIn('d.designation', $designations)->where('ru.role_id', $employeeRole)->pluck('e.user_id');
            $roleId = DB::table('roles')->where('company_id', $companyId)->where('name', $name)->whereNull('deleted_at')->value('id');
            if (! $roleId && $users->isEmpty()) {
                continue;
            }
            if (! $roleId) {
                $roleId = DB::table('roles')->insertGetId(['company_id' => $companyId, 'name' => $name, 'label' => $name, 'description' => $description,
                    'home_path' => $home, 'status' => 1, 'created_at' => now(), 'updated_at' => now()]);
                DB::table('permission_role')->insert(DB::table('permissions')->whereIn('name', $perms)->pluck('id')
                    ->map(fn ($p) => ['permission_id' => $p, 'role_id' => $roleId])->all());
            }
            foreach ($users as $uid) {
                DB::table('role_user')->where('user_id', $uid)->update(['role_id' => $roleId]);
                DB::table('users')->where('id', $uid)->update(['role_id' => $roleId, 'updated_at' => now()]);
            }
        }
    }

    /**
     * Ticket office staff (designation Boletería / Ticket office, 2026-11-03) work on the
     * POS: a login still on the generic Employee role gets Terminal POS and, when it has
     * no location, one agency (shared out in turn) -- so it opens the POS, not its file.
     */
    public static function ticketOffice(int $companyId, int $posRole, array $warehouses): void
    {
        $employeeRole = DB::table('roles')->where('company_id', $companyId)->where('name', 'Employee')->whereNull('deleted_at')->value('id');
        if (! $employeeRole || ! $warehouses) {
            return;
        }
        $users = DB::table('employees as e')->join('designations as d', 'd.id', '=', 'e.designation_id')
            ->join('role_user as ru', 'ru.user_id', '=', 'e.user_id')
            ->where('e.company_id', $companyId)->whereNull('e.deleted_at')->whereNotNull('e.user_id')
            ->whereIn('d.designation', ['Boletería', 'Boleteria', 'Ticket office'])->where('ru.role_id', $employeeRole)
            ->orderBy('e.id')->pluck('e.user_id');
        foreach ($users->values() as $i => $uid) {
            DB::table('role_user')->where('user_id', $uid)->update(['role_id' => $posRole]);
            DB::table('users')->where('id', $uid)->update(['role_id' => $posRole, 'is_all_warehouses' => 0, 'updated_at' => now()]);
            if (! DB::table('user_warehouse')->where('user_id', $uid)->exists()) {
                DB::table('user_warehouse')->insert(['user_id' => $uid, 'warehouse_id' => $warehouses[$i % count($warehouses)]]);
            }
        }
    }

    /** The company's Terminal POS role (made when missing; its permissions always the ticket office's). */
    public static function posRole(int $companyId): int
    {
        $id = DB::table('roles')->where('company_id', $companyId)->where('name', self::POS_ROLE)->whereNull('deleted_at')->value('id');
        if (! $id) {
            $id = DB::table('roles')->insertGetId([
                'company_id' => $companyId, 'name' => self::POS_ROLE, 'label' => self::POS_ROLE,
                'description' => 'Ticket office of one agency: sells tickets on the POS, its own sales and cash register.',
                'home_path' => '/pos', 'status' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        DB::table('permission_role')->where('role_id', $id)->delete();
        DB::table('permission_role')->insert(DB::table('permissions')->whereIn('name', self::POS_PERMISSIONS)->pluck('id')
            ->map(fn ($p) => ['permission_id' => $p, 'role_id' => $id])->all());

        return (int) $id;
    }
}
