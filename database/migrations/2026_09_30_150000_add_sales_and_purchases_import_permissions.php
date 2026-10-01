<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Import Sales / Import Purchases get their own permissions (they used to be
 * gated by Sales_add / Purchases_add, so in the role editor turning on
 * "Create Sale" also turned on "Import Sales" -- the same switch).
 *
 * To keep today's access unchanged, every role that has the create
 * permission also gets the matching import permission; from then on they
 * can be granted separately.
 */
return new class extends Migration
{
    // new permission => the permission it is split from
    private const SPLIT = [
        'sales_import' => 'Sales_add',
        'purchases_import' => 'Purchases_add',
    ];

    public function up(): void
    {
        foreach (self::SPLIT as $new => $from) {
            $newId = DB::table('permissions')->where('name', $new)->value('id')
                ?? DB::table('permissions')->insertGetId([
                    'name' => $new, 'label' => $new, 'created_at' => now(), 'updated_at' => now(),
                ]);

            $fromId = DB::table('permissions')->where('name', $from)->value('id');
            if (! $fromId) {
                continue;
            }
            $roleIds = DB::table('permission_role')->where('permission_id', $fromId)->pluck('role_id');
            $has = DB::table('permission_role')->where('permission_id', $newId)->pluck('role_id')->all();
            $rows = $roleIds->diff($has)->map(fn ($rid) => ['permission_id' => $newId, 'role_id' => $rid])->values()->all();
            if ($rows) {
                DB::table('permission_role')->insert($rows);
            }
        }
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('name', array_keys(self::SPLIT))->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};
