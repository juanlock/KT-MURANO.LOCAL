<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Database\Migrations\Migration;

/**
 * Service menu split (config/menu.js): Today's Jobs, Service Jobs
 * (work at the customer), Device Repairs (quotes / pickup), All Jobs,
 * Maintenance History, Setup (technicians, checklists) -- then the three
 * Service reports companies had moved here. Every company's saved sidebar
 * tree (menu_positions, nested) is rewritten for the Service section so the
 * new entries show in this order; a node a company had hidden stays hidden.
 * Node keys follow resources/src/lib/menuOrder.js nodeId(): leaves = their
 * /app path, sub-groups = "group:<label>".
 */
return new class extends Migration
{
    /** [item_key, parent_key, sort_order] */
    private const TREE = [
        ['/app/service/jobs?day=today', 'service', 0],
        ['group:Service_Jobs_Group', 'service', 1],
        ['/app/service/jobs?kind=service', 'group:Service_Jobs_Group', 0],
        ['/app/service/jobs?kind=service&payment_status=unpaid', 'group:Service_Jobs_Group', 1],
        ['group:Device_Repairs', 'service', 2],
        ['/app/service/jobs?kind=device', 'group:Device_Repairs', 0],
        ['/app/service/jobs?kind=device&status=quoted', 'group:Device_Repairs', 1],
        ['/app/service/jobs?kind=device&status=ready', 'group:Device_Repairs', 2],
        ['/app/service/jobs?kind=device&payment_status=unpaid', 'group:Device_Repairs', 3],
        ['/app/service/jobs', 'service', 3],
        ['/app/service/history', 'service', 4],
        ['group:Service_Setup', 'service', 5],
        ['/app/service/technicians', 'group:Service_Setup', 0],
        ['/app/service/checklist-categories', 'group:Service_Setup', 1],
        ['/app/service/checklists', 'group:Service_Setup', 2],
        ['/app/reports/service_jobs_report', 'service', 6],
        ['/app/reports/checklist_completion_report', 'service', 7],
        ['/app/reports/customer_maintenance_history_report', 'service', 8],
    ];

    /** Old Service entries that no longer exist in the menu. */
    private const GONE = ['/app/service/jobs?status=quoted', '/app/service/jobs?status=ready', '/app/service/jobs?payment_status=unpaid'];

    public function up(): void
    {
        $companies = DB::table('menu_positions')->whereNotNull('parent_key')->distinct()->pluck('company_id');
        $keys = array_column(self::TREE, 0);
        $now = now();
        foreach ($companies as $companyId) {
            $old = DB::table('menu_positions')->where('company_id', $companyId)->whereIn('item_key', array_merge($keys, self::GONE))->get()->keyBy('item_key');
            // A report a company moved elsewhere (not under Service) stays where it is.
            $tree = array_filter(self::TREE, fn ($n) => ! str_starts_with($n[0], '/app/reports/')
                || ! isset($old[$n[0]]) || $old[$n[0]]->parent_key === 'service');

            DB::table('menu_positions')->where('company_id', $companyId)
                ->whereIn('item_key', array_merge(array_column($tree, 0), self::GONE))->delete();
            foreach ($tree as [$key, $parent, $sort]) {
                DB::table('menu_positions')->insert([
                    'company_id' => $companyId, 'item_key' => $key, 'parent_key' => $parent,
                    'label' => $old[$key]->label ?? null, 'icon' => $old[$key]->icon ?? null, 'route' => null,
                    'external' => 0, 'is_custom' => 0, 'template' => null, 'dynamic_table' => null,
                    'hidden' => (int) ($old[$key]->hidden ?? 0), 'location' => $old[$key]->location ?? 'sidebar', 'sort_order' => $sort,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        // Not restored: the old flat Service entries no longer exist in config/menu.js.
    }
};
