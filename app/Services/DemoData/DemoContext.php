<?php

namespace App\Services\DemoData;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Everything a demo module needs to create example rows for ONE company.
 *
 * insert() is the only way modules should write: it adds company_id (when the
 * table has that column) and created_at/updated_at (when present), and tags
 * the new row in demo_records (company, module, table, id). Reset deletes a
 * company's tagged rows newest-first, so child rows (details, payments,
 * lines) inserted after their parents are removed before them -- modules must
 * insert parents first and tag EVERY row they create, children included.
 * Rows of tables without an auto-increment `id` (pivots) can't be tagged:
 * avoid them, or remove them in the module's purge() hook.
 */
class DemoContext
{
    public string $module = 'core';

    private static array $columnCache = [];

    public function __construct(
        public int $companyId,
        public int $userId,
        /** @var int[] this company's live warehouses (at least one), DEFAULT warehouse first */
        public array $warehouseIds,
        /** the company's default warehouse (settings.warehouse_id) -- where demo documents go */
        public ?int $defaultWarehouseId = null,
    ) {
    }

    /** The warehouse demo documents use: the company's default warehouse. */
    public function warehouse(): int
    {
        return (int) ($this->defaultWarehouseId ?: ($this->warehouseIds[0] ?? 0));
    }

    /** Insert one row for this company, tag it as demo, return its id. */
    public function insert(string $table, array $row): int
    {
        if ($this->hasColumn($table, 'company_id') && ! array_key_exists('company_id', $row)) {
            $row['company_id'] = $this->companyId;
        }
        $now = now();
        if ($this->hasColumn($table, 'created_at') && ! array_key_exists('created_at', $row)) {
            $row['created_at'] = $now;
        }
        if ($this->hasColumn($table, 'updated_at') && ! array_key_exists('updated_at', $row)) {
            $row['updated_at'] = $now;
        }
        // Drop keys the table doesn't have (schemas differ between installs).
        $row = array_intersect_key($row, array_flip($this->columns($table)));

        $id = (int) DB::table($table)->insertGetId($row);
        $this->tag($table, $id);
        $this->linkUser($table, $id, $row);

        return $id;
    }

    /**
     * Demo customers / suppliers / employees get their linked user like real
     * ones do (type customer / supplier / employee + that company role, via
     * LinkedUserService). The user is tagged as demo too, so Remove demo data
     * deletes it (DemoRunner::reset detaches it first).
     */
    private function linkUser(string $table, int $id, array $row): void
    {
        $map = ['clients' => ['customer', 'Customer'], 'providers' => ['supplier', 'Supplier'], 'employees' => ['employee', 'Employee']];
        if (! isset($map[$table]) || ! empty($row['user_id']) || ! $this->hasColumn($table, 'user_id')) {
            return;
        }
        [$type, $roleName] = $map[$table];
        $name = trim((string) ($row['name'] ?? (($row['firstname'] ?? '').' '.($row['lastname'] ?? '')))) ?: ucfirst($type);
        $userId = \App\Services\LinkedUserService::provision($this->companyId, $type, $roleName, $name, $row['email'] ?? null, $row['phone'] ?? null);
        DB::table($table)->where('id', $id)->update(['user_id' => $userId]);
        $this->tag('users', $userId);
    }

    public function tag(string $table, int $id): void
    {
        DB::table('demo_records')->insert([
            'company_id' => $this->companyId,
            'module' => $this->module,
            'record_type' => $table,
            'record_id' => $id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** Ids of this company's demo rows in $table (optionally any module). */
    public function demoIds(string $table): array
    {
        return DB::table('demo_records')
            ->where('company_id', $this->companyId)
            ->where('record_type', $table)
            ->pluck('record_id')->map(fn ($v) => (int) $v)->all();
    }

    /**
     * Does this company already have REAL (non-demo) rows in $table?
     * Company-scoped when the table has company_id; soft-deleted rows ignored.
     */
    public function hasRealData(string $table): bool
    {
        if (! Schema::hasTable($table)) {
            return false;
        }
        $q = DB::table($table);
        if ($this->hasColumn($table, 'company_id')) {
            $q->where('company_id', $this->companyId);
        }
        if ($this->hasColumn($table, 'deleted_at')) {
            $q->whereNull('deleted_at');
        }
        $demo = $this->demoIds($table);
        if ($demo) {
            $q->whereNotIn('id', $demo);
        }

        return $q->exists();
    }

    public function hasColumn(string $table, string $column): bool
    {
        return in_array($column, $this->columns($table), true);
    }

    public function columns(string $table): array
    {
        return self::$columnCache[$table] ??= Schema::hasTable($table) ? Schema::getColumnListing($table) : [];
    }

    /** A random moment in the last $days days, business hours. */
    public function randomDate(int $days = 90): Carbon
    {
        return Carbon::now()->subDays(random_int(0, max(0, $days - 1)))
            ->setTime(random_int(8, 19), random_int(0, 59), random_int(0, 59));
    }

    public function pick(array $items)
    {
        return $items[array_rand($items)];
    }

    public const FIRST_NAMES = ['James', 'Sarah', 'Mohamed', 'Fatima', 'David', 'Aisha', 'Carlos', 'Nadia', 'Kevin', 'Leila', 'Omar', 'Julia', 'Youssef', 'Emma', 'Karim'];
    public const LAST_NAMES = ['Smith', 'Johnson', 'Alami', 'Benali', 'Garcia', 'Martin', 'Idrissi', 'Brown', 'Tazi', 'Lee', 'Haddad', 'Wilson', 'Mansouri', 'Clark', 'Amrani'];

    public function personName(): array
    {
        return [$this->pick(self::FIRST_NAMES), $this->pick(self::LAST_NAMES)];
    }
}
