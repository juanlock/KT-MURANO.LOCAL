<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * New Era (company 3) -- three roles (user's request, 2026-10-06), like Ruba's:
 *  - AdminNE  (role "Admin NE"): everything in the salon (all permissions;
 *    modules switched off for the company stay hidden anyway).
 *  - ManagerNE (new): appointments for every stylist, POS + open / close the
 *    register, customers, products & services (add / edit, categories, brands),
 *    Email Office (read / link emails), staff hours (attendance) and employee
 *    records (view), register report. Home: the booking calendar.
 *  - StaffNE (role "Staff NE", trimmed): their own appointments + POS (sell,
 *    take payment, open / close the register); their own employee record
 *    (basic, production, payments -- view). No products / services, no customer
 *    list, no editing or deleting sales. Home: the booking calendar.
 * Liz and Jessi (on the generic "Employee" role) move to StaffNE.
 */
return new class extends Migration
{
    private const MANAGER = [
        'dashboard', 'bookings', 'booking_other_staff', 'trays',
        'Pos_view', 'Sales_view', 'Sales_add', 'edit_product_sale', 'payment_sales_view', 'payment_sales_add', 'cash_register_report',
        'Customers_view', 'Customers_add', 'Customers_edit',
        'products_view', 'products_add', 'products_edit', 'category', 'brand', 'barcode_view',
        'email_office_view',
        'attendance', 'report_attendance_summary', 'leave',
        'view_employee', 'employee_view_all',
        'employee_tab_basic_view', 'employee_tab_company_view', 'employee_tab_production_view', 'employee_tab_payments_view', 'employee_tab_user_view',
        'menu_hide:/app/commissions/programs', 'menu_hide:/app/commissions/agents', 'menu_hide:/app/commissions/rules',
    ];

    private const STAFF = [
        'bookings',
        'Pos_view', 'Sales_add', 'payment_sales_add',
        'view_employee', 'employee_tab_basic_view', 'employee_tab_production_view', 'employee_tab_payments_view',
    ];

    public function up(): void
    {
        if (! DB::table('companies')->where('id', 3)->exists()) {
            return;
        }
        $permId = fn (string $name) => DB::table('permissions')->where('name', $name)->value('id')
            ?: DB::table('permissions')->insertGetId(['name' => $name, 'label' => $name, 'description' => $name, 'created_at' => now(), 'updated_at' => now()]);
        $setPerms = function (int $roleId, array $names) use ($permId) {
            DB::table('permission_role')->where('role_id', $roleId)->delete();
            foreach (array_unique($names) as $n) {
                DB::table('permission_role')->insert(['role_id' => $roleId, 'permission_id' => $permId($n)]);
            }
        };
        $role = function (string $name, array $find, string $label, string $description, ?string $home) {
            $id = DB::table('roles')->where('company_id', 3)->whereNull('deleted_at')->whereIn('name', $find)->value('id');
            $values = ['name' => $name, 'label' => $label, 'description' => $description, 'home_path' => $home, 'updated_at' => now()];
            if ($id) {
                DB::table('roles')->where('id', $id)->update($values);

                return (int) $id;
            }

            return (int) DB::table('roles')->insertGetId($values + ['company_id' => 3, 'status' => 0, 'created_at' => now()]);
        };

        // AdminNE: everything (the full Admin-ALL set of the install).
        $admin = $role('AdminNE', ['AdminNE', 'Admin NE'], 'Admin NE', 'Salon admin: everything in New Era.', null);
        $allIds = DB::table('permissions')->where('name', 'not like', 'menu_hide:%')->pluck('id');
        DB::table('permission_role')->where('role_id', $admin)->delete();
        foreach ($allIds as $pid) {
            DB::table('permission_role')->insert(['role_id' => $admin, 'permission_id' => $pid]);
        }

        $manager = $role('ManagerNE', ['ManagerNE'], 'Manager NE',
            'Salon manager: appointments for all staff, POS + register, customers, products & services, Email Office, staff hours. Home: booking calendar.', '/bookings/calendar');
        $setPerms($manager, self::MANAGER);

        $staff = $role('StaffNE', ['StaffNE', 'Staff  NE', 'Staff NE', 'Salon NE'], 'Staff NE',
            'Stylist: own appointments and POS (sell, payments, open / close the register). No products, services or customer list. Home: booking calendar.', '/bookings/calendar');
        $setPerms($staff, self::STAFF);

        // Liz and Jessi: from the generic Employee role to StaffNE (both places the role is stored).
        foreach (DB::table('users')->where('company_id', 3)->whereIn('username', ['liz_escalona', 'jessi_marroquin'])->pluck('id') as $uid) {
            DB::table('role_user')->where('user_id', $uid)->delete();
            DB::table('role_user')->insert(['user_id' => $uid, 'role_id' => $staff, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('users')->where('id', $uid)->update(['role_id' => $staff]);
        }
    }

    public function down(): void
    {
    }
};
