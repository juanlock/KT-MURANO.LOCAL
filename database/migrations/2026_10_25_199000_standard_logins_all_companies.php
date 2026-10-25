<?php

use App\Support\CompanyLogins;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Standard logins for the other companies (user's answers, 2026-10-25):
 *  - every role an admin login uses (Admin-ALL, AdminNE, Manager RG...) gets
 *    "Company logins" (company_logins_view);
 *  - the test / demo companies get everything, like Vitalis (access logins,
 *    default passwords, every login with its standard password, portal on);
 *  - the real companies (KcolTech, Ruba, New Era, Vanclem) only get the access
 *    logins they lack ({slug}-admin / -helpdesk / -info, C0ntr0l123$) -- the
 *    passwords of their staff and customers are never touched.
 * By company name; safe to run twice.
 */
return new class extends Migration
{
    private const DEMOS = ['AquaExpress', 'CargoPack', 'Alo Mundo', 'La Sazon de Lock', 'Urban Cuts', 'Apedido Buffet', 'Quiero ese Inmueble',
        'Colegio Bicentenario', 'Prime', 'My Bodega', 'Turismo Cautivo', 'Guimac Garden', 'Vitalis'];

    private const REAL = ['KcolTech', 'Ruba Garden LandScape', 'NewEraUnisexSalon', 'Vanclem'];

    public function up(): void
    {
        $perm = DB::table('permissions')->where('name', 'company_logins_view')->value('id');
        if ($perm) {
            $roles = DB::table('users as u')->join('role_user as ru', 'ru.user_id', '=', 'u.id')->join('roles as r', 'r.id', '=', 'ru.role_id')
                ->where('u.type', 'admin')->whereNull('u.deleted_at')->whereNull('r.deleted_at')->whereNotIn('r.name', ['Info', 'Helpdesk'])
                ->distinct()->pluck('r.id');
            foreach ($roles as $roleId) {
                if (! DB::table('permission_role')->where('role_id', $roleId)->where('permission_id', $perm)->exists()) {
                    DB::table('permission_role')->insert(['role_id' => $roleId, 'permission_id' => $perm]);
                }
            }
        }

        foreach (DB::table('companies')->whereNull('deleted_at')->whereIn('name', self::DEMOS)->pluck('id') as $cid) {
            CompanyLogins::applyToTestCompany((int) $cid);
        }
        foreach (DB::table('companies')->whereNull('deleted_at')->whereIn('name', self::REAL)->pluck('id') as $cid) {
            CompanyLogins::ensureAccess((int) $cid, false);
        }
    }

    public function down(): void
    {
        // Logins stay.
    }
};
