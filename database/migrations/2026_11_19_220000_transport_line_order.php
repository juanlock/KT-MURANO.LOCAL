<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Turismo Cautivo runs ONE line, Lima -> Trujillo -> Chepén -> Chiclayo (user's rule, 2026-11-19): every list, filter, button,
 * table and chart by agency shows them in that order (or reversed), never alphabetically. transport_locations.line_order:
 * an agency's place on the line; a terminal takes its agency's (hub's) number so it sits with it. By name, safe to run twice.
 */
return new class extends Migration
{
    private const LINE = ['LIMA' => 1, 'TRUJILLO' => 2, 'CHEPEN' => 3, 'CHEPÉN' => 3, 'CHICLAYO' => 4];

    public function up(): void
    {
        if (! Schema::hasTable('transport_locations')) {
            return;
        }
        if (! Schema::hasColumn('transport_locations', 'line_order')) {
            Schema::table('transport_locations', fn (Blueprint $t) => $t->unsignedSmallInteger('line_order')->nullable()->after('kind'));
        }
        $company = DB::table('companies')->where('name', 'Turismo Cautivo')->value('id');
        if (! $company) {
            return;
        }
        foreach (DB::table('transport_locations')->where('company_id', $company)->where('kind', 'agency')->get(['id', 'name']) as $a) {
            $n = self::LINE[mb_strtoupper(trim($a->name))] ?? null;
            if ($n) {
                DB::table('transport_locations')->where('id', $a->id)->update(['line_order' => $n]);
                DB::table('transport_locations')->where('hub_id', $a->id)->update(['line_order' => $n]);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('transport_locations') && Schema::hasColumn('transport_locations', 'line_order')) {
            Schema::table('transport_locations', fn (Blueprint $t) => $t->dropColumn('line_order'));
        }
    }
};
