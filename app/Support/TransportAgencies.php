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
    public const POS_PERMISSIONS = ['Pos_view', 'Sales_view', 'Sales_add', 'Customers_view', 'Customers_add', 'register_open_close'];

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
        self::posRole($companyId);

        return $map;
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
