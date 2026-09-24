<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from KT-BILLING.LOCAL's sale_details.note (read-only source --
 * this is Murano's own copy of the column, not a shared table). A per-line
 * free-text note, distinct from sales.notes (the whole-document note).
 * Billing's own column is VARCHAR(250) NULLABLE; its sibling
 * quotation_details.note had to be patched nullable after the fact when it
 * was created NOT NULL with no default -- starting this one nullable from
 * day one avoids that same insert failure.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('sale_details', 'note')) {
            return;
        }
        Schema::table('sale_details', function (Blueprint $table) {
            $table->string('note', 250)->nullable()->after('imei_number');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('sale_details', 'note')) {
            Schema::table('sale_details', function (Blueprint $table) {
                $table->dropColumn('note');
            });
        }
    }
};
