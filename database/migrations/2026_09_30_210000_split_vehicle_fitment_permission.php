<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Vehicle Fitment gets its own permission. Its menu item used to be gated by
 * products_view, so in the role editor "All Products & Services" and
 * "Vehicle Fitment" were the same switch. Every role that has products_view
 * also gets vehicle_fitment, so today's access is unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        $newId = DB::table('permissions')->where('name', 'vehicle_fitment')->value('id')
            ?? DB::table('permissions')->insertGetId([
                'name' => 'vehicle_fitment', 'label' => 'vehicle_fitment', 'created_at' => now(), 'updated_at' => now(),
            ]);
        $fromId = DB::table('permissions')->where('name', 'products_view')->value('id');
        if (! $fromId) {
            return;
        }
        $roleIds = DB::table('permission_role')->where('permission_id', $fromId)->pluck('role_id');
        $has = DB::table('permission_role')->where('permission_id', $newId)->pluck('role_id')->all();
        $rows = $roleIds->diff($has)->map(fn ($rid) => ['permission_id' => $newId, 'role_id' => $rid])->values()->all();
        if ($rows) {
            DB::table('permission_role')->insert($rows);
        }
    }

    public function down(): void
    {
        $id = DB::table('permissions')->where('name', 'vehicle_fitment')->value('id');
        if ($id) {
            DB::table('permission_role')->where('permission_id', $id)->delete();
            DB::table('permissions')->where('id', $id)->delete();
        }
    }
};
