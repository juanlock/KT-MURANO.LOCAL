<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * The roles of a hospital company (user's rule, 2026-10-21): the old roles
 * are removed and replaced by the hospital's own -- Emergencia, Admisión,
 * Médico, Enfermero, Jefe de enfermería, Farmacia, Caja / Facturación,
 * Laboratorio, Quirófano -- each with its permissions and home page. Kept
 * (the code finds them by name): the company admin's role (Admin-ALL, shown
 * as "Administrador", with everything), Customer (= the patient, "Paciente
 * (cliente)"), Supplier ("Proveedor") and Employee ("Personal sin rol", the
 * role a new employee's login gets until the admin picks the real one).
 * Users of a removed role move to Employee. Safe to run twice: existing
 * hospital roles are brought up to date, never duplicated.
 */
class HospitalRoles
{
    // Helpdesk / Info: the standard access logins of every company (CompanyLogins, 2026-10-25).
    // + every company's standard roles (CompanyRoles, 2026-11-01).
    private const KEEP = ['Admin Compañía' => 'Administrador', 'Customer' => 'Paciente (cliente)', 'Supplier' => 'Proveedor', 'Employee' => 'Personal sin rol',
        'Helpdesk' => 'Helpdesk'];

    /** name => [label, description, home page, permissions] */
    public const ROLES = [
        'Emergency' => ['Emergencia', 'Emergency room: arrivals, triage, the ER board, doctors and patients with their history.', '/hospital/er', [
            'hms_dashboard', 'hms_er', 'hms_admission_desk', 'hms_triage', 'hms_patients_view', 'hms_patients_add', 'hms_patients_edit', 'hms_doctors',
            'hms_visits', 'hms_lab', 'hms_treatments', 'hms_treatments_administer', 'hms_my_patients', 'hms_admissions', 'hms_pharmacy',
        ]],
        'Admission' => ['Admisión', 'Admission desk: arrivals with triage, waiting room, beds, visitors and appointments.', '/hospital/admission-desk', [
            'hms_admission_desk', 'hms_visitors', 'hms_appointments', 'hms_patients_view', 'hms_patients_add', 'hms_patients_edit',
        ]],
        // Triage (2026-10-25): after the admission window, the triage nurse calls the patient
        // to the office, takes vital signs, weight and height, describes the reason and sends them to an ER bed.
        'Triage' => ['Triaje', 'Triage nurse: calls the patients registered by admission, takes vital signs, weight and height, describes why they came and sends them to an ER bed.', '/hospital/triage', [
            'hms_triage', 'hms_patients_view', 'hms_patients_edit',
        ]],
        'Doctor' => ['Médico', 'Doctor: the patients of the shift, consultations, treatments and medication orders, lab, surgeries.', '/hospital/my-patients', [
            'hms_dashboard', 'hms_my_patients', 'hms_patients_view', 'hms_patients_edit', 'hms_appointments', 'hms_visits', 'hms_treatments',
            'hms_treatments_administer', 'hms_lab', 'hms_er', 'hms_admissions', 'hms_surgery', 'hms_pharmacy',
        ]],
        'Nurse' => ['Enfermero', 'Nurse: the patients assigned in the shift and their medication sheet.', '/hospital/my-patients', [
            'hms_my_patients', 'hms_treatments_administer', 'hms_patients_view',
        ]],
        'Head Nurse' => ['Jefe de enfermería', 'Head nurse: assigns nurses (and doctors) to shifts and patients; sees every patient and medication sheet.', '/hospital/shifts', [
            'hms_dashboard', 'hms_shifts', 'hms_my_patients', 'hms_treatments_administer', 'hms_patients_view', 'hms_wards', 'hms_admissions', 'hms_er', 'hms_triage',
        ]],
        'Pharmacy' => ['Farmacia', 'Pharmacy: the medicines in stock, batches and the dispatch of the treatments.', '/hospital/pharmacy', [
            'hms_pharmacy', 'hms_pharmacy_dispense', 'products_view', 'products_add', 'products_edit', 'view_batches', 'batch_view',
            'Purchases_view', 'Purchases_add', 'Suppliers_view',
        ]],
        'Billing' => ['Caja / Facturación', 'Cashier: hospital bills (sales) and payments.', '/hospital/invoices', [
            'hms_billing', 'hms_patients_view', 'Sales_view', 'Customers_view',
        ]],
        'Laboratory' => ['Laboratorio', 'Laboratory: lab orders and results.', '/hospital/lab-orders', [
            'hms_lab', 'hms_patients_view',
        ]],
        'Operating Room' => ['Quirófano', 'Operating room: surgeries, rooms and the patient moves.', '/hospital/surgery', [
            'hms_surgery', 'hms_patients_view', 'hms_treatments', 'hms_treatments_administer',
        ]],
    ];

    /** Apply to a hospital company. Returns [created, updated, removed] role names. */
    public static function apply(int $companyId, ?array $set = null): array
    {
        $now = now();
        // The set of roles: the hospital's, or another type's (a specialist clinic, SpecialistRoles, 2026-10-26).
        $set ??= self::ROLES;
        $roles = DB::table('roles')->where('company_id', $companyId)->whereNull('deleted_at')->get(['id', 'name']);
        $employeeRole = $roles->firstWhere('name', 'Employee');
        $created = $updated = $removed = [];

        // 1. Remove the roles that are neither kept nor hospital roles (their users -> Employee).
        foreach ($roles as $r) {
            if (isset(self::KEEP[$r->name]) || isset($set[$r->name])
                || in_array($r->name, array_merge(CompanyRoles::STANDARD, CompanyRoles::AUTO), true)) {
                continue;
            }
            if ($employeeRole) {
                foreach (DB::table('role_user')->where('role_id', $r->id)->pluck('user_id') as $uid) {
                    if (! DB::table('role_user')->where('user_id', $uid)->where('role_id', $employeeRole->id)->exists()) {
                        DB::table('role_user')->insert(['user_id' => $uid, 'role_id' => $employeeRole->id]);
                    }
                }
                DB::table('users')->where('role_id', $r->id)->update(['role_id' => $employeeRole->id]);
            }
            DB::table('role_user')->where('role_id', $r->id)->delete();
            DB::table('permission_role')->where('role_id', $r->id)->delete();
            DB::table('roles')->where('id', $r->id)->update(['deleted_at' => $now, 'updated_at' => $now]);
            $removed[] = $r->name;
        }

        // 2. Kept roles: Spanish labels.
        foreach (self::KEEP as $name => $label) {
            DB::table('roles')->where('company_id', $companyId)->where('name', $name)->whereNull('deleted_at')->update(['label' => $label, 'updated_at' => $now]);
        }

        // 3. Hospital roles: create or bring permissions / home / label up to date.
        foreach (array_keys($set) as $name) {
            self::syncRole($companyId, $name, $set[$name]) ? $created[] = $name : $updated[] = $name;
        }

        return [$created, $updated, $removed];
    }

    /**
     * One hospital role: created when missing, else label / home / permissions
     * brought up to date (permissions only added). Never removes another role.
     * Returns true when it was created.
     */
    public static function syncRole(int $companyId, string $name, ?array $def = null): bool
    {
        [$label, $description, $home, $perms] = $def ?? self::ROLES[$name];
        $now = now();
        $perms = SettingsAccess::filter($companyId, $perms);
        $permIds = DB::table('permissions')->whereIn('name', $perms)->pluck('id')->all();
        $roleId = DB::table('roles')->where('company_id', $companyId)->where('name', $name)->whereNull('deleted_at')->value('id');
        $created = ! $roleId;
        if ($created) {
            $roleId = DB::table('roles')->insertGetId([
                'company_id' => $companyId, 'name' => $name, 'label' => $label, 'description' => $description,
                'status' => 0, 'home_path' => $home, 'created_at' => $now, 'updated_at' => $now,
            ]);
        } else {
            DB::table('roles')->where('id', $roleId)->update(['label' => $label, 'description' => $description, 'home_path' => $home, 'updated_at' => $now]);
        }
        $have = DB::table('permission_role')->where('role_id', $roleId)->pluck('permission_id')->all();
        $rows = array_map(fn ($pid) => ['permission_id' => $pid, 'role_id' => $roleId], array_values(array_diff($permIds, $have)));
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('permission_role')->insert($chunk);
        }

        return $created;
    }

    /** The company's role id by name (null when missing). */
    public static function roleId(int $companyId, string $name): ?int
    {
        $id = DB::table('roles')->where('company_id', $companyId)->where('name', $name)->whereNull('deleted_at')->value('id');

        return $id ? (int) $id : null;
    }
}
