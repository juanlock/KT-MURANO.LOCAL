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
        // On when any of the Business Modules it belongs to is on (a school has no
        // HRM / Accounting, so no holidays, payroll or regular expenses demo).
        $enabled = collect($module->moduleFlags())->contains(fn ($f) => ($flags[$f] ?? true) !== false);
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
            // SuperAdmin can delete this module's real (test) data for the company.
            'wipeable' => $installed && (bool) $module->wipePlan(),
        ];
    }

    /**
     * Generate the given modules (null = every available one) for a company.
     * Each module runs in its own transaction; a failing module is reported
     * and doesn't stop the others.
     *
     * @return array<string,array> key => {status, created|reason}
     */
    public static function run(int $companyId, int $userId, ?array $keys = null, ?string $payType = null, array $options = []): array
    {
        $ctx = self::context($companyId, $userId, true);
        $ctx->options = $options;
        // Demo staff pay (hours / commission): asked on Demo Data; default by business type.
        $ctx->payType = in_array($payType, ['hourly', 'commission'], true) ? $payType
            : (in_array($ctx->businessType, ['salon', 'barber'], true) ? 'commission' : 'hourly');
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
        self::standardLogins($companyId);

        return $results;
    }

    /**
     * Demos are for testing every role (user's rule, 2026-10-25): the demo's
     * people (staff, patients, students, customers...) sign in with the
     * standard password of their type, and the company has its admin /
     * helpdesk / info access logins (App\Support\CompanyLogins). Only demo
     * users are touched; real logins keep their passwords.
     */
    public static function standardLogins(int $companyId): void
    {
        try {
            \App\Support\CompanyLogins::applyDefaults($companyId);
            $access = \App\Support\CompanyLogins::ensureAccess($companyId);
            $demoUsers = DB::table('demo_records')->where('company_id', $companyId)->where('record_type', 'users')->pluck('record_id')
                ->diff(array_values($access));
            $types = ['employee' => 'employee', 'customer' => 'customer', 'supplier' => 'supplier', 'sales_agent' => 'sales_agent', 'Online_store' => 'online_store'];
            foreach ($types as $type => $key) {
                DB::table('users')->whereIn('id', $demoUsers)->where('type', $type)
                    ->update(['password' => \Illuminate\Support\Facades\Hash::make(\App\Support\CompanyLogins::PASSWORDS[$key]), 'statut' => 1]);
            }
        } catch (\Throwable $e) {
            report($e); // the demo itself is done; logins can be fixed from Users
        }
    }

    /**
     * SuperAdmin only (2026-10-21): delete EVERY row of one module for one
     * company -- real (test) data and demo rows alike -- following the
     * module's wipePlan(), in one transaction. Returns rows deleted.
     */
    public static function wipe(int $companyId, DemoModule $module): int
    {
        $plan = $module->wipePlan();
        if (! $plan || $companyId === \App\Models\Setting::TEMPLATE_COMPANY_ID) {
            return 0;
        }

        return DB::transaction(function () use ($companyId, $module, $plan) {
            $deleted = 0;
            $idsOf = fn (string $t) => Schema::hasTable($t) ? DB::table($t)->where('company_id', $companyId)->pluck('id')->all() : [];
            foreach ($plan as $step) {
                if (isset($step['journal'])) {
                    if (! Schema::hasTable('acc_journal_entries') || ! ($ids = $idsOf($step['of']))) {
                        continue;
                    }
                    foreach (array_chunk($ids, 500) as $chunk) {
                        $je = DB::table('acc_journal_entries')->where('reference_type', $step['journal'])->whereIn('reference_id', $chunk)->pluck('id');
                        if ($je->isNotEmpty()) {
                            DB::table('acc_journal_entry_lines')->whereIn('journal_entry_id', $je)->delete();
                            $deleted += DB::table('acc_journal_entries')->whereIn('id', $je)->delete();
                        }
                    }
                    continue;
                }
                $t = $step['table'];
                if (! Schema::hasTable($t)) {
                    continue;
                }
                if (isset($step['parent'])) {
                    [$col, $parent] = $step['parent'];
                    foreach (array_chunk($idsOf($parent), 500) as $chunk) {
                        $deleted += DB::table($t)->whereIn($col, $chunk)->delete();
                    }
                } else {
                    $deleted += DB::table($t)->where('company_id', $companyId)->delete();
                }
                // Their demo tags go too.
                DB::table('demo_records')->where('company_id', $companyId)->where('record_type', $t)->delete();
            }
            DB::table('demo_records')->where('company_id', $companyId)->where('module', $module->key())->delete();

            return $deleted;
        });
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

            return self::deleteRows($rows);
        });
    }

    /**
     * Remove only some kinds of a company's demo rows (2026-10-20: a company
     * whose Sales / Purchases / Suppliers are off drops their demo documents),
     * with the demo logins of the customers / suppliers removed and the journal
     * entries of the documents removed. Real rows are never touched: only ids
     * listed in demo_records. Returns rows deleted.
     */
    public static function purgeTypes(int $companyId, array $types): int
    {
        return DB::transaction(function () use ($companyId, $types) {
            $rows = DB::table('demo_records')->where('company_id', $companyId)->whereIn('record_type', $types)
                ->orderByDesc('id')->get(['id', 'record_type', 'record_id']);
            // The demo logins of the demo customers / suppliers being removed.
            $userIds = [];
            foreach (['clients', 'providers'] as $t) {
                $ids = $rows->where('record_type', $t)->pluck('record_id')->all();
                if ($ids && Schema::hasColumn($t, 'user_id')) {
                    $userIds = array_merge($userIds, DB::table($t)->whereIn('id', $ids)->whereNotNull('user_id')->pluck('user_id')->all());
                }
            }
            if ($userIds) {
                $rows = $rows->merge(DB::table('demo_records')->where('company_id', $companyId)->where('record_type', 'users')
                    ->whereIn('record_id', $userIds)->get(['id', 'record_type', 'record_id']));
            }

            return self::deleteRows($rows);
        });
    }

    /** Delete these demo_records rows' records (+ links, journal) and the demo_records rows. */
    private static function deleteRows($rows): int
    {
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
                    try {
                        $count += DB::table($r->record_type)->where('id', $r->record_id)->delete();
                    } catch (\Illuminate\Database\QueryException $e) {
                        // A row of a SHARED table (no company_id, e.g. leave_types)
                        // that another company's records still use (FK 1451,
                        // 2026-10-21): it stays; only this company's tag goes.
                        if ((int) ($e->errorInfo[1] ?? 0) !== 1451) {
                            throw $e;
                        }
                    }
                }
            }
            DB::table('demo_records')->whereIn('id', $rows->pluck('id'))->delete();
            // Positions of employees that no longer exist (removed demo employees).
            if (Schema::hasTable('employee_positions')) {
                DB::table('employee_positions')->whereNotIn('employee_id', DB::table('employees')->select('id'))->delete();
            }

            return $count;
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
