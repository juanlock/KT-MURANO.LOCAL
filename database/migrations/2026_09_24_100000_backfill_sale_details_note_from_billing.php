<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One-time data backfill (not a schema change): dbmurano's sale_details.note
 * column (added in 2026_09_23_290000_add_note_to_sale_details_table.php) was
 * never populated for the ~8.2k sale_details rows bulk-imported from
 * KT-BILLING.LOCAL's dbbilling before that column existed on this side.
 *
 * dbbilling is read-only (CLAUDE.md hard rule) -- this only reads from it.
 * Both databases live on the same MySQL server under the same DB user, so a
 * cross-database UPDATE...JOIN is used directly rather than a PHP loop.
 *
 * Correspondence confirmed 1:1 with zero mismatches across all 8242
 * overlapping rows: dbmurano.sale_details.id === dbbilling.sale_details.id
 * (same for sale_id/product_id on every matched row). Murano's extra rows
 * (ids with no billing counterpart, created after the migration) are
 * untouched by the WHERE clause. Safe to re-run: matches 0 rows once notes
 * are filled in.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('sale_details', 'note')) {
            return;
        }

        DB::statement(<<<'SQL'
            UPDATE dbmurano.sale_details m
            INNER JOIN dbbilling.sale_details b ON m.id = b.id
            SET m.note = b.note
            WHERE b.note IS NOT NULL
              AND b.note != ''
              AND m.note IS NULL
        SQL);
    }

    public function down(): void
    {
        // Data backfill only -- not reversible (and not destructive to leave as-is).
    }
};
