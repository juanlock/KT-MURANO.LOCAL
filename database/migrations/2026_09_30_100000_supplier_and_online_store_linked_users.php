<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Linked users for the two flows that didn't have one yet:
 *
 *  - Suppliers: providers.user_id (same nullable/unique link as
 *    clients.user_id / employees.user_id) + a per-company "Supplier" role,
 *    auto-assigned by LinkedUserService when a supplier is created/imported.
 *    View-only access to Purchases, purchase payments and Suppliers.
 *
 *  - Web store sign-ups: a per-company "Online Store" role, auto-assigned to
 *    the users row created once a storefront account is confirmed (email
 *    and/or admin approval, per Store Settings). Same view-only scope as the
 *    Customer role.
 *
 * Roles are added to EVERY company including the template (999), so
 * CompanyProvisioner copies them into companies created later -- same
 * per-company pattern as 2026_09_23_200100_create_sales_agent_role_per_company.
 * Both roles stay editable in Permissions like any other.
 */
return new class extends Migration
{
    private const ROLES = [
        'Supplier' => [
            'description' => 'Auto-assigned to accounts created from the Suppliers screen. View-only access to Purchases, purchase payments and Suppliers.',
            'permissions' => ['Purchases_view', 'payment_purchases_view', 'Suppliers_view'],
        ],
        'Online Store' => [
            'description' => 'Auto-assigned to web store accounts once confirmed (email and/or admin approval, per Store Settings). View-only access to their own profile and their own sales.',
            'permissions' => ['Sales_view', 'Customers_view'],
        ],
    ];

    public function up(): void
    {
        if (! Schema::hasColumn('providers', 'user_id')) {
            Schema::table('providers', function (Blueprint $table) {
                // int(11) like users.id / clients.user_id
                $table->integer('user_id')->nullable()->unique()->after('company_id');
            });
        }

        $now = now();
        foreach (DB::table('companies')->pluck('id') as $companyId) {
            foreach (self::ROLES as $name => $def) {
                if (DB::table('roles')->where('company_id', $companyId)->where('name', $name)->whereNull('deleted_at')->exists()) {
                    continue;
                }
                $roleId = DB::table('roles')->insertGetId([
                    'company_id' => $companyId,
                    'name' => $name,
                    'label' => $name,
                    'description' => $def['description'],
                    'status' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $rows = DB::table('permissions')->whereIn('name', $def['permissions'])->pluck('id')
                    ->map(fn ($pid) => ['permission_id' => $pid, 'role_id' => $roleId])->all();
                if ($rows) {
                    DB::table('permission_role')->insert($rows);
                }
            }
        }
    }

    public function down(): void
    {
        $roleIds = DB::table('roles')->whereIn('name', array_keys(self::ROLES))->pluck('id');
        DB::table('permission_role')->whereIn('role_id', $roleIds)->delete();
        DB::table('roles')->whereIn('id', $roleIds)->delete();

        if (Schema::hasColumn('providers', 'user_id')) {
            Schema::table('providers', function (Blueprint $table) {
                $table->dropUnique(['user_id']);
                $table->dropColumn('user_id');
            });
        }
    }
};
