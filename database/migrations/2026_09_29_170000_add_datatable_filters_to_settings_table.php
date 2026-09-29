<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Customize Filters" -- the same idea as datatable_columns
 * (2026_09_27_170000_add_datatable_company_settings_and_permission.php) but
 * for the filter row above a list (Group Name / Product Name / Category /
 * etc. on Products, Customer / Status / etc. on Sales, Supplier / Status /
 * etc. on Purchases) instead of the table's own displayed columns. Some
 * companies don't need every filter visible, and want to reorder them.
 *
 * settings.datatable_filters: per-table filter customization set by an
 * admin ({tableKey: [{key, hidden}, ...] in display order}) -- same shape
 * as datatable_columns minus title/width (a filter has neither).
 *
 * Deliberately reuses the existing `datatable_customize` permission rather
 * than adding a new one -- both are "customize this list's layout" and an
 * admin who may do one should reasonably be able to do the other.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            if (! Schema::hasColumn('settings', 'datatable_filters')) {
                $table->longText('datatable_filters')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            if (Schema::hasColumn('settings', 'datatable_filters')) {
                $table->dropColumn('datatable_filters');
            }
        });
    }
};
