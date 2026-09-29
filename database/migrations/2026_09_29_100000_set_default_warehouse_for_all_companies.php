<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Every company needs a DEFAULT warehouse (settings.warehouse_id): new
 * documents and demo data go there. Companies whose default is empty, deleted
 * or another company's warehouse get their own first live warehouse. The
 * template company (no warehouses) is left alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('settings')->whereNull('deleted_at')->whereNotNull('company_id')->get(['id', 'company_id', 'warehouse_id']) as $s) {
            $valid = $s->warehouse_id && DB::table('warehouses')->where('id', $s->warehouse_id)
                ->where('company_id', $s->company_id)->whereNull('deleted_at')->exists();
            if ($valid) {
                continue;
            }
            $first = DB::table('warehouses')->where('company_id', $s->company_id)->whereNull('deleted_at')->orderBy('id')->value('id');
            if ($first) {
                DB::table('settings')->where('id', $s->id)->update(['warehouse_id' => $first]);
            }
        }
    }

    public function down(): void
    {
        // Data fix only.
    }
};
