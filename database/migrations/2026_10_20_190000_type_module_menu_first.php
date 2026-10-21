<?php

use App\Support\BusinessTypes;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Hospital / school companies (user's rule, 2026-10-20): their module's menu
 * (Hospital Management / School Management) goes first in the side menu, and
 * they open on that module's dashboard (BusinessTypes::HOME, by code). New
 * companies get it on create; this does the existing ones (by business type,
 * not id -- today Vitalis and Colegio Bicentenario). Safe to run twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('companies', 'business_type') || ! Schema::hasTable('menu_positions')) {
            return;
        }
        $ids = DB::table('companies')->whereIn('business_type', array_keys(BusinessTypes::MENU_FIRST))->whereNull('deleted_at')->pluck('id');
        foreach ($ids as $cid) {
            DB::transaction(fn () => BusinessTypes::promoteMenu((int) $cid));
        }
    }

    public function down(): void
    {
        // Order stays (Manage Menu -> Default order puts AdminSystem's back).
    }
};
