<?php

namespace App\Services\DemoData;

use App\Models\Setting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Runs demo modules for one company -- shared by System Settings -> Demo Data
 * and "create company with demo data" (HRM -> Companies).
 */
class DemoRunner
{
    public static function context(int $companyId, int $userId, bool $ensureWarehouse): DemoContext
    {
        $warehouseIds = DB::table('warehouses')->whereNull('deleted_at')->where('company_id', $companyId)
            ->orderBy('id')->pluck('id')->map(fn ($v) => (int) $v)->all();
        // Demo documents go to the company's DEFAULT warehouse (settings.warehouse_id);
        // fall back to its first warehouse. Listed first so warehouseIds[0] is it too.
        $default = (int) (Setting::forCompany($companyId)->warehouse_id ?? 0);
        if (! in_array($default, $warehouseIds, true)) {
            $default = $warehouseIds[0] ?? 0;
        }
        if ($default) {
            $warehouseIds = array_values(array_unique(array_merge([$default], $warehouseIds)));
        }
        $ctx = new DemoContext($companyId, $userId, $warehouseIds, $default ?: null);
        $ctx->businessType = \App\Support\BusinessTypes::of($companyId);
        if (! $warehouseIds && $ensureWarehouse) {
            $ctx->module = 'core';
            $ctx->warehouseIds = [$ctx->insert('warehouses', ['name' => 'Demo Warehouse'])];
            $ctx->defaultWarehouseId = $ctx->warehouseIds[0];
        }

        return $ctx;
    }

    /** settings.module_flags ({key: bool}, null = all on) for this company. */
    public static function moduleFlags(int $companyId): array
    {
        $raw = Setting::forCompany($companyId)->module_flags ?? null;

        return $raw ? (json_decode($raw, true) ?: []) : [];
    }

    public static function status(DemoContext $ctx, DemoModule $module, array $flags): array
    {
        $key = $module->key();
        $enabled = $key === 'core' || ($flags[$key] ?? true) !== false;
        $installed = collect($module->requiredTables())->every(fn ($t) => Schema::hasTable($t));

        $demoCount = DB::table('demo_records')->where('company_id', $ctx->companyId)->where('module', $key)->count();
        $realData = $installed && collect($module->dataTables())->contains(fn ($t) => $ctx->hasRealData($t));

        $reason = ! $installed ? 'Module tables not installed'
            : (! $enabled ? 'Module is switched off'
            : ($demoCount > 0 ? 'Examples already generated'
            : ($realData ? 'Company already has real data here' : null)));

        return [
            'key' => $key,
            'label' => $module->label(),
            'enabled' => $enabled,
            'has_real_data' => $realData,
            'demo_count' => $demoCount,
            'available' => $reason === null,
            'reason' => $reason,
        ];
    }

    /**
     * Generate the given modules (null = every available one) for a company.
     * Each module runs in its own transaction; a failing module is reported
     * and doesn't stop the others.
     *
     * @return array<string,array> key => {status, created|reason}
     */
    public static function run(int $companyId, int $userId, ?array $keys = null): array
    {
        $ctx = self::context($companyId, $userId, true);
        $flags = self::moduleFlags($companyId);

        $results = [];
        foreach (DemoModules::all() as $key => $module) {
            if ($keys !== null && ! in_array($key, $keys, true)) {
                continue;
            }
            $st = self::status($ctx, $module, $flags);
            if (! $st['available']) {
                $results[$key] = ['status' => 'skipped', 'reason' => $st['reason']];
                continue;
            }
            $ctx->module = $key;
            try {
                $created = DB::transaction(fn () => $module->generate($ctx));
                $results[$key] = ['status' => 'created', 'created' => $created];
            } catch (\Throwable $e) {
                report($e);
                $results[$key] = ['status' => 'error', 'reason' => $e->getMessage()];
            }
        }

        return $results;
    }

    /** Remove a company's demo rows (optionally one module). Returns rows deleted. */
    public static function reset(int $companyId, int $userId, ?string $only = null): int
    {
        $ctx = self::context($companyId, $userId, false);

        return DB::transaction(function () use ($ctx, $companyId, $only) {
            foreach (DemoModules::all() as $key => $module) {
                if (! $only || $only === $key) {
                    $ctx->module = $key;
                    $module->purge($ctx);
                }
            }

            $rows = DB::table('demo_records')->where('company_id', $companyId)
                ->when($only, fn ($q) => $q->where('module', $only))
                ->orderByDesc('id')->get(['id', 'record_type', 'record_id']);

            // Demo users (linked to demo customers / suppliers / employees):
            // detach them and drop their role rows first -- clients/employees
            // .user_id and role_user reference users.
            $demoUserIds = $rows->where('record_type', 'users')->pluck('record_id')->all();
            if ($demoUserIds) {
                foreach (['clients', 'providers', 'employees'] as $t) {
                    if (Schema::hasColumn($t, 'user_id')) {
                        DB::table($t)->whereIn('user_id', $demoUserIds)->update(['user_id' => null]);
                    }
                }
                DB::table('role_user')->whereIn('user_id', $demoUserIds)->delete();
                // Their warehouse access rows (user_warehouse.user_id FK).
                if (Schema::hasTable('user_warehouse')) {
                    DB::table('user_warehouse')->whereIn('user_id', $demoUserIds)->delete();
                }
            }

            // Journal entries of the demo documents being removed, even when
            // they weren't tagged (posted later, e.g. by
            // accounting:post-missing) -- else Accounting keeps counting
            // documents that no longer exist.
            $count = self::purgeJournalOf($rows);

            foreach ($rows as $r) {
                if (Schema::hasTable($r->record_type)) {
                    $count += DB::table($r->record_type)->where('id', $r->record_id)->delete();
                }
            }
            DB::table('demo_records')->whereIn('id', $rows->pluck('id'))->delete();
            // Positions of employees that no longer exist (removed demo employees).
            if (Schema::hasTable('employee_positions')) {
                DB::table('employee_positions')->whereNotIn('employee_id', DB::table('employees')->select('id'))->delete();
            }

            return $count;
        });
    }

    /** Document table => acc_journal_entries.reference_type. */
    private const JOURNAL_SOURCES = [
        'sales' => 'sale', 'payment_sales' => 'payment_sale', 'purchases' => 'purchase', 'payment_purchases' => 'payment_purchase',
        'sale_returns' => 'sale_return', 'payment_sale_returns' => 'payment_sale_return',
        'purchase_returns' => 'purchase_return', 'payment_purchase_returns' => 'payment_purchase_return', 'expenses' => 'expense',
    ];

    /** Delete the journal entries (and lines) posted for these demo rows' documents. Returns rows deleted. */
    private static function purgeJournalOf($rows): int
    {
        if (! Schema::hasTable('acc_journal_entries')) {
            return 0;
        }
        $count = 0;
        foreach (self::JOURNAL_SOURCES as $table => $type) {
            $ids = $rows->where('record_type', $table)->pluck('record_id')->all();
            foreach (array_chunk($ids, 500) as $chunk) {
                $entryIds = DB::table('acc_journal_entries')->where('reference_type', $type)->whereIn('reference_id', $chunk)->pluck('id')->all();
                if ($entryIds) {
                    $count += DB::table('acc_journal_entry_lines')->whereIn('journal_entry_id', $entryIds)->delete();
                    $count += DB::table('acc_journal_entries')->whereIn('id', $entryIds)->delete();
                }
            }
        }

        return $count;
    }
}
