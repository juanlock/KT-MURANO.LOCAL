<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * System Settings -> Defaults -> "Default — Product": auto-generate a new
 * product's Code as <prefix><next number>, e.g. PPI-042 (per company).
 *
 * Seeds each company's prefix/digits from the pattern its existing codes
 * already follow (e.g. KcolTech's PPI-001..PPI-041 -> 'PPI-', 3 digits);
 * companies with no such pattern get 'P-' and 4 digits.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            if (! Schema::hasColumn('settings', 'product_code_auto')) {
                $table->boolean('product_code_auto')->default(true);
            }
            if (! Schema::hasColumn('settings', 'product_code_prefix')) {
                $table->string('product_code_prefix', 20)->nullable();
            }
            if (! Schema::hasColumn('settings', 'product_code_digits')) {
                $table->unsignedTinyInteger('product_code_digits')->default(4);
            }
        });

        foreach (DB::table('settings')->get(['id', 'company_id', 'product_code_prefix']) as $s) {
            if ($s->product_code_prefix !== null) {
                continue;
            }
            // Most common "<1-6 letters><optional -/_><digits>" shape among
            // this company's codes, if at least 3 codes share it (so one-off
            // test codes like QA-6ab2d798873e3 don't become the prefix).
            $shapes = [];
            foreach (DB::table('products')->where('company_id', $s->company_id)->pluck('code') as $code) {
                if (preg_match('/^([A-Za-z]{1,6}[-_]?)(\d+)$/', (string) $code, $m)) {
                    $key = $m[1].'|'.strlen($m[2]);
                    $shapes[$key] = ($shapes[$key] ?? 0) + 1;
                }
            }
            arsort($shapes);
            [$prefix, $digits] = ($shapes && reset($shapes) >= 3) ? explode('|', array_key_first($shapes)) : ['P-', 4];

            DB::table('settings')->where('id', $s->id)->update([
                'product_code_prefix' => $prefix,
                'product_code_digits' => (int) $digits,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            foreach (['product_code_auto', 'product_code_prefix', 'product_code_digits'] as $column) {
                if (Schema::hasColumn('settings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
