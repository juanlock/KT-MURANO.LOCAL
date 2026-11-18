<?php

use App\Support\CompanyLogins;
use App\Support\TranslationKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * "Control de Calidad" (user's rule, 2026-11-18): the complaints are handled by the quality-control role, not by the owner.
 * Its home is the complaints board (/transport/quality) and it opens the forms (forms_view). For now only Turismo Cautivo:
 * the role, Daniel (administracion@transportescautivo.com) moved to it, and the access login turismo-calidad@kcoltech.com
 * (standard access password). Every other company gets it later (CLAUDE.md). Safe to run twice; by name, never by id.
 */
return new class extends Migration
{
    private const ROLE = 'Control de Calidad';

    public function up(): void
    {
        TranslationKeys::ensure('Transport_Quality', ['es' => 'Control de calidad']);
        foreach (['en' => 'Quality control', 'es' => 'Control de calidad'] as $col => $text) {
            DB::table('translations')->whereRaw('`key` = ? COLLATE utf8mb4_bin', ['Transport_Quality'])
                ->where(fn ($q) => $q->whereNull($col)->orWhere($col, '')->orWhere($col, 'Transport_Quality'))
                ->update([$col => $text, 'updated_at' => now()]);
        }
        if (function_exists('forget_db_translations')) {
            forget_db_translations();
        }

        $cid = (int) DB::table('companies')->where('name', 'like', 'Turismo Cautivo%')->whereNull('deleted_at')->value('id');
        if (! $cid) {
            return;
        }
        $roleId = DB::table('roles')->where('company_id', $cid)->where('name', self::ROLE)->whereNull('deleted_at')->value('id');
        if (! $roleId) {
            $roleId = DB::table('roles')->insertGetId([
                'company_id' => $cid, 'name' => self::ROLE, 'label' => self::ROLE,
                'description' => 'Quality control: the complaints board and the forms. Home: Control de calidad.',
                'home_path' => '/transport/quality', 'status' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        foreach (DB::table('permissions')->whereIn('name', ['forms_view'])->pluck('id') as $pid) {
            if (! DB::table('permission_role')->where('role_id', $roleId)->where('permission_id', $pid)->exists()) {
                DB::table('permission_role')->insert(['role_id' => $roleId, 'permission_id' => $pid]);
            }
        }

        // Daniel and the access login carry the role.
        $users = DB::table('users')->where('company_id', $cid)->whereNull('deleted_at')
            ->whereIn('email', ['administracion@transportescautivo.com', 'turismo-calidad@kcoltech.com'])->pluck('id', 'email');
        if (! isset($users['turismo-calidad@kcoltech.com'])) {
            $username = 'turismo-calidad';
            if (DB::table('users')->where('username', $username)->exists()) {
                $username .= '-'.$cid;
            }
            $users['turismo-calidad@kcoltech.com'] = DB::table('users')->insertGetId([
                'company_id' => $cid, 'firstname' => 'Calidad', 'lastname' => 'Turismo Cautivo', 'username' => $username,
                'email' => 'turismo-calidad@kcoltech.com', 'password' => Hash::make(CompanyLogins::ACCESS_PASSWORD), 'phone' => '',
                'role_id' => $roleId, 'type' => 'admin', 'statut' => 1, 'is_all_warehouses' => 1, 'record_view' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        foreach ($users as $uid) {
            DB::table('role_user')->where('user_id', $uid)->delete();
            DB::table('role_user')->insert(['user_id' => $uid, 'role_id' => $roleId]);
            DB::table('users')->where('id', $uid)->update(['role_id' => $roleId, 'statut' => 1, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
    }
};
