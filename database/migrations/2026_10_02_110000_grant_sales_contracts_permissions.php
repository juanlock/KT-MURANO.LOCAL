<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sales Contracts permissions (new with the 2026-10-02 contracts split):
 * every role that could manage contracts before (it held `contracts`, which
 * covered customer contracts too) gets the five sales_contracts_* ones, so
 * nobody loses access to their customer contracts. Other roles get them from
 * the role editor (Roles > Sales Contracts). A migration, not a manual DB
 * edit, so production gets the same grant on `php artisan migrate`.
 */
return new class extends Migration
{
    private const PERMS = ['sales_contracts_view', 'sales_contracts_add', 'sales_contracts_edit', 'sales_contracts_delete', 'sales_contracts_invoice'];

    public function up(): void
    {
        $contracts = DB::table('permissions')->where('name', 'contracts')->value('id');
        if (! $contracts) {
            return;
        }
        $roleIds = DB::table('permission_role')->where('permission_id', $contracts)->pluck('role_id');
        foreach (self::PERMS as $name) {
            $pid = DB::table('permissions')->where('name', $name)->value('id')
                ?? DB::table('permissions')->insertGetId(['name' => $name, 'label' => $name, 'created_at' => now(), 'updated_at' => now()]);
            foreach ($roleIds as $rid) {
                if (! DB::table('permission_role')->where('role_id', $rid)->where('permission_id', $pid)->exists()) {
                    DB::table('permission_role')->insert(['role_id' => $rid, 'permission_id' => $pid]);
                }
            }
        }
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('name', self::PERMS)->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
    }
};
