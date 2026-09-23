<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Roles were global -- one shared set of 5 rows (Owner/Admin/Admin-ALL/
 * Employee/Customer) used by every company's users. This was a real
 * cross-tenant leak, not just cosmetic: role id 3 ("Admin-ALL", labeled
 * "Admin-RG") was actually in live use by BOTH company 1 and company 2's
 * real users at once -- editing its permissions for one company silently
 * changed them for the other, and any admin could see/edit/delete a role
 * belonging to a different company.
 *
 * Fix: every company gets its own copy of the 5 base roles (same
 * name/label/description/permissions to start), and every existing user's
 * role_id is repointed to the copy in their OWN company_id. The original 5
 * rows become company 1's copies in place (cheapest diff: company 1's own
 * 2 users already point at them, nothing to repoint there); companies
 * 2/3/4/999 each get 5 new rows with fresh ids.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->integer('company_id')->nullable()->after('id');
        });

        // Company 1 keeps the original 5 rows.
        DB::table('roles')->update(['company_id' => 1]);

        $originalRoles = DB::table('roles')->where('company_id', 1)->get();
        $originalPermissions = DB::table('permission_role')->get()->groupBy('role_id');

        $otherCompanyIds = DB::table('companies')->where('id', '!=', 1)->pluck('id');
        $now = now();

        foreach ($otherCompanyIds as $companyId) {
            foreach ($originalRoles as $role) {
                $newId = DB::table('roles')->insertGetId([
                    'company_id' => $companyId,
                    'name' => $role->name,
                    'label' => $role->label,
                    'description' => $role->description,
                    'status' => $role->status,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                // Copy this role's permission set onto the new per-company row.
                $perms = $originalPermissions->get($role->id, collect());
                foreach ($perms as $p) {
                    DB::table('permission_role')->insert([
                        'permission_id' => $p->permission_id,
                        'role_id' => $newId,
                    ]);
                }

                // Repoint every user in THIS company who was pointing at the
                // old shared role (by name) to their own company's copy.
                DB::table('users')
                    ->where('company_id', $companyId)
                    ->where('role_id', $role->id)
                    ->update(['role_id' => $newId]);
            }
        }

        Schema::table('roles', function (Blueprint $table) {
            $table->integer('company_id')->nullable(false)->default(1)->change();
            $table->foreign('company_id')->references('id')->on('companies');
        });
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
            $table->dropColumn('company_id');
        });
    }
};
