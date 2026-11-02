<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * "Update the demo every day" (user's rule, 2026-11-02): companies.demo_daily,
 * switched in Settings -> Demo Data; `demo:daily` (hourly) keeps the hospital,
 * school and transport demos of those companies current. On from the start for
 * the companies that already have demo data in those modules (the hospital ones
 * were refreshed every day already), never for a company in production
 * (is_live). Safe to run twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('companies', 'demo_daily')) {
            Schema::table('companies', function (Blueprint $t) {
                $t->boolean('demo_daily')->default(false);
            });
            $ids = DB::table('demo_records')->whereIn('module', ['hospital', 'school', 'transport'])->distinct()->pluck('company_id');
            DB::table('companies')->whereIn('id', $ids)->where(fn ($q) => $q->whereNull('is_live')->orWhere('is_live', 0))->update(['demo_daily' => 1]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('companies', 'demo_daily')) {
            Schema::table('companies', fn (Blueprint $t) => $t->dropColumn('demo_daily'));
        }
    }
};
