<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Server-side sort of a list (?SortField=&SortType=), safely (2026-10-17).
 *
 * The lists' Customer / Supplier / Warehouse / Category ... columns are names
 * from another table (client_name, warehouse_name ...): sorting by them used
 * to send that name straight to ORDER BY -> "Unknown column 'client_name'"
 * (Sales with the Unpaid filter, Purchases, Quotations, Expenses ...).
 *
 *   ListSort::apply($query, 'sales', $request->SortField, $request->SortType, [
 *       'client_name' => ['clients', 'client_id'],             // clients.name
 *       'account_name' => ['accounts', 'account_id', 'account_name'],
 *       'customer_name' => fn () => DB::table(...)->select(...)->whereColumn(...), // further away
 *   ], 'date');
 *
 * - a key of $names: ORDER BY that table's name column (subquery on the
 *   foreign key), newest row first within the same name;
 * - a real column of $table: ORDER BY it;
 * - anything else: $default, newest first.
 */
class ListSort
{
    private static array $columns = [];

    public static function apply($query, string $table, ?string $field, ?string $dir, array $names = [], string $default = 'id')
    {
        $dir = strtolower((string) $dir) === 'asc' ? 'asc' : 'desc';
        $field = (string) $field;

        // a ready sub-query for names further away (e.g. a shipment's customer, through its sale)
        if ($field !== '' && isset($names[$field]) && $names[$field] instanceof \Closure) {
            return $query->orderBy($names[$field](), $dir)->orderBy("$table.id", 'desc');
        }

        if ($field !== '' && isset($names[$field])) {
            [$other, $fk, $col] = $names[$field] + [2 => 'name'];

            return $query->orderBy(
                DB::table($other)->select($col)->whereColumn("$other.id", "$table.$fk")->limit(1),
                $dir
            )->orderBy("$table.id", 'desc');
        }

        if ($field !== '' && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $field) && in_array($field, self::columns($table), true)) {
            return $query->orderBy("$table.$field", $dir);
        }

        return $query->orderBy("$table.$default", 'desc');
    }

    private static function columns(string $table): array
    {
        return self::$columns[$table] ??= Schema::getColumnListing($table);
    }
}
