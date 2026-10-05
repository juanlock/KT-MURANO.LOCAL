<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * sales_contracts.invoice_note: the note on each generated invoice (the
 * sale's own Note), a template filled per invoice like the line notes
 * ("Service made on {{weekday}}, {{date}}"). Empty = the old automatic
 * "Sales contract SC-... - subject (period)" text.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('sales_contracts', 'invoice_note')) {
            Schema::table('sales_contracts', function (Blueprint $table) {
                $table->string('invoice_note', 1000)->nullable()->after('description');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('sales_contracts', 'invoice_note')) {
            Schema::table('sales_contracts', function (Blueprint $table) {
                $table->dropColumn('invoice_note');
            });
        }
    }
};
