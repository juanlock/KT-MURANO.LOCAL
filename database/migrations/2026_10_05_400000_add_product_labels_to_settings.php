<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-company names for the product "Group Name" / "Name" fields (System
 * Settings -> Defaults -> Products). The SPA renames those labels and their
 * help texts on every form (i18n overlay, resources/src/i18n/index.js).
 * Empty = the standard "Group Name" / "Name".
 *   Ruba (company 2):   Main Service / Service
 *   NewEra (company 3): Service / Area
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            if (! Schema::hasColumn('settings', 'product_group_label')) {
                $table->string('product_group_label', 60)->nullable();
            }
            if (! Schema::hasColumn('settings', 'product_name_label')) {
                $table->string('product_name_label', 60)->nullable();
            }
        });
        foreach ([2 => ['Main Service', 'Service'], 3 => ['Service', 'Area']] as $cid => [$group, $name]) {
            DB::table('settings')->where('company_id', $cid)->whereNull('product_group_label')
                ->update(['product_group_label' => $group, 'product_name_label' => $name]);
        }
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            foreach (['product_group_label', 'product_name_label'] as $c) {
                if (Schema::hasColumn('settings', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
    }
};
