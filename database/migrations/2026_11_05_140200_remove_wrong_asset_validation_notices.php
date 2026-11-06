<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * assets:check-validation-due told every user with the assets permission, of every company, about every asset. Removes the
 * bell notices that came from it: those about a demo asset, and those sent to a user of another company. A real notice to
 * the asset's own company stays. Safe to run twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('notifications')) {
            return;
        }
        $demo = DB::table('demo_records')->where('record_type', 'assets')->pluck('record_id')->map(fn ($id) => (int) $id)->flip();
        $assetCompany = DB::table('assets as a')->join('warehouses as w', 'w.id', '=', 'a.warehouse_id')->pluck('w.company_id', 'a.id');
        $userCompany = DB::table('users')->pluck('company_id', 'id');

        $remove = [];
        foreach (DB::table('notifications')->where('type', 'App\\Notifications\\AssetValidationDueNotification')->get(['id', 'notifiable_id', 'data']) as $n) {
            $assetId = (int) (json_decode($n->data, true)['asset_id'] ?? 0);
            $own = isset($assetCompany[$assetId]) && (int) $assetCompany[$assetId] === (int) ($userCompany[$n->notifiable_id] ?? 0);
            if (isset($demo[$assetId]) || ! $own) {
                $remove[] = $n->id;
            }
        }
        foreach (array_chunk($remove, 200) as $ids) {
            DB::table('notifications')->whereIn('id', $ids)->delete();
        }
    }

    public function down(): void
    {
    }
};
