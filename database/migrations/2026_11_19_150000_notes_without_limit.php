<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Notes are never cut (user's rule, 2026-11-19: "en todo Murano, notes de sales, quota y demás no debe cortar el contenido"). The
 * production MySQL runs without strict mode, so a longer text was silently cut at the column's length (sale_details.note at 250:
 * KcolTech's PP-0529 / PP-0530 lines). Every document / customer note that was a VARCHAR becomes TEXT. Safe to run twice.
 */
return new class extends Migration
{
    private const COLUMNS = [
        'sale_details' => 'note',
        'sales_contract_items' => 'note',
        'sales_contracts' => 'invoice_note',
        'clients' => 'notes',
        'expenses' => 'details',
        'pos_settings' => 'note_customer',
        'products' => 'preorder_note',
        'portfolio_catalog_orders' => 'notes',
    ];

    public function up(): void
    {
        foreach (self::COLUMNS as $table => $column) {
            if (! Schema::hasColumn($table, $column)) {
                continue;
            }
            $type = DB::selectOne('SELECT DATA_TYPE t FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$table, $column])->t ?? '';
            if (in_array($type, ['varchar', 'char', 'tinytext'], true)) {
                DB::statement("ALTER TABLE `$table` MODIFY `$column` TEXT NULL");
            }
        }
    }

    public function down(): void
    {
        // Never shorten a note again (it would cut what was written).
    }
};
