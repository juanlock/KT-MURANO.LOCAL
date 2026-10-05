<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Service Jobs multicompany: technicians and checklist categories / items get
 * a company_id (they were shared by every company). Existing rows go to the
 * company that owns them (demo record), else the company whose jobs use them.
 */
return new class extends Migration
{
    private const TABLES = ['service_technicians', 'service_checklist_categories', 'service_checklist_items'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasColumn($table, 'company_id')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->unsignedInteger('company_id')->nullable()->index()->after('id');
                });
            }
        }

        // Company of a job: its location's, else its customer's.
        $jobCompany = fn ($q) => $q->leftJoin('warehouses as w', 'w.id', '=', 'j.warehouse_id')
            ->leftJoin('clients as c', 'c.id', '=', 'j.client_id')
            ->selectRaw('COALESCE(w.company_id, c.company_id) as cid');

        foreach (DB::table('service_technicians')->whereNull('company_id')->pluck('id') as $id) {
            $cid = $this->demoOwner('service_technicians', $id)
                ?: $jobCompany(DB::table('service_jobs as j')->where('j.technician_id', $id))->value('cid');
            if ($cid) {
                DB::table('service_technicians')->where('id', $id)->update(['company_id' => $cid]);
            }
        }

        foreach (DB::table('service_checklist_categories')->whereNull('company_id')->pluck('id') as $id) {
            $cid = $this->demoOwner('service_checklist_categories', $id)
                ?: $jobCompany(DB::table('service_job_checklist_items as ji')->join('service_jobs as j', 'j.id', '=', 'ji.service_job_id')->where('ji.category_id', $id))->value('cid');
            if ($cid) {
                DB::table('service_checklist_categories')->where('id', $id)->update(['company_id' => $cid]);
            }
        }

        foreach (DB::table('service_checklist_items')->whereNull('company_id')->get(['id', 'category_id']) as $item) {
            $cid = $this->demoOwner('service_checklist_items', $item->id)
                ?: DB::table('service_checklist_categories')->where('id', $item->category_id)->value('company_id');
            if ($cid) {
                DB::table('service_checklist_items')->where('id', $item->id)->update(['company_id' => $cid]);
            }
        }
    }

    private function demoOwner(string $table, int $id): ?int
    {
        $cid = DB::table('demo_records')->where('record_type', $table)->where('record_id', $id)->value('company_id');

        return $cid ? (int) $cid : null;
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (Schema::hasColumn($table, 'company_id')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->dropIndex(['company_id']);
                    $t->dropColumn('company_id');
                });
            }
        }
    }
};
