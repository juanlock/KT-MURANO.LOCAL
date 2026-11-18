<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Call center demo lock (user's rule, 2026-11-18): Alo Mundo's demo holds real leads from the client's Excel, so its leads
 * list shows at most 6 (always the same), can't be copied and shows the licence notice. The SuperAdmin is never limited.
 * settings.cc_demo_lead_limit: 0 / null = off. Safe to run twice; the company is found by name.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('settings', 'cc_demo_lead_limit')) {
            Schema::table('settings', fn (Blueprint $t) => $t->unsignedSmallInteger('cc_demo_lead_limit')->nullable());
        }
        $cid = DB::table('companies')->where('name', 'Alo Mundo')->whereNull('deleted_at')->value('id');
        if ($cid) {
            DB::table('settings')->where('company_id', $cid)->update(['cc_demo_lead_limit' => 6]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('settings', 'cc_demo_lead_limit')) {
            Schema::table('settings', fn (Blueprint $t) => $t->dropColumn('cc_demo_lead_limit'));
        }
    }
};
